<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GovernanceController extends Controller
{
    private const PURGEABLE_CATEGORIES = ['notifications', 'exports', 'expired_upload_sessions'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('system_administrator', 'hq_recruitment_administrator', 'prisons_council_secretariat', 'auditor'), 403);

        return response()->json([
            'policies' => DB::table('retention_policies')->orderBy('record_category')->get(),
            'legal_holds' => DB::table('legal_holds')->orderByDesc('placed_at')->paginate(100),
            'purge_requests' => DB::table('purge_requests')->orderByDesc('created_at')->paginate(100),
            'supported_purge_categories' => self::PURGEABLE_CATEGORIES,
        ]);
    }

    public function storePolicy(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat', 'system_administrator'), 403);
        $data = $request->validate([
            'recruitment_campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'],
            'record_category' => ['required', 'string', 'max:80'],
            'retention_days' => ['required', 'integer', 'between:1,36500'],
            'disposition' => ['required', 'in:archive,review_for_purge'],
            'legal_basis_reference' => ['required', 'string', 'max:255'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $key = ['recruitment_campaign_id' => $data['recruitment_campaign_id'] ?? null, 'record_category' => $data['record_category']];
        $id = DB::table('retention_policies')->where($key)->value('id') ?? (string) Str::ulid();
        DB::table('retention_policies')->updateOrInsert($key, [
            'id' => $id,
            'retention_days' => $data['retention_days'],
            'disposition' => $data['disposition'],
            'legal_basis_reference' => $data['legal_basis_reference'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $policy = DB::table('retention_policies')->where('id', $id)->first();
        $audit->record('retention.policy_approved', 'retention_policy', $id, after: (array) $policy, actor: $request->user(), reason: $data['legal_basis_reference'], approvalReference: $data['approval_reference']);

        return response()->json(['policy' => $policy], 201);
    }

    public function placeHold(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat', 'auditor'), 403);
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:100'],
            'entity_id' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $existing = DB::table('legal_holds')->where('entity_type', $data['entity_type'])->where('entity_id', $data['entity_id'])->whereNull('released_at')->first();
        if ($existing !== null) {
            return response()->json(['legal_hold' => $existing, 'duplicate' => true]);
        }
        $id = (string) Str::ulid();
        DB::table('legal_holds')->insert([
            'id' => $id,
            ...$data,
            'placed_by' => $request->user()->id,
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hold = DB::table('legal_holds')->where('id', $id)->first();
        $audit->record('retention.legal_hold_placed', 'legal_hold', $id, after: (array) $hold, actor: $request->user(), reason: $data['reason']);

        return response()->json(['legal_hold' => $hold, 'duplicate' => false], 201);
    }

    public function releaseHold(Request $request, string $legalHold, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $hold = DB::table('legal_holds')->where('id', $legalHold)->first();
        abort_if($hold === null, 404);
        abort_if($hold->released_at !== null, 409, 'The legal hold was already released.');
        DB::table('legal_holds')->where('id', $legalHold)->update(['released_by' => $request->user()->id, 'released_at' => now(), 'updated_at' => now()]);
        $audit->record('retention.legal_hold_released', 'legal_hold', $legalHold, before: (array) $hold, after: ['released_by' => $request->user()->id], actor: $request->user(), reason: $data['reason']);

        return response()->json(['status' => 'released']);
    }

    public function requestPurge(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('system_administrator', 'hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'record_category' => ['required', Rule::in(self::PURGEABLE_CATEGORIES)],
            'recruitment_campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $policy = DB::table('retention_policies')
            ->where('record_category', $data['record_category'])
            ->where(fn ($query) => $query->where('recruitment_campaign_id', $data['recruitment_campaign_id'] ?? null)->orWhereNull('recruitment_campaign_id'))
            ->orderByRaw('case when recruitment_campaign_id is null then 1 else 0 end')->first();
        abort_if($policy === null || $policy->approved_at === null, 422, 'An approved retention policy is required before a purge request.');
        abort_unless($policy->disposition === 'review_for_purge', 422, 'This policy requires archive rather than purge review.');
        $scope = ['recruitment_campaign_id' => $data['recruitment_campaign_id'] ?? null, 'cutoff' => now()->subDays($policy->retention_days)->toISOString(), 'retention_policy_id' => $policy->id];
        $eligibleIds = $this->eligibleIds($data['record_category'], $scope);
        $id = (string) Str::ulid();
        DB::table('purge_requests')->insert([
            'id' => $id,
            'record_category' => $data['record_category'],
            'scope' => json_encode($scope, JSON_THROW_ON_ERROR),
            'eligible_record_count' => count($eligibleIds),
            'status' => 'pending_approval',
            'reason' => $data['reason'],
            'requested_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purgeRequest = DB::table('purge_requests')->where('id', $id)->first();
        $audit->record('retention.purge_requested', 'purge_request', $id, after: [...(array) $purgeRequest, 'candidate_fingerprint' => hash('sha256', implode('|', $eligibleIds))], actor: $request->user(), reason: $data['reason']);

        return response()->json(['purge_request' => $purgeRequest], 201);
    }

    public function approvePurge(Request $request, string $purgeRequest, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $record = DB::table('purge_requests')->where('id', $purgeRequest)->first();
        abort_if($record === null, 404);
        abort_unless($record->status === 'pending_approval', 409, 'This purge request is not awaiting approval.');
        abort_if((int) $record->requested_by === (int) $request->user()->id, 409, 'The requester cannot approve the same purge request.');
        $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        DB::table('purge_requests')->where('id', $purgeRequest)->update([
            'status' => $status,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record("retention.purge_{$status}", 'purge_request', $purgeRequest, before: (array) $record, after: ['status' => $status], actor: $request->user(), reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['status' => $status]);
    }

    public function executePurge(Request $request, string $purgeRequest, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('system_administrator'), 403);
        $data = $request->validate([
            'confirmation' => ['required', 'in:PURGE'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $record = DB::table('purge_requests')->where('id', $purgeRequest)->first();
        abort_if($record === null, 404);
        abort_unless($record->status === 'approved', 409, 'Only an approved purge request can execute.');
        abort_if((int) $record->requested_by === (int) $request->user()->id, 409, 'The purge requester cannot execute the same operation.');
        $scope = json_decode($record->scope, true, 512, JSON_THROW_ON_ERROR);
        $ids = $this->eligibleIds($record->record_category, $scope);
        $evidence = [
            'purge_request_id' => $record->id,
            'record_category' => $record->record_category,
            'scope' => $scope,
            'approved_by' => $record->approved_by,
            'candidate_ids' => $ids,
            'candidate_count' => count($ids),
        ];
        $evidenceHash = $canonicalJson->hash($evidence);
        $audit->record('retention.purge_execution_started', 'purge_request', $record->id, before: (array) $record, after: ['evidence_hash' => $evidenceHash, 'candidate_count' => count($ids)], actor: $request->user(), reason: $data['reason']);

        $deleted = $this->purge($record->record_category, $ids);
        DB::table('purge_requests')->where('id', $record->id)->update([
            'status' => 'executed',
            'eligible_record_count' => $deleted,
            'executed_at' => now(),
            'evidence_hash' => $evidenceHash,
            'updated_at' => now(),
        ]);
        $audit->record('retention.purge_executed', 'purge_request', $record->id, after: ['evidence_hash' => $evidenceHash, 'deleted_count' => $deleted], actor: $request->user(), reason: $data['reason']);

        return response()->json(['status' => 'executed', 'deleted_count' => $deleted, 'evidence_hash' => $evidenceHash]);
    }

    /** @param array<string, mixed> $scope
     * @return list<string>
     */
    private function eligibleIds(string $category, array $scope): array
    {
        $cutoff = $scope['cutoff'];
        $campaignId = $scope['recruitment_campaign_id'] ?? null;
        $query = match ($category) {
            'notifications' => DB::table('notifications')->whereIn('status', ['delivered', 'failed'])->where('updated_at', '<', $cutoff)
                ->when($campaignId, fn ($builder, $id) => $builder->whereIn('application_id', DB::table('applications')->where('recruitment_campaign_id', $id)->select('id'))),
            'exports' => DB::table('exports')->where('expires_at', '<', $cutoff),
            'expired_upload_sessions' => DB::table('upload_sessions')->whereIn('status', ['expired', 'completed'])->where('expires_at', '<', $cutoff)
                ->when($campaignId, fn ($builder, $id) => $builder->whereIn('application_id', DB::table('applications')->where('recruitment_campaign_id', $id)->select('id'))),
            default => throw new \DomainException('Unsupported purge category.'),
        };
        $heldIds = DB::table('legal_holds')->where('entity_type', $category)->whereNull('released_at')->pluck('entity_id');

        return $query->whereNotIn('id', $heldIds)->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /** @param list<string> $ids */
    private function purge(string $category, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        if ($category === 'exports') {
            foreach (DB::table('exports')->whereIn('id', $ids)->get(['storage_path']) as $export) {
                if ($export->storage_path) {
                    Storage::disk(config('erecruit.uploads.disk'))->delete($export->storage_path);
                }
            }
        }
        if ($category === 'expired_upload_sessions') {
            foreach ($ids as $id) {
                $disk = Storage::disk(config('erecruit.uploads.disk'));
                $files = $disk->files("upload-sessions/{$id}/chunks");
                if ($files !== []) {
                    $disk->delete($files);
                }
            }
        }

        return DB::table(match ($category) {
            'notifications' => 'notifications',
            'exports' => 'exports',
            'expired_upload_sessions' => 'upload_sessions',
        })->whereIn('id', $ids)->delete();
    }
}
