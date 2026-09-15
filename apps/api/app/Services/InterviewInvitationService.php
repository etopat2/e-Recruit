<?php

namespace App\Services;

use App\Jobs\DeliverNotificationJob;
use App\Models\InterviewAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InterviewInvitationService
{
    public function __construct(private InvitationArtifactService $artifacts) {}

    /**
     * @param  list<string>  $instructions
     * @return array{invitation: object, idempotent: bool}
     */
    public function issue(InterviewAssignment $assignment, array $instructions, User $actor): array
    {
        $existing = DB::table('interview_invitations')->where('interview_assignment_id', $assignment->id)->first();
        if ($existing !== null) {
            return ['invitation' => $existing, 'idempotent' => true];
        }

        $details = DB::table('interview_assignments as assignments')
            ->join('applications', 'applications.id', '=', 'assignments.application_id')
            ->join('recruitment_posts', 'recruitment_posts.id', '=', 'applications.recruitment_post_id')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'assignments.centre_session_id')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->join('panels', 'panels.id', '=', 'assignments.panel_id')
            ->where('assignments.id', $assignment->id)
            ->select(
                'assignments.id', 'applications.reference', 'applications.applicant_id',
                'recruitment_posts.name as post_name', 'centre_sessions.code as session_code',
                'centre_sessions.session_date', 'centre_sessions.reporting_time', 'centre_sessions.room',
                'recruitment_centres.name as centre_name', 'recruitment_centres.address as centre_address',
                'panels.code as panel_code',
            )->firstOrFail();
        $id = (string) Str::ulid();
        $artifact = $this->artifacts->create(
            'pdf.interview-invite',
            ['assignment' => $details, 'instructions' => $instructions],
            "artefacts/interviews/{$id}.pdf",
            $this->verificationUrl($details->reference),
        );
        $notificationId = (string) Str::ulid();

        DB::transaction(function () use ($id, $assignment, $instructions, $artifact, $actor, $notificationId, $details): void {
            DB::table('interview_invitations')->insert([
                'id' => $id,
                'interview_assignment_id' => $assignment->id,
                'instructions' => json_encode($instructions, JSON_THROW_ON_ERROR),
                'document_path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'issued_by' => $actor->id,
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
        DeliverNotificationJob::dispatch($notificationId)->afterCommit();

        return [
            'invitation' => DB::table('interview_invitations')->where('id', $id)->firstOrFail(),
            'idempotent' => false,
        ];
    }

    private function verificationUrl(string $reference): string
    {
        return rtrim((string) config('app.url'), '/').'/official-artifacts/verify?reference='.rawurlencode($reference);
    }
}
