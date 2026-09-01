<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Offline\OfflineSyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncEventsRequest;
use App\Models\Application;
use App\Models\AssessmentScore;
use App\Models\Document;
use App\Models\InterviewAssignment;
use App\Models\MedicalResult;
use App\Models\OfflinePackage;
use App\Models\Panel;
use App\Models\SyncConflict;
use App\Models\VerifiedValue;
use App\Services\AuditService;
use App\Services\ScopeAuthorizer;
use App\Support\CanonicalJson;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSyncController extends Controller
{
    public function registerDevice(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'public_identifier' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:80'],
            'public_key' => ['nullable', 'string', 'max:2048'],
        ]);
        $device = DB::table('registered_devices')->where('public_identifier', $data['public_identifier'])->first();
        abort_if($device !== null && (int) $device->user_id !== (int) $request->user()->id, 409, 'This device is already registered to another user.');
        $id = $device?->id ?? (string) Str::ulid();
        DB::table('registered_devices')->updateOrInsert(['public_identifier' => $data['public_identifier']], [
            'id' => $id,
            'user_id' => $request->user()->id,
            'label' => $data['label'],
            'platform' => $data['platform'] ?? null,
            'public_key' => $data['public_key'] ?? null,
            'status' => 'active',
            'enrolled_at' => $device?->enrolled_at ?? now(),
            'last_seen_at' => now(),
            'created_at' => $device?->created_at ?? now(),
            'updated_at' => now(),
        ]);
        $audit->record('offline.device_registered', 'registered_device', $id, actor: $request->user(), after: ['label' => $data['label']]);

        return response()->json(['device' => DB::table('registered_devices')->where('id', $id)->first()], $device === null ? 201 : 200);
    }

    public function issue(Request $request, CanonicalJson $canonicalJson, ScopeAuthorizer $scopeAuthorizer, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_member', 'panel_head', 'attendance_officer', 'centre_coordinator', 'hard_copy_receiving_officer', 'regional_recruitment_officer', 'verification_officer', 'data_clerk', 'medical_officer'), 403);
        $data = $request->validate([
            'registered_device_id' => ['required', 'exists:registered_devices,id'],
            'pack_type' => ['required', 'in:interview,attendance,score_capture,hard_copy,verification,medical,panel_closure'],
            'scope' => ['required', 'array'],
            'permitted_actions' => ['required', 'array', 'min:1', 'max:20'],
            'permitted_actions.*' => ['string', 'in:ASSESSMENT_SCORE_RECORDED,ATTENDANCE_RECORDED,HARDCOPY_RECEIPT_RECORDED,DOCUMENT_VERIFICATION_RECORDED,MEDICAL_RESULT_RECORDED,PANEL_CLOSED'],
            'entity_ids' => ['required', 'array', 'max:'.config('erecruit.offline.maximum_records')],
            'entity_ids.*' => ['string', 'max:64'],
            'expiry_hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);
        $device = DB::table('registered_devices')->where('id', $data['registered_device_id'])
            ->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_if($device === null, 403, 'The active device must belong to the current user.');
        $packDefinitions = [
            'score_capture' => ['assessment_score', 'ASSESSMENT_SCORE_RECORDED'],
            'interview' => ['interview_assignment', 'ATTENDANCE_RECORDED'],
            'attendance' => ['interview_assignment', 'ATTENDANCE_RECORDED'],
            'hard_copy' => ['application', 'HARDCOPY_RECEIPT_RECORDED'],
            'verification' => ['document', 'DOCUMENT_VERIFICATION_RECORDED'],
            'medical' => ['application', 'MEDICAL_RESULT_RECORDED'],
            'panel_closure' => ['panel', 'PANEL_CLOSED'],
        ];
        [$entityType, $expectedAction] = $packDefinitions[$data['pack_type']];
        abort_if(collect($data['permitted_actions'])->contains(fn (string $action): bool => $action !== $expectedAction), 422, 'The requested action does not match the pack type.');

        $serverRecords = [];
        foreach ($data['entity_ids'] as $entityId) {
            $serverRecords[] = $this->serverRecord($data['pack_type'], $entityId, $data['scope'], $request, $scopeAuthorizer);
        }
        $issuedAt = now();
        $expiresAt = now()->addHours($data['expiry_hours'] ?? config('erecruit.offline.default_expiry_hours'));
        $packageId = strtolower((string) Str::ulid());
        $manifest = [
            'schema_version' => 1,
            'pack_id' => $packageId,
            'pack_version' => 1,
            'entity_type' => $entityType,
            'entity_ids' => array_values($data['entity_ids']),
            'issued_to' => $request->user()->id,
            'device_id' => $device->id,
            'scope' => $data['scope'],
            'issued_at' => $issuedAt->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'permitted_actions' => array_values(array_unique($data['permitted_actions'])),
            'permitted_payload_schema_versions' => [1],
            'server_versions' => collect($serverRecords)->mapWithKeys(fn (array $record): array => [$record['entity_id'] => $record['server_version']])->all(),
        ];
        $package = OfflinePackage::query()->create([
            'id' => $packageId,
            'registered_device_id' => $device->id,
            'user_id' => $request->user()->id,
            'pack_type' => $data['pack_type'],
            'version' => 1,
            'scope' => $data['scope'],
            'permitted_actions' => array_values(array_unique($data['permitted_actions'])),
            'manifest' => $manifest,
            'manifest_fingerprint' => $canonicalJson->hash($manifest),
            'status' => 'active',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'outstanding_events' => 0,
        ]);
        foreach ($serverRecords as $serverRecord) {
            DB::table('offline_package_records')->insert([
                'id' => (string) Str::ulid(),
                'offline_package_id' => $package->id,
                'entity_type' => $serverRecord['entity_type'],
                'entity_id' => $serverRecord['entity_id'],
                'server_version' => $serverRecord['server_version'],
                'payload_fingerprint' => $canonicalJson->hash($serverRecord['payload']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $audit->record('offline.package_issued', $package, actor: $request->user(), after: ['manifest_fingerprint' => $package->manifest_fingerprint]);

        return response()->json(['package' => $package, 'server_records' => $serverRecords, 'server_time' => now()->toISOString()], 201);
    }

    public function show(Request $request, OfflinePackage $offlinePackage): JsonResponse
    {
        abort_unless((int) $offlinePackage->user_id === (int) $request->user()->id, 403);

        return response()->json([
            'package' => $offlinePackage,
            'server_records' => DB::table('offline_package_records')->where('offline_package_id', $offlinePackage->id)->get(),
            'conflicts' => SyncConflict::query()->whereHas('event', fn ($query) => $query->where('offline_package_id', $offlinePackage->id))->get(),
            'server_time' => now()->toISOString(),
        ]);
    }

    public function changes(Request $request, OfflinePackage $offlinePackage, ScopeAuthorizer $scopeAuthorizer): JsonResponse
    {
        abort_unless((int) $offlinePackage->user_id === (int) $request->user()->id, 403);
        $request->validate(['since' => ['nullable', 'date']]);
        $records = [];
        foreach ($offlinePackage->manifest['entity_ids'] ?? [] as $entityId) {
            $records[] = $this->serverRecord($offlinePackage->pack_type, (string) $entityId, $offlinePackage->scope, $request, $scopeAuthorizer);
        }

        return response()->json([
            'package_status' => $offlinePackage->status,
            'server_records' => $records,
            'conflicts' => SyncConflict::query()->whereHas('event', fn ($query) => $query->where('offline_package_id', $offlinePackage->id))->where('updated_at', '>=', $request->date('since') ?? $offlinePackage->issued_at)->get(),
            'server_cursor' => now()->toISOString(),
        ]);
    }

    public function revokeDevice(Request $request, string $device, AuditService $audit): JsonResponse
    {
        $record = DB::table('registered_devices')->where('id', $device)->first();
        abort_if($record === null, 404);
        abort_unless((int) $record->user_id === (int) $request->user()->id || $request->user()->hasRole('centre_coordinator', 'system_administrator'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($device, $request, $data): void {
            DB::table('registered_devices')->where('id', $device)->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'revocation_reason' => $data['reason'],
                'updated_at' => now(),
            ]);
            DB::table('offline_packages')->where('registered_device_id', $device)->where('status', 'active')->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $audit->record('offline.device_revoked', 'registered_device', $device, before: (array) $record, after: ['status' => 'revoked'], actor: $request->user(), reason: $data['reason']);

        return response()->json(['status' => 'revoked']);
    }

    public function revokePackage(Request $request, OfflinePackage $offlinePackage, AuditService $audit): JsonResponse
    {
        abort_unless((int) $offlinePackage->user_id === (int) $request->user()->id || $request->user()->hasRole('centre_coordinator', 'system_administrator'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        abort_unless($offlinePackage->status === 'active', 409, 'Only an active package can be revoked.');
        $offlinePackage->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $audit->record('offline.package_revoked', $offlinePackage, after: ['status' => 'revoked'], actor: $request->user(), reason: $data['reason']);

        return response()->json(['status' => 'revoked']);
    }

    public function operations(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'system_administrator', 'auditor'), 403);

        return response()->json([
            'devices' => DB::table('registered_devices as devices')->join('users', 'users.id', '=', 'devices.user_id')
                ->select('devices.id', 'devices.label', 'devices.platform', 'devices.status', 'devices.last_seen_at', 'devices.last_sync_at', 'users.name as owner')->orderByDesc('devices.last_seen_at')->paginate(100),
            'packages' => DB::table('offline_packages')->select('id', 'registered_device_id', 'user_id', 'pack_type', 'status', 'expires_at', 'last_sync_at', 'outstanding_events')->orderByDesc('issued_at')->paginate(100),
            'risk' => [
                'expired_active_packs' => DB::table('offline_packages')->where('status', 'active')->where('expires_at', '<', now())->count(),
                'stale_active_packs' => DB::table('offline_packages')->where('status', 'active')->where(fn ($query) => $query->whereNull('last_sync_at')->orWhere('last_sync_at', '<', now()->subHours(12)))->count(),
                'outstanding_events' => DB::table('offline_packages')->where('status', 'active')->sum('outstanding_events'),
                'open_conflicts' => DB::table('sync_conflicts')->where('status', 'open')->count(),
            ],
        ]);
    }

    public function sync(SyncEventsRequest $request, OfflinePackage $offlinePackage, OfflineSyncService $syncService, AuditService $audit): JsonResponse
    {
        try {
            $result = $syncService->push($offlinePackage, $request->user(), $request->validated('events'), $request->safe()->only([
                'client_pending_count',
                'last_local_sequence',
                'complete',
            ]));
        } catch (DomainException $exception) {
            abort(409, $exception->getMessage());
        }
        $audit->record('offline.events_synced', $offlinePackage, actor: $request->user(), after: [
            'event_count' => count($request->validated('events')),
            'conflict_count' => $result['conflict_count'],
        ]);

        return response()->json($result);
    }

    public function resolveConflict(Request $request, SyncConflict $conflict, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_head', 'centre_coordinator', 'hq_recruitment_administrator'), 403);
        abort_unless($conflict->status === 'open', 409, 'This conflict has already been resolved.');
        $conflict->load('event.package');
        $data = $request->validate([
            'resolution' => ['required', 'in:keep_server,accept_local,manual_value'],
            'resolved_value' => ['nullable', 'array', 'required_if:resolution,manual_value'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $resolvedValue = match ($data['resolution']) {
            'keep_server' => $conflict->server_value,
            'accept_local' => $conflict->local_value,
            default => $data['resolved_value'],
        };
        abort_if(
            $data['resolution'] !== 'keep_server' && ! in_array($conflict->entity_type, ['assessment_score', 'interview_assignment', 'document'], true)
                && $conflict->event->action_type !== 'MEDICAL_RESULT_RECORDED',
            422,
            'This conflict must keep the current server state; issue a fresh scoped pack to re-enter the proposed action.',
        );
        DB::transaction(function () use ($conflict, $data, $resolvedValue, $request): void {
            if ($data['resolution'] !== 'keep_server' && $conflict->entity_type === 'assessment_score') {
                DB::table('assessment_scores')->where('id', $conflict->entity_id)->update([
                    'score' => data_get($resolvedValue, 'score'),
                    'entity_version' => DB::raw('entity_version + 1'),
                    'updated_at' => now(),
                ]);
            } elseif ($data['resolution'] !== 'keep_server' && $conflict->entity_type === 'interview_assignment') {
                $existingAttendance = DB::table('attendance_records')->where('interview_assignment_id', $conflict->entity_id)->first();
                DB::table('attendance_records')->updateOrInsert(['interview_assignment_id' => $conflict->entity_id], [
                    'id' => $existingAttendance->id ?? (string) Str::ulid(),
                    'status' => data_get($resolvedValue, 'status'),
                    'recorded_at' => now(),
                    'recorded_by' => $request->user()->id,
                    'entity_version' => ((int) ($existingAttendance->entity_version ?? 1)) + 1,
                    'created_at' => $existingAttendance->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            } elseif ($data['resolution'] !== 'keep_server' && $conflict->entity_type === 'document') {
                $document = Document::query()->findOrFail($conflict->entity_id);
                $action = (string) data_get($resolvedValue, 'action');
                abort_unless(in_array($action, ['verify', 'flag_discrepancy', 'correct', 'mark_ocr_incorrect', 'request_replacement', 'mark_unreadable', 'mark_not_present'], true), 422, 'Resolved verification action is invalid.');
                DB::table('document_verifications')->insert([
                    'id' => (string) Str::ulid(),
                    'document_id' => $document->id,
                    'extracted_field_id' => data_get($resolvedValue, 'extracted_field_id'),
                    'action' => $action,
                    'outcome' => data_get($resolvedValue, 'outcome'),
                    'reason' => $data['reason'],
                    'review_state' => json_encode(['conflict_id' => $conflict->id, 'resolution' => $data['resolution']], JSON_THROW_ON_ERROR),
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (in_array($action, ['verify', 'correct'], true)) {
                    $current = VerifiedValue::query()->where('application_id', $document->application_id)->where('field_key', data_get($resolvedValue, 'field_key'))->where('current', true)->lockForUpdate()->first();
                    $current?->forceFill(['current' => false])->save();
                    VerifiedValue::query()->create([
                        'application_id' => $document->application_id,
                        'supersedes_id' => $current?->id,
                        'field_key' => data_get($resolvedValue, 'field_key'),
                        'verified_value' => ['value' => data_get($resolvedValue, 'verified_value')],
                        'evidence_references' => data_get($resolvedValue, 'evidence_references', []),
                        'verification_method' => 'offline_conflict_resolution',
                        'reason' => $data['reason'],
                        'verified_by' => $request->user()->id,
                        'verified_at' => now(),
                        'current' => true,
                    ]);
                }
            } elseif ($data['resolution'] !== 'keep_server' && $conflict->event->action_type === 'MEDICAL_RESULT_RECORDED') {
                $scheduleId = (string) data_get($conflict->event->package->scope, 'medical_schedule_id');
                $result = MedicalResult::query()->where('application_id', $conflict->entity_id)->where('medical_schedule_id', $scheduleId)->lockForUpdate()->firstOrNew();
                $result->fill([
                    'application_id' => $conflict->entity_id,
                    'medical_schedule_id' => $scheduleId,
                    'outcome' => data_get($resolvedValue, 'outcome'),
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                    'entity_version' => ((int) ($result->entity_version ?? 1)) + 1,
                ])->save();
            }
            $conflict->forceFill([
                'status' => 'resolved',
                'resolution' => $data['resolution'],
                'resolved_value' => $resolvedValue,
                'resolution_reason' => $data['reason'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ])->save();
            $conflict->event->forceFill(['sync_state' => $data['resolution'] === 'keep_server' ? 'rejected' : 'accepted'])->save();
        }, 3);
        $audit->record('offline.conflict_resolved', $conflict, actor: $request->user(), after: $data, reason: $data['reason']);

        return response()->json(['conflict' => $conflict->fresh()]);
    }

    /** @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function serverRecord(string $packType, string $entityId, array $scope, Request $request, ScopeAuthorizer $scopeAuthorizer): array
    {
        if ($packType === 'score_capture') {
            $score = AssessmentScore::query()->with(['assignment.application', 'definition'])->find($entityId);
            abort_if($score === null, 422, 'A requested assessment score record was not found.');
            abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:score', $score->assignment->application), 403);
            abort_if($request->user()->hasRole('panel_member') && (int) $score->assessor_id !== (int) $request->user()->id, 403, 'Panel members may download only their own score records.');

            return ['entity_type' => 'assessment_score', 'entity_id' => $score->id, 'server_version' => $score->entity_version, 'payload' => [
                'application_reference' => $score->assignment->application->reference,
                'assignment_id' => $score->interview_assignment_id,
                'assessment_code' => $score->definition->code,
                'assessment_name' => $score->definition->name,
                'maximum_mark' => $score->definition->maximum_mark,
                'score' => $score->score,
                'status' => $score->status,
            ]];
        }
        if (in_array($packType, ['interview', 'attendance'], true)) {
            $assignment = InterviewAssignment::query()->with('application')->find($entityId);
            abort_if($assignment === null, 422, 'A requested interview assignment was not found.');
            abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:attendance', $assignment->application), 403);
            $attendance = DB::table('attendance_records')->where('interview_assignment_id', $assignment->id)->first();

            return ['entity_type' => 'interview_assignment', 'entity_id' => $assignment->id, 'server_version' => (int) ($attendance->entity_version ?? 1), 'payload' => [
                'application_reference' => $assignment->application->reference,
                'assignment_order' => $assignment->assignment_order,
                'panel_id' => $assignment->panel_id,
                'attendance_status' => $attendance->status ?? null,
            ]];
        }
        if ($packType === 'hard_copy') {
            abort_unless($request->user()->hasRole('hard_copy_receiving_officer', 'centre_coordinator', 'regional_recruitment_officer'), 403);
            $application = Application::query()->find($entityId);
            abort_if($application === null, 422, 'A requested application was not found.');
            abort_unless($scopeAuthorizer->canViewApplication($request->user(), $application), 403);
            $requirements = DB::table('campaign_document_requirements')->where('recruitment_post_id', $application->recruitment_post_id)->where('campaign_version_id', $application->campaign_version_id)->get(['document_type', 'label', 'hard_copy_required', 'original_required_at_interview']);

            return ['entity_type' => 'application', 'entity_id' => $application->id, 'server_version' => $application->entity_version, 'payload' => [
                'application_reference' => $application->reference,
                'status' => $application->status,
                'document_requirements' => $requirements,
            ]];
        }
        if ($packType === 'verification') {
            abort_unless($request->user()->hasRole('verification_officer', 'data_clerk'), 403);
            $document = Document::query()->with('application')->find($entityId);
            abort_if($document === null, 422, 'A requested document was not found.');
            abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:verification', $document->application), 403);
            $fields = DB::table('extracted_fields')->whereIn('document_extraction_id', DB::table('document_extractions')->where('document_id', $document->id)->select('id'))
                ->get(['id', 'field_key', 'raw_value', 'normalised_value', 'confidence', 'page_number', 'bounding_polygon']);

            return ['entity_type' => 'document', 'entity_id' => $document->id, 'server_version' => $document->version, 'payload' => [
                'application_reference' => $document->application->reference,
                'document_type' => $document->document_type,
                'document_version' => $document->version,
                'preview_endpoint' => "/api/v1/documents/{$document->id}/download",
                'extracted_fields' => $fields,
            ]];
        }
        if ($packType === 'medical') {
            abort_unless($request->user()->hasRole('medical_officer'), 403);
            $application = Application::query()->with('applicant')->find($entityId);
            abort_if($application === null, 422, 'A requested application was not found.');
            abort_unless($scopeAuthorizer->canViewRestrictedMedical($request->user(), $application), 403);
            $certifiedSelected = DB::table('selection_outcomes')->join('selection_runs', 'selection_runs.id', '=', 'selection_outcomes.selection_run_id')->where('selection_outcomes.application_id', $application->id)->where('selection_outcomes.outcome', 'selected')->where('selection_runs.status', 'certified')->exists();
            $recommendedReserve = DB::table('reserve_replacement_recommendations')->join('selection_runs', 'selection_runs.id', '=', 'reserve_replacement_recommendations.selection_run_id')->where('reserve_replacement_recommendations.reserve_application_id', $application->id)->where('reserve_replacement_recommendations.status', 'pending_approval')->where('selection_runs.status', 'certified')->exists();
            abort_unless($certifiedSelected || $recommendedReserve, 409, 'Only certified provisionally selected candidates or formally recommended reserves may be included in a medical pack.');
            $scheduleId = (string) ($scope['medical_schedule_id'] ?? '');
            $schedule = DB::table('medical_schedules')->where('id', $scheduleId)->where('recruitment_post_id', $application->recruitment_post_id)->first();
            abort_if($schedule === null, 422, 'A valid medical schedule must be included in the pack scope.');
            $result = DB::table('medical_results')->where('application_id', $application->id)->where('medical_schedule_id', $scheduleId)->first();

            return ['entity_type' => 'application', 'entity_id' => $application->id, 'server_version' => (int) ($result->entity_version ?? 1), 'payload' => [
                'application_reference' => $application->reference,
                'candidate' => ['first_name' => $application->applicant->first_name, 'last_name' => $application->applicant->last_name, 'date_of_birth' => $application->applicant->date_of_birth, 'sex' => $application->applicant->sex],
                'medical_schedule_id' => $scheduleId,
                'facility' => $schedule->facility,
                'scheduled_date' => $schedule->scheduled_date,
                'outcome' => $result->outcome ?? null,
            ]];
        }

        abort_unless($packType === 'panel_closure' && $request->user()->hasRole('panel_head'), 403);
        $panel = Panel::query()->with('assignments.application')->find($entityId);
        abort_if($panel === null || $panel->assignments->isEmpty(), 422, 'A requested panel with assignments was not found.');
        abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:panel_close', $panel->assignments->first()->application), 403);
        $closure = DB::table('panel_closures')->where('panel_id', $panel->id)->first();

        return ['entity_type' => 'panel', 'entity_id' => $panel->id, 'server_version' => $closure ? 2 : 1, 'payload' => [
            'panel_code' => $panel->code,
            'panel_status' => $panel->status,
            'assignment_count' => $panel->assignments->count(),
            'application_references' => $panel->assignments->pluck('application.reference')->all(),
        ]];
    }
}
