<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assessments\AssessmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScoreRequest;
use App\Models\AssessmentDefinition;
use App\Models\AssessmentScore;
use App\Models\InterviewAssignment;
use App\Models\Panel;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function definitions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('written_examination_officer', 'panel_member', 'panel_head', 'hq_recruitment_administrator'), 403);
        $query = AssessmentDefinition::query()->with('post:id,recruitment_campaign_id,code,name');
        if (! $request->user()->hasRole('hq_recruitment_administrator')) {
            $postIds = $request->user()->scopes()->where('scope_type', 'post')->pluck('scope_id');
            $query->whereIn('recruitment_post_id', $postIds);
        }

        return response()->json(['data' => $query->orderBy('recruitment_post_id')->orderBy('code')->get()]);
    }

    public function store(StoreScoreRequest $request, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        $assignment = InterviewAssignment::query()->with('application')->findOrFail($data['interview_assignment_id']);
        $this->authorize('view', $assignment->application);
        $definition = AssessmentDefinition::query()->findOrFail($data['assessment_definition_id']);
        abort_unless($definition->recruitment_post_id === $assignment->application->recruitment_post_id, 422, 'Assessment component does not belong to this post.');
        abort_if(DB::table('panel_closures')->where('panel_id', $assignment->panel_id)->where('status', 'closed')->exists(), 409, 'The panel is closed; scores are immutable.');
        $attendanceStatus = DB::table('attendance_records')->where('interview_assignment_id', $assignment->id)->value('status');
        $attendanceException = ! in_array($attendanceStatus, ['present', 'late'], true);
        if ($attendanceException) {
            abort_unless($request->user()->hasRole('panel_head') && filled($data['attendance_exception_reason'] ?? null), 409, 'Candidate must be checked in before scoring; a panel-head exception requires a reason.');
        }
        if ((float) $data['score'] > (float) $definition->maximum_mark) {
            throw ValidationException::withMessages(['score' => "Score must not exceed {$definition->maximum_mark}."]);
        }

        $result = DB::transaction(function () use ($data, $assignment, $definition, $request, $attendanceException, $attendanceStatus): array {
            $score = AssessmentScore::query()->lockForUpdate()->firstOrNew([
                'interview_assignment_id' => $assignment->id,
                'assessment_definition_id' => $definition->id,
                'assessor_id' => $request->user()->id,
            ]);
            if ($score->exists && isset($data['entity_version']) && (int) $score->entity_version !== (int) $data['entity_version']) {
                return ['conflict' => true, 'score' => $score];
            }
            $before = $score->exists ? $score->toArray() : null;
            $score->fill([
                'score' => $data['score'],
                'notes' => $data['notes'] ?? null,
                'status' => 'submitted',
                'entity_version' => ((int) ($score->entity_version ?? 0)) + 1,
                'submitted_at' => now(),
            ])->save();

            if ($attendanceException) {
                DB::table('integrity_flags')->insert([
                    'id' => (string) Str::ulid(),
                    'indicator_type' => 'assessment_without_normal_check_in',
                    'severity' => 'review',
                    'entity_type' => AssessmentScore::class,
                    'entity_id' => $score->id,
                    'evidence' => json_encode(['attendance_status' => $attendanceStatus, 'reason' => $data['attendance_exception_reason']], JSON_THROW_ON_ERROR),
                    'status' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ['conflict' => false, 'score' => $score, 'before' => $before];
        }, 3);
        if ($result['conflict']) {
            return response()->json(['message' => 'The score changed on the server.', 'current' => $result['score']], 409);
        }
        $audit->record('assessment.score_recorded', $result['score'], before: $result['before'], after: $result['score']->toArray(), actor: $request->user(), reason: $data['attendance_exception_reason'] ?? null);

        return response()->json(['score' => $result['score']], $result['before'] === null ? 201 : 200);
    }

    public function aggregate(InterviewAssignment $assignment, AssessmentService $assessmentService): JsonResponse
    {
        $this->authorize('view', $assignment->application);
        $assignment->load(['scores.definition']);
        $definitions = AssessmentDefinition::query()
            ->where('recruitment_post_id', $assignment->application->recruitment_post_id)
            ->where('campaign_version_id', $assignment->application->campaign_version_id)
            ->orderBy('code')
            ->get();
        $scoresByComponent = $assignment->scores
            ->where('status', 'submitted')
            ->groupBy(fn (AssessmentScore $score): string => $score->definition->code)
            ->map(fn ($scores) => $scores->pluck('score')->map(fn ($score): float => (float) $score)->all())
            ->all();

        return response()->json(['assessment' => $assessmentService->aggregate(
            $definitions->map(fn (AssessmentDefinition $definition): array => [
                ...$definition->toArray(),
                'required_assessors' => $definition->assessor_model === 'multi' ? 2 : 1,
            ])->all(),
            $scoresByComponent,
        )]);
    }

    public function closePanel(Request $request, Panel $panel, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_head'), 403);
        $firstApplication = $panel->assignments()->with('application')->first()?->application;
        abort_if($firstApplication === null, 422, 'The panel has no assigned applications.');
        $this->authorize('view', $firstApplication);
        $data = $request->validate(['confirmation' => ['required', 'accepted']]);
        unset($data);
        abort_if(DB::table('sync_conflicts')->where('status', 'open')->whereIn(
            'entity_id',
            DB::table('assessment_scores')->join('interview_assignments', 'interview_assignments.id', '=', 'assessment_scores.interview_assignment_id')
                ->where('interview_assignments.panel_id', $panel->id)->select('assessment_scores.id'),
        )->exists(), 409, 'Resolve all offline score conflicts before closing the panel.');
        abort_if(DB::table('offline_packages')->where('status', 'active')->where(function ($query) use ($panel) {
            $query->whereExists(function ($records) use ($panel) {
                $records->selectRaw('1')->from('offline_package_records')
                    ->join('assessment_scores', 'assessment_scores.id', '=', 'offline_package_records.entity_id')
                    ->join('interview_assignments', 'interview_assignments.id', '=', 'assessment_scores.interview_assignment_id')
                    ->whereColumn('offline_package_records.offline_package_id', 'offline_packages.id')
                    ->where('offline_package_records.entity_type', 'assessment_score')
                    ->where('interview_assignments.panel_id', $panel->id);
            })->orWhereExists(function ($records) use ($panel) {
                $records->selectRaw('1')->from('offline_package_records')
                    ->join('interview_assignments', 'interview_assignments.id', '=', 'offline_package_records.entity_id')
                    ->whereColumn('offline_package_records.offline_package_id', 'offline_packages.id')
                    ->where('offline_package_records.entity_type', 'interview_assignment')
                    ->where('interview_assignments.panel_id', $panel->id);
            });
        })->exists(), 409, 'Reconcile all active offline packages before closing the panel.');
        $scores = AssessmentScore::query()->whereHas('assignment', fn ($query) => $query->where('panel_id', $panel->id))
            ->orderBy('id')->get(['id', 'score', 'status', 'entity_version']);
        $assignments = InterviewAssignment::query()->where('panel_id', $panel->id)->count();
        abort_if($assignments === 0 || $scores->isEmpty(), 422, 'The panel has no submitted assessment data.');
        $fingerprint = $canonicalJson->hash($scores->toArray());
        $closureId = (string) Str::ulid();
        DB::transaction(function () use ($panel, $fingerprint, $closureId, $request): void {
            DB::table('panel_closures')->insert([
                'id' => $closureId,
                'panel_id' => $panel->id,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'score_fingerprint' => $fingerprint,
                'status' => 'closed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $panel->forceFill(['status' => 'closed'])->save();
        }, 3);
        $audit->record('panel.closed', $panel, actor: $request->user(), after: ['score_fingerprint' => $fingerprint]);

        return response()->json(['closure_id' => $closureId, 'score_fingerprint' => $fingerprint]);
    }

    public function adjust(Request $request, AssessmentScore $score, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_head', 'hq_recruitment_administrator'), 403);
        $score->loadMissing(['assignment.application', 'definition']);
        $this->authorize('view', $score->assignment->application);
        $data = $request->validate([
            'new_score' => ['required', 'numeric', 'min:0'],
            'reason_code' => ['required', 'string', 'max:50'],
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        abort_if((float) $data['new_score'] > (float) $score->definition->maximum_mark, 422, 'Adjusted score exceeds the configured maximum.');
        $adjustmentId = (string) Str::ulid();
        DB::table('score_adjustments')->insert([
            'id' => $adjustmentId,
            'assessment_score_id' => $score->id,
            'previous_score' => $score->score,
            'new_score' => $data['new_score'],
            'reason_code' => $data['reason_code'],
            'justification' => $data['justification'],
            'requested_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('assessment.adjustment_requested', $score, actor: $request->user(), after: $data, reason: $data['justification']);

        return response()->json(['adjustment_id' => $adjustmentId, 'status' => 'pending_approval'], 201);
    }

    public function decideAdjustment(Request $request, string $adjustment, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $record = DB::table('score_adjustments')->where('id', $adjustment)->firstOrFail();
        abort_unless($record->status === 'pending', 409, 'This score adjustment has already been decided.');
        abort_if((int) $record->requested_by === (int) $request->user()->id, 409, 'The requester cannot approve their own score adjustment.');

        $score = AssessmentScore::query()->with(['definition', 'assignment'])->findOrFail($record->assessment_score_id);
        abort_if((float) $record->new_score > (float) $score->definition->maximum_mark, 422, 'Adjusted score exceeds the configured maximum.');
        if ($data['decision'] === 'approve') {
            abort_if(DB::table('panel_closures')->where('panel_id', $score->assignment->panel_id)->where('status', 'closed')->exists(), 409, 'Reopen the panel through the controlled workflow before approving a score correction.');
        }

        DB::transaction(function () use ($record, $score, $data, $request): void {
            if ($data['decision'] === 'approve') {
                $score->forceFill([
                    'score' => $record->new_score,
                    'status' => 'submitted',
                    'entity_version' => $score->entity_version + 1,
                    'submitted_at' => now(),
                ])->save();
            }
            DB::table('score_adjustments')->where('id', $record->id)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'decision_reason' => $data['reason'],
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
        $audit->record('assessment.adjustment_decided', $score, actor: $request->user(), before: ['score' => $record->previous_score], after: ['score' => $data['decision'] === 'approve' ? $record->new_score : $score->score, 'decision' => $data['decision']], reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['adjustment' => DB::table('score_adjustments')->where('id', $record->id)->first()]);
    }

    public function reopenPanel(Request $request, Panel $panel, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:4000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $closure = DB::table('panel_closures')->where('panel_id', $panel->id)->where('status', 'closed')->first();
        abort_if($closure === null, 409, 'The panel does not have an active closure.');
        DB::transaction(function () use ($closure, $panel, $data, $request): void {
            DB::table('panel_closures')->where('id', $closure->id)->update([
                'status' => 'reopened',
                'reopen_reason' => $data['reason'],
                'reopened_by' => $request->user()->id,
                'reopened_at' => now(),
                'updated_at' => now(),
            ]);
            $panel->forceFill(['status' => 'open'])->save();
        }, 3);
        $audit->record('panel.reopened', $panel, actor: $request->user(), before: ['status' => 'closed'], after: ['status' => 'open'], reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['panel' => $panel->fresh(), 'closure' => DB::table('panel_closures')->where('id', $closure->id)->first()]);
    }
}
