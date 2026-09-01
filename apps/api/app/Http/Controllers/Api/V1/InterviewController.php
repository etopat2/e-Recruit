<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\InterviewAssignment;
use App\Models\RecruitmentPost;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InterviewController extends Controller
{
    public function assign(Request $request, RecruitmentPost $post, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'regional_recruitment_officer', 'centre_coordinator'), 403);
        $data = $request->validate([
            'centre_session_id' => ['required', 'exists:centre_sessions,id'],
            'application_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'application_ids.*' => ['required', 'distinct', 'exists:applications,id'],
            'panel_ids' => ['required', 'array', 'min:1', 'max:50'],
            'panel_ids.*' => ['required', 'distinct', 'exists:panels,id'],
            'algorithm_version' => ['nullable', 'string', 'max:30'],
        ]);

        $session = DB::table('centre_sessions')->where('id', $data['centre_session_id'])->first();
        if ($session === null || $session->recruitment_post_id !== $post->id) {
            throw ValidationException::withMessages(['centre_session_id' => 'The session does not belong to this recruitment post.']);
        }
        $panels = DB::table('panels')->whereIn('id', $data['panel_ids'])->where('centre_session_id', $session->id)->orderBy('code')->get();
        if ($panels->count() !== count($data['panel_ids'])) {
            throw ValidationException::withMessages(['panel_ids' => 'Every panel must belong to the selected centre session.']);
        }
        $applications = Application::query()
            ->whereBelongsTo($post, 'post')
            ->whereIn('id', $data['application_ids'])
            ->orderBy('reference')
            ->orderBy('id')
            ->get();
        if ($applications->count() !== count($data['application_ids'])) {
            throw ValidationException::withMessages(['application_ids' => 'Every application must belong to the selected post.']);
        }
        if ($applications->count() > $panels->sum('capacity')) {
            throw ValidationException::withMessages(['application_ids' => 'The selected panels do not have enough capacity.']);
        }

        $fingerprint = $canonicalJson->hash([
            'post_id' => $post->id,
            'session_id' => $session->id,
            'application_ids' => $applications->pluck('id')->all(),
            'panel_ids' => $panels->pluck('id')->all(),
        ]);
        $assignments = DB::transaction(function () use ($applications, $panels, $session, $fingerprint, $data, $request) {
            $created = collect();
            $panelIndex = 0;
            $panelCounts = array_fill_keys($panels->pluck('id')->all(), 0);
            foreach ($applications as $application) {
                while ($panelCounts[$panels[$panelIndex]->id] >= $panels[$panelIndex]->capacity) {
                    $panelIndex++;
                }
                $panel = $panels[$panelIndex];
                $panelCounts[$panel->id]++;
                $created->push(InterviewAssignment::query()->updateOrCreate(
                    ['application_id' => $application->id],
                    [
                        'centre_session_id' => $session->id,
                        'panel_id' => $panel->id,
                        'assignment_order' => $panelCounts[$panel->id],
                        'algorithm_version' => $data['algorithm_version'] ?? 'round-robin-v1',
                        'input_fingerprint' => $fingerprint,
                        'manual_adjustment' => false,
                        'assigned_by' => $request->user()->id,
                    ],
                ));
            }

            return $created;
        }, 3);
        $audit->record('interview.assignments_generated', $post, actor: $request->user(), after: [
            'count' => $assignments->count(),
            'input_fingerprint' => $fingerprint,
        ]);

        return response()->json(['input_fingerprint' => $fingerprint, 'assignments' => $assignments], 201);
    }

    public function adjust(Request $request, InterviewAssignment $assignment, CanonicalJson $canonicalJson, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $assignment->application);
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'regional_recruitment_officer'), 403);
        $data = $request->validate([
            'centre_session_id' => ['required', 'exists:centre_sessions,id'],
            'panel_id' => ['required', 'exists:panels,id'],
            'assignment_order' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $panelBelongsToSession = DB::table('panels')->where('id', $data['panel_id'])
            ->where('centre_session_id', $data['centre_session_id'])->exists();
        throw_unless($panelBelongsToSession, ValidationException::withMessages(['panel_id' => 'The panel does not belong to the selected session.']));
        $before = $assignment->toArray();
        $assignment->forceFill([
            'centre_session_id' => $data['centre_session_id'],
            'panel_id' => $data['panel_id'],
            'assignment_order' => $data['assignment_order'],
            'manual_adjustment' => true,
            'adjustment_reason' => $data['reason'],
            'input_fingerprint' => $canonicalJson->hash([$assignment->application_id, $data]),
            'assigned_by' => $request->user()->id,
        ])->save();
        $audit->record('interview.assignment_adjusted', $assignment, before: $before, after: $assignment->toArray(), actor: $request->user(), reason: $data['reason']);

        return response()->json(['assignment' => $assignment]);
    }

    public function attendance(Request $request, InterviewAssignment $assignment, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $assignment->application);
        abort_unless($request->user()->hasRole('attendance_officer', 'centre_coordinator', 'panel_head'), 403);
        $data = $request->validate([
            'status' => ['required', 'in:present,absent,late,referred,disqualified,excused,no_show'],
            'exception_reason' => ['nullable', 'string', 'max:2000', 'required_if:status,disqualified'],
            'entity_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $current = DB::table('attendance_records')->where('interview_assignment_id', $assignment->id)->lockForUpdate()->first();
        if ($current !== null && isset($data['entity_version']) && (int) $current->entity_version !== (int) $data['entity_version']) {
            return response()->json(['message' => 'Attendance changed on the server.', 'current' => $current], 409);
        }
        $id = $current?->id ?? (string) Str::ulid();
        DB::table('attendance_records')->updateOrInsert(
            ['interview_assignment_id' => $assignment->id],
            [
                'id' => $id,
                'status' => $data['status'],
                'recorded_at' => now(),
                'recorded_by' => $request->user()->id,
                'exception_reason' => $data['exception_reason'] ?? null,
                'entity_version' => ((int) ($current?->entity_version ?? 0)) + 1,
                'created_at' => $current?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        $audit->record('attendance.recorded', $assignment, actor: $request->user(), after: $data, reason: $data['exception_reason'] ?? null);

        return response()->json(['attendance' => DB::table('attendance_records')->where('id', $id)->first()]);
    }
}
