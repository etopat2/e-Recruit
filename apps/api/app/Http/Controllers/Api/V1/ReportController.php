<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'prisons_council_secretariat', 'executive_viewer', 'auditor'), 403);
        $filters = $request->validate([
            'campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'],
            'post_id' => ['nullable', 'exists:recruitment_posts,id'],
        ]);
        $applications = DB::table('applications')
            ->when(isset($filters['campaign_id']), fn ($query) => $query->where('recruitment_campaign_id', $filters['campaign_id']))
            ->when(isset($filters['post_id']), fn ($query) => $query->where('recruitment_post_id', $filters['post_id']));
        $funnel = (clone $applications)->select('status', DB::raw('count(*) as total'))->groupBy('status')->orderBy('status')->get();
        $bySex = (clone $applications)->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->select('applicants.sex', DB::raw('count(*) as total'))->groupBy('applicants.sex')->orderBy('applicants.sex')->get();
        $documentQueue = DB::table('documents')->whereIn('application_id', (clone $applications)->select('id'))
            ->select('processing_status', DB::raw('count(*) as total'))->groupBy('processing_status')->get();
        $eligibility = DB::table('eligibility_runs')->whereIn('application_id', (clone $applications)->select('id'))
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->get();

        return response()->json([
            'generated_at' => now()->toISOString(),
            'filters' => $filters,
            'total_applications' => (clone $applications)->count(),
            'funnel' => $funnel,
            'sex_distribution' => $bySex,
            'document_processing' => $documentQueue,
            'eligibility' => $eligibility,
            'offline_readiness' => [
                'open_conflicts' => DB::table('sync_conflicts')->where('status', 'open')->count(),
                'unsynced_packages' => DB::table('offline_packages')->where('outstanding_events', '>', 0)->count(),
            ],
        ]);
    }

    public function export(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'auditor'), 403);
        $data = $request->validate([
            'export_type' => ['required', 'in:applications,selection,training_handoff,audit'],
            'format' => ['required', 'in:csv'],
            'campaign_id' => ['nullable', 'exists:recruitment_campaigns,id'],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'include_contact_details' => ['nullable', 'boolean'],
        ]);
        $includeContact = ($data['include_contact_details'] ?? false) && $request->user()->hasRole('hq_recruitment_administrator');
        $rows = match ($data['export_type']) {
            'applications' => DB::table('applications')
                ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
                ->when(isset($data['campaign_id']), fn ($query) => $query->where('applications.recruitment_campaign_id', $data['campaign_id']))
                ->select(['applications.reference', 'applications.status', 'applications.submitted_at', 'applicants.first_name', 'applicants.last_name', ...($includeContact ? ['applicants.primary_phone', 'applicants.email'] : [])])
                ->orderBy('applications.reference')->get(),
            'selection' => DB::table('selection_outcomes')->join('applications', 'applications.id', '=', 'selection_outcomes.application_id')
                ->select('applications.reference', 'selection_outcomes.outcome', 'selection_outcomes.position', 'selection_outcomes.score', 'selection_outcomes.bucket_key')
                ->orderBy('selection_outcomes.selection_run_id')->orderBy('selection_outcomes.position')->get(),
            'training_handoff' => DB::table('training_reporting')
                ->join('training_invites', 'training_invites.id', '=', 'training_reporting.training_invite_id')
                ->join('final_selections', 'final_selections.id', '=', 'training_invites.final_selection_id')
                ->join('applications', 'applications.id', '=', 'final_selections.application_id')
                ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
                ->where('training_reporting.status', 'admitted')
                ->when(isset($data['campaign_id']), fn ($query) => $query->where('applications.recruitment_campaign_id', $data['campaign_id']))
                ->select('applications.reference', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'applications.recruitment_post_id', 'training_reporting.recorded_at as admitted_at')
                ->orderBy('applications.reference')->get(),
            'audit' => DB::table('audit_logs')->select('occurred_at', 'action', 'entity_type', 'entity_id', 'correlation_id', 'entry_hash')->orderBy('occurred_at')->get(),
        };
        $handle = fopen('php://temp', 'w+b');
        abort_if($handle === false, 500, 'Unable to prepare export.');
        $columns = $rows->isEmpty() ? ['no_records'] : array_keys((array) $rows->first());
        fputcsv($handle, $columns, escape: '\\');
        foreach ($rows as $row) {
            fputcsv($handle, array_values((array) $row), escape: '\\');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);
        abort_if($contents === false, 500, 'Unable to prepare export.');
        $id = (string) Str::ulid();
        $path = "exports/{$id}.csv";
        Storage::disk(config('erecruit.uploads.disk'))->put($path, $contents);
        DB::table('exports')->insert([
            'id' => $id,
            'requested_by' => $request->user()->id,
            'export_type' => $data['export_type'],
            'format' => 'csv',
            'scope' => json_encode(['campaign_id' => $data['campaign_id'] ?? null], JSON_THROW_ON_ERROR),
            'filters' => json_encode(['campaign_id' => $data['campaign_id'] ?? null], JSON_THROW_ON_ERROR),
            'masking_policy' => json_encode(['contact_details' => $includeContact ? 'included_by_role' : 'excluded'], JSON_THROW_ON_ERROR),
            'purpose' => $data['purpose'],
            'status' => 'ready',
            'storage_path' => $path,
            'sha256' => hash('sha256', $contents),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('export.created', 'export', $id, actor: $request->user(), after: ['type' => $data['export_type'], 'rows' => $rows->count()], reason: $data['purpose']);

        return response()->json(['export_id' => $id, 'status' => 'ready', 'expires_at' => now()->addHours(24)->toISOString()], 201);
    }

    public function download(Request $request, string $export, AuditService $audit): StreamedResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'auditor'), 403);
        $record = DB::table('exports')->where('id', $export)->where('requested_by', $request->user()->id)->firstOrFail();
        abort_if($record->expires_at !== null && now()->isAfter($record->expires_at), 410, 'This export has expired.');
        $audit->record('export.downloaded', 'export', $export, actor: $request->user(), reason: $record->purpose);

        return Storage::disk(config('erecruit.uploads.disk'))->download($record->storage_path, "{$record->export_type}-{$export}.csv", [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
