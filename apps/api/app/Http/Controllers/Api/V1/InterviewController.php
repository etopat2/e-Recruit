<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverNotificationJob;
use App\Models\Application;
use App\Models\InterviewAssignment;
use App\Models\RecruitmentPost;
use App\Services\AuditService;
use App\Services\InvitationArtifactService;
use App\Services\ScopeAuthorizer;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InterviewController extends Controller
{
    public function assign(Request $request, RecruitmentPost $post, CanonicalJson $canonicalJson, ScopeAuthorizer $scopeAuthorizer, AuditService $audit): JsonResponse
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
        foreach ($applications as $application) {
            abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:schedule', $application), 403, 'One or more applications are outside your authorised scheduling scope.');
        }
        $this->assertSessionScope($request, $session->id);
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

    public function adjust(Request $request, InterviewAssignment $assignment, CanonicalJson $canonicalJson, ScopeAuthorizer $scopeAuthorizer, AuditService $audit): JsonResponse
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
        abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:schedule', $assignment->application), 403);
        $this->assertSessionScope($request, $data['centre_session_id']);
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

    public function invite(Request $request, InterviewAssignment $assignment, InvitationArtifactService $artifacts, AuditService $audit): JsonResponse
    {
        $assignment->loadMissing('application');
        $this->authorize('view', $assignment->application);
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'regional_recruitment_officer', 'centre_coordinator'), 403);
        $data = $request->validate(['instructions' => ['required', 'array', 'min:1', 'max:50'], 'instructions.*' => ['string', 'max:500']]);
        $existing = DB::table('interview_invitations')->where('interview_assignment_id', $assignment->id)->first();
        if ($existing !== null) {
            return response()->json(['invitation' => $existing, 'idempotent' => true]);
        }
        $details = DB::table('interview_assignments as assignments')
            ->join('applications', 'applications.id', '=', 'assignments.application_id')
            ->join('recruitment_posts', 'recruitment_posts.id', '=', 'applications.recruitment_post_id')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'assignments.centre_session_id')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->join('panels', 'panels.id', '=', 'assignments.panel_id')
            ->where('assignments.id', $assignment->id)
            ->select('assignments.id', 'applications.reference', 'applications.applicant_id', 'recruitment_posts.name as post_name', 'centre_sessions.code as session_code', 'centre_sessions.session_date', 'centre_sessions.reporting_time', 'centre_sessions.room', 'recruitment_centres.name as centre_name', 'recruitment_centres.address as centre_address', 'panels.code as panel_code')->firstOrFail();
        $id = (string) Str::ulid();
        $artifact = $artifacts->create('pdf.interview-invite', ['assignment' => $details, 'instructions' => $data['instructions']], "artefacts/interviews/{$id}.pdf", $this->verificationUrl($details->reference));
        $notificationId = (string) Str::ulid();
        DB::transaction(function () use ($id, $assignment, $data, $artifact, $request, $notificationId, $details): void {
            DB::table('interview_invitations')->insert([
                'id' => $id,
                'interview_assignment_id' => $assignment->id,
                'instructions' => json_encode($data['instructions'], JSON_THROW_ON_ERROR),
                'document_path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'issued_by' => $request->user()->id,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $recipient = DB::table('applicants')->where('id', $details->applicant_id)->value('user_id');
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'application_id' => $assignment->application_id,
                'event_code' => 'interview.invited',
                'channel' => 'in_portal',
                'recipient' => (string) $recipient,
                'status' => 'pending',
                'idempotency_key' => hash('sha256', "interview.invited:{$assignment->id}"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
        DeliverNotificationJob::dispatch($notificationId);
        $audit->record('interview.invitation_issued', $assignment, actor: $request->user(), after: ['invitation_id' => $id, 'sha256' => $artifact['sha256']]);

        return response()->json(['invitation' => DB::table('interview_invitations')->where('id', $id)->first(), 'idempotent' => false], 201);
    }

    public function downloadInvitation(Request $request, string $invitation): StreamedResponse
    {
        $record = DB::table('interview_invitations')->where('id', $invitation)->firstOrFail();
        $assignment = InterviewAssignment::query()->with('application')->findOrFail($record->interview_assignment_id);
        $this->authorize('view', $assignment->application);

        return Storage::disk(config('erecruit.uploads.disk'))->download($record->document_path, "{$assignment->application->reference}-interview-invitation.pdf", ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    private function verificationUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/').'/official-artifacts/verify?reference='.rawurlencode($reference);
    }

    private function assertSessionScope(Request $request, string $sessionId): void
    {
        if ($request->user()->hasRole('hq_recruitment_administrator')) {
            return;
        }
        $session = DB::table('centre_sessions')->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')->where('centre_sessions.id', $sessionId)->select('centre_sessions.recruitment_centre_id', 'recruitment_centres.prison_region_id')->firstOrFail();
        $authorised = $request->user()->scopes()->where(function ($query) use ($session): void {
            $query->where(fn ($scope) => $scope->where('scope_type', 'centre')->where('scope_id', $session->recruitment_centre_id))
                ->orWhere(fn ($scope) => $scope->where('scope_type', 'region')->where('scope_id', $session->prison_region_id));
        })->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->get()->contains(fn ($scope): bool => in_array('*', $scope->allowed_tasks ?? [], true) || in_array('decision:schedule', $scope->allowed_tasks ?? [], true));
        abort_unless($authorised, 403, 'The centre session is outside your assigned scope.');
    }
}
