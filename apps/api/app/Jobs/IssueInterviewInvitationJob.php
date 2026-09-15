<?php

namespace App\Jobs;

use App\Models\InterviewAssignment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InterviewInvitationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IssueInterviewInvitationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public string $assignmentId, public int $issuedBy) {}

    /**
     * Execute the job.
     */
    public function handle(InterviewInvitationService $invitations, AuditService $audit): void
    {
        $assignment = InterviewAssignment::query()->findOrFail($this->assignmentId);
        $actor = User::query()->findOrFail($this->issuedBy);
        $result = $invitations->issue(
            $assignment,
            array_values(config('erecruit.interview.default_invitation_instructions')),
            $actor,
        );
        if (! $result['idempotent']) {
            $audit->record('interview.invitation_issued', $assignment, actor: $actor, after: [
                'invitation_id' => $result['invitation']->id,
                'automated' => true,
            ]);
        }
    }
}
