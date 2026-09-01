<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Selection\SelectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunSelectionRequest;
use App\Models\SelectionOutcome;
use App\Models\SelectionRun;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SelectionController extends Controller
{
    public function rank(Request $request, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        $this->authorize('create', SelectionRun::class);
        $data = $request->validate([
            'recruitment_post_id' => ['required', 'exists:recruitment_posts,id'],
            'campaign_version_id' => ['required', 'exists:campaign_versions,id'],
            'scope_dimension' => ['required', 'string', 'max:40'],
            'tie_break_policy' => ['required', 'array', 'max:10'],
        ]);
        $candidates = DB::table('applications')
            ->join('interview_assignments', 'interview_assignments.application_id', '=', 'applications.id')
            ->join('assessment_scores', 'assessment_scores.interview_assignment_id', '=', 'interview_assignments.id')
            ->join('assessment_definitions', 'assessment_definitions.id', '=', 'assessment_scores.assessment_definition_id')
            ->where('applications.recruitment_post_id', $data['recruitment_post_id'])
            ->where('applications.campaign_version_id', $data['campaign_version_id'])
            ->where('assessment_scores.status', 'submitted')
            ->groupBy('applications.id', 'applications.submitted_at')
            ->select('applications.id as application_id', 'applications.submitted_at', DB::raw('sum((assessment_scores.score / assessment_definitions.maximum_mark) * assessment_definitions.weight) as aggregate_score'))
            ->orderByDesc('aggregate_score')->orderBy('applications.submitted_at')->orderBy('applications.id')->get();
        abort_if($candidates->isEmpty(), 422, 'There are no complete submitted assessment scores to rank.');
        $runNumber = ((int) DB::table('ranking_runs')->where('recruitment_post_id', $data['recruitment_post_id'])->max('run_number')) + 1;
        $snapshot = $candidates->map(fn ($candidate): array => (array) $candidate)->all();
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $data, $runNumber, $snapshot, $candidates, $canonicalJson, $request): void {
            DB::table('ranking_runs')->insert([
                'id' => $id,
                'recruitment_post_id' => $data['recruitment_post_id'],
                'campaign_version_id' => $data['campaign_version_id'],
                'run_number' => $runNumber,
                'scope_dimension' => $data['scope_dimension'],
                'input_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'score_formula' => json_encode(['method' => 'weighted_sum', 'version' => 1], JSON_THROW_ON_ERROR),
                'tie_break_policy' => json_encode($data['tie_break_policy'], JSON_THROW_ON_ERROR),
                'fingerprint' => $canonicalJson->hash(['candidates' => $snapshot, 'tie_break_policy' => $data['tie_break_policy']]),
                'run_by' => $request->user()->id,
                'run_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($candidates as $index => $candidate) {
                DB::table('ranking_results')->insert([
                    'id' => (string) Str::ulid(),
                    'ranking_run_id' => $id,
                    'application_id' => $candidate->application_id,
                    'bucket_key' => 'national',
                    'aggregate_score' => $candidate->aggregate_score,
                    'merit_rank' => $index + 1,
                    'tie_break_values' => json_encode(['submitted_at' => $candidate->submitted_at], JSON_THROW_ON_ERROR),
                    'tie_break_resolution' => $index === 0 ? null : 'configured_policy_then_application_id',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
        $audit->record('ranking.run_created', 'ranking_run', $id, actor: $request->user(), after: ['fingerprint' => $canonicalJson->hash($snapshot)]);

        return response()->json(['ranking_run_id' => $id, 'results' => DB::table('ranking_results')->where('ranking_run_id', $id)->orderBy('merit_rank')->get()], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SelectionRun::class);
        $runs = SelectionRun::query()->withCount('outcomes')->latest()->paginate(50);

        return response()->json($runs);
    }

    public function store(RunSelectionRequest $request, SelectionService $selectionService, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        $rankingRun = DB::table('ranking_runs')->where('id', $data['ranking_run_id'])->firstOrFail();
        $candidates = DB::table('ranking_results')
            ->where('ranking_run_id', $rankingRun->id)
            ->orderBy('application_id')
            ->get()
            ->map(function ($row): array {
                $application = DB::table('applications')->where('id', $row->application_id)->first();
                $verifiedSkills = DB::table('applicant_skills')
                    ->join('skill_categories', 'skill_categories.id', '=', 'applicant_skills.skill_category_id')
                    ->where('applicant_skills.application_id', $application->id)
                    ->get(['skill_categories.code', 'applicant_skills.verification_status as status'])
                    ->map(fn ($skill): array => (array) $skill)->all();

                return [
                    'application_id' => $row->application_id,
                    'score' => (float) $row->aggregate_score,
                    'bucket' => $row->bucket_key,
                    'submitted_at' => $application->submitted_at,
                    'eligible' => true,
                    'assessment_complete' => true,
                    'skills' => $verifiedSkills,
                    ...(is_array($row->tie_break_values) ? $row->tie_break_values : json_decode($row->tie_break_values, true, 512, JSON_THROW_ON_ERROR)),
                ];
            })->all();
        $offlineReadiness = [
            'open_conflicts' => DB::table('sync_conflicts')->where('status', 'open')->count(),
            'unsynced_packages' => DB::table('offline_packages')->where(function ($query) {
                $query->where('status', 'active')->orWhere('outstanding_events', '>', 0);
            })->count(),
            'checked_at' => now()->toISOString(),
        ];
        $ready = $offlineReadiness['open_conflicts'] === 0 && $offlineReadiness['unsynced_packages'] === 0;
        $result = $selectionService->run($candidates, $data['policy'], $ready);
        $run = DB::transaction(function () use ($rankingRun, $data, $result, $offlineReadiness, $canonicalJson, $request): SelectionRun {
            $runNumber = ((int) SelectionRun::query()->where('recruitment_post_id', $rankingRun->recruitment_post_id)->lockForUpdate()->max('run_number')) + 1;
            $run = SelectionRun::query()->create([
                'ranking_run_id' => $rankingRun->id,
                'recruitment_post_id' => $rankingRun->recruitment_post_id,
                'campaign_version_id' => $rankingRun->campaign_version_id,
                'run_number' => $runNumber,
                'mode' => $data['mode'],
                'status' => 'draft',
                'parameters' => $data['policy'],
                'offline_readiness' => $offlineReadiness,
                'input_fingerprint' => $canonicalJson->hash(['ranking_run' => (array) $rankingRun, 'policy' => $data['policy']]),
                'output_fingerprint' => $result['fingerprint'],
                'run_by' => $request->user()->id,
            ]);
            foreach ($result['outcomes'] as $outcome) {
                SelectionOutcome::query()->create([
                    'selection_run_id' => $run->id,
                    'application_id' => $outcome['application_id'],
                    'bucket_key' => $outcome['bucket_key'],
                    'outcome' => $outcome['outcome'],
                    'position' => $outcome['position'],
                    'score' => $outcome['score'],
                    'skill_reservation_applied' => $outcome['skill_reservation_applied'],
                    'manual_adjustment' => false,
                    'decision_trace' => $outcome['decision_trace'],
                ]);
                if ($outcome['outcome'] === 'reserve') {
                    DB::table('reserve_list_entries')->insert([
                        'id' => (string) Str::ulid(),
                        'selection_run_id' => $run->id,
                        'application_id' => $outcome['application_id'],
                        'bucket_key' => $outcome['bucket_key'],
                        'position' => $outcome['position'],
                        'status' => 'available',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $run;
        }, 3);
        $audit->record('selection.run_created', $run, actor: $request->user(), after: [
            'mode' => $run->mode,
            'output_fingerprint' => $run->output_fingerprint,
        ]);

        return response()->json(['run' => $run, 'outcomes' => $run->outcomes()->orderBy('bucket_key')->orderBy('position')->get()], 201);
    }

    public function show(SelectionRun $selectionRun): JsonResponse
    {
        $this->authorize('view', $selectionRun);

        return response()->json(['run' => $selectionRun, 'outcomes' => $selectionRun->outcomes()->orderBy('bucket_key')->orderBy('position')->get()]);
    }

    public function certify(Request $request, SelectionRun $selectionRun, AuditService $audit): JsonResponse
    {
        $this->authorize('certify', $selectionRun);
        $data = $request->validate([
            'confirmation' => ['required', 'accepted'],
            'approval_reference' => ['required', 'string', 'max:255'],
            'exception_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_if(DB::table('sync_conflicts')->where('status', 'open')->exists(), 409, 'Open offline conflicts block certification.');
        abort_if(DB::table('offline_packages')->where(function ($query) {
            $query->where('status', 'active')->orWhere('outstanding_events', '>', 0);
        })->exists(), 409, 'Active or unsynchronised offline packages block certification.');
        $selectionRun->forceFill([
            'status' => 'certified',
            'certified_by' => $request->user()->id,
            'certified_at' => now(),
            'exception_reason' => $data['exception_reason'] ?? null,
        ])->save();
        $audit->record('selection.run_certified', $selectionRun, actor: $request->user(), after: ['status' => 'certified'], reason: $data['exception_reason'] ?? null, approvalReference: $data['approval_reference']);

        return response()->json(['run' => $selectionRun]);
    }

    public function override(Request $request, SelectionRun $selectionRun, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate([
            'application_id' => ['required', 'exists:applications,id'],
            'replaced_application_id' => ['nullable', 'exists:applications,id'],
            'new_outcome' => ['required', 'in:selected,reserve,not_selected'],
            'reason_code' => ['required', 'string', 'max:60'],
            'justification' => ['required', 'string', 'min:20', 'max:4000'],
        ]);
        $outcome = $selectionRun->outcomes()->where('application_id', $data['application_id'])->firstOrFail();
        $id = (string) Str::ulid();
        DB::table('selection_overrides')->insert([
            'id' => $id,
            'selection_run_id' => $selectionRun->id,
            'application_id' => $data['application_id'],
            'replaced_application_id' => $data['replaced_application_id'] ?? null,
            'previous_outcome' => $outcome->outcome,
            'new_outcome' => $data['new_outcome'],
            'reason_code' => $data['reason_code'],
            'justification' => $data['justification'],
            'requested_by' => $request->user()->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('selection.override_requested', $selectionRun, actor: $request->user(), after: $data, reason: $data['justification']);

        return response()->json(['override_id' => $id, 'status' => 'pending'], 201);
    }
}
