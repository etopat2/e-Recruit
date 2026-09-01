<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverNotificationJob;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\TrainingReporting;
use App\Services\AuditService;
use App\Services\InvitationArtifactService;
use App\Services\ScopeAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingController extends Controller
{
    public function invite(Request $request, InvitationArtifactService $artifacts, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'prisons_council_secretariat'), 403);
        $data = $request->validate([
            'final_selection_id' => ['required', 'exists:final_selections,id'],
            'reporting_date' => ['required', 'date'],
            'reporting_time' => ['required', 'date_format:H:i'],
            'location' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'array', 'min:1', 'max:50'],
        ]);
        $selection = DB::table('final_selections')
            ->join('applications', 'applications.id', '=', 'final_selections.application_id')
            ->where('final_selections.id', $data['final_selection_id'])
            ->select('final_selections.*', 'applications.reference')->firstOrFail();
        abort_unless($selection->status === 'approved', 409, 'Only approved final selections may receive training invitations.');
        $existing = DB::table('training_invites')->where('final_selection_id', $data['final_selection_id'])->first();
        if ($existing !== null) {
            return response()->json(['invite' => $existing, 'idempotent' => true]);
        }
        $id = (string) Str::ulid();
        $path = "artefacts/training/{$id}.pdf";
        $verificationUrl = rtrim((string) config('app.url'), '/').'/official-artifacts/verify?reference='.rawurlencode((string) $selection->reference);
        $artifact = $artifacts->create('pdf.training-invite', ['selection' => $selection, 'invite' => (object) $data], $path, $verificationUrl);
        DB::table('training_invites')->insert([
            'id' => $id,
            ...$data,
            'instructions' => json_encode($data['instructions'], JSON_THROW_ON_ERROR),
            'document_path' => $path,
            'sha256' => $artifact['sha256'],
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $application = Application::query()->findOrFail($selection->application_id);
        $recipient = DB::table('applicants')->where('id', $application->applicant_id)->value('user_id');
        $notificationId = (string) Str::ulid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'application_id' => $application->id,
            'event_code' => 'training.invited',
            'channel' => 'in_portal',
            'recipient' => (string) $recipient,
            'status' => 'pending',
            'idempotency_key' => hash('sha256', "training.invited:{$data['final_selection_id']}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DeliverNotificationJob::dispatch($notificationId);
        $audit->record('training.invitation_issued', 'training_invite', $id, actor: $request->user(), after: ['final_selection_id' => $data['final_selection_id'], 'sha256' => $artifact['sha256']]);

        return response()->json(['invite' => DB::table('training_invites')->where('id', $id)->first()], 201);
    }

    public function report(Request $request, ScopeAuthorizer $scopeAuthorizer, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('training_school_officer', 'hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'training_invite_id' => ['required', 'exists:training_invites,id'],
            'status' => ['required', 'in:expected,reported,verified,admitted,late,documentation_incomplete,no_show,withdrawn,replacement,not_reported,declined'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $applicationId = DB::table('training_invites')->join('final_selections', 'final_selections.id', '=', 'training_invites.final_selection_id')->where('training_invites.id', $data['training_invite_id'])->value('final_selections.application_id');
        $application = Application::query()->findOrFail($applicationId);
        abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:training', $application), 403);
        $record = TrainingReporting::query()->create([
            ...$data,
            'recorded_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);
        $audit->record('training.reporting_recorded', $record, actor: $request->user(), after: $data, reason: $data['notes'] ?? null);

        return response()->json(['reporting' => $record], 201);
    }

    public function downloadInvitation(Request $request, string $invitation): StreamedResponse
    {
        $invite = DB::table('training_invites')->where('id', $invitation)->firstOrFail();
        $application = Application::query()->whereIn('id', DB::table('final_selections')->where('id', $invite->final_selection_id)->select('application_id'))->firstOrFail();
        $this->authorize('view', $application);

        return Storage::disk(config('erecruit.uploads.disk'))->download($invite->document_path, "{$application->reference}-training-invitation.pdf", ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function recommendReplacement(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'replaced_application_id' => ['required', 'exists:applications,id'],
            'selection_run_id' => ['required', 'exists:selection_runs,id'],
            'trigger' => ['required', 'in:not_fit,no_show,withdrawal,training_vacancy'],
            'reason' => ['required', 'string', 'min:20', 'max:4000'],
        ]);
        abort_unless(DB::table('selection_runs')->where('id', $data['selection_run_id'])->where('status', 'certified')->exists(), 409, 'Reserve replacement requires a certified selection run.');
        abort_unless(DB::table('final_selections')->where('application_id', $data['replaced_application_id'])->where('status', 'approved')->exists(), 409, 'The replaced candidate does not have an approved final selection.');
        $nextReserve = DB::table('reserve_list_entries')->where('selection_run_id', $data['selection_run_id'])->where('status', 'available')->orderBy('position')->first();
        abort_if($nextReserve === null, 422, 'No available reserve candidate remains in this selection run.');
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $data, $nextReserve, $request): void {
            DB::table('reserve_replacement_recommendations')->insert([
                'id' => $id,
                'selection_run_id' => $data['selection_run_id'],
                'replaced_application_id' => $data['replaced_application_id'],
                'reserve_application_id' => $nextReserve->application_id,
                'trigger' => $data['trigger'],
                'reason' => $data['reason'],
                'status' => 'pending_approval',
                'recommended_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('reserve_list_entries')->where('id', $nextReserve->id)->update(['status' => 'recommended', 'updated_at' => now()]);
        }, 3);
        $audit->record('selection.reserve_replacement_recommended', 'application', $nextReserve->application_id, actor: $request->user(), after: ['recommendation_id' => $id, 'replaced_application_id' => $data['replaced_application_id'], 'trigger' => $data['trigger']], reason: $data['reason']);

        return response()->json(['recommendation' => DB::table('reserve_replacement_recommendations')->where('id', $id)->first()], 201);
    }

    public function decideReplacement(Request $request, string $recommendation, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:20', 'max:4000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $record = DB::table('reserve_replacement_recommendations')->where('id', $recommendation)->firstOrFail();
        abort_unless($record->status === 'pending_approval', 409, 'This replacement recommendation has already been decided.');
        abort_if((int) $record->recommended_by === (int) $request->user()->id, 409, 'The recommending officer cannot approve the same replacement.');
        if ($data['decision'] === 'approve') {
            $this->applyReplacement($record, $request, $data['reason'], $data['approval_reference']);
        } else {
            DB::transaction(function () use ($record, $data, $request): void {
                DB::table('reserve_replacement_recommendations')->where('id', $record->id)->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decision_reason' => $data['reason'], 'approval_reference' => $data['approval_reference'], 'decided_at' => now(), 'updated_at' => now()]);
                DB::table('reserve_list_entries')->where('selection_run_id', $record->selection_run_id)->where('application_id', $record->reserve_application_id)->where('status', 'recommended')->update(['status' => 'available', 'updated_at' => now()]);
            }, 3);
        }
        $audit->record('selection.reserve_replacement_decided', 'application', $record->reserve_application_id, actor: $request->user(), after: ['recommendation_id' => $record->id, 'decision' => $data['decision']], reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['recommendation' => DB::table('reserve_replacement_recommendations')->where('id', $record->id)->first()]);
    }

    public function replace(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate([
            'replaced_application_id' => ['required', 'exists:applications,id'],
            'reserve_application_id' => ['required', 'different:replaced_application_id', 'exists:applications,id'],
            'selection_run_id' => ['required', 'exists:selection_runs,id'],
            'reason' => ['required', 'string', 'min:20', 'max:4000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $record = DB::table('reserve_replacement_recommendations')->where('selection_run_id', $data['selection_run_id'])->where('replaced_application_id', $data['replaced_application_id'])->where('reserve_application_id', $data['reserve_application_id'])->where('status', 'pending_approval')->first();
        abort_if($record === null, 409, 'Create an independently reviewed replacement recommendation before approval.');
        abort_if((int) $record->recommended_by === (int) $request->user()->id, 409, 'The recommending officer cannot approve the same replacement.');
        $this->applyReplacement($record, $request, $data['reason'], $data['approval_reference']);
        $audit->record('training.reserve_promoted', 'application', $data['reserve_application_id'], actor: $request->user(), after: ['recommendation_id' => $record->id], reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['message' => 'Reserve candidate promoted in strict order.']);
    }

    private function applyReplacement(object $record, Request $request, ?string $reason = null, ?string $approvalReference = null): void
    {
        $medical = DB::table('medical_results')->where('application_id', $record->reserve_application_id)->where('outcome', 'Fit')->latest('recorded_at')->first();
        abort_if($medical === null, 409, 'A reserve replacement cannot be finally approved until the candidate has a Fit medical result.');
        $selectionOutcomeId = DB::table('selection_outcomes')->where('selection_run_id', $record->selection_run_id)->where('application_id', $record->reserve_application_id)->value('id');
        abort_if($selectionOutcomeId === null, 422, 'The recommended reserve is outside this selection run.');
        DB::transaction(function () use ($record, $request, $medical, $selectionOutcomeId, $reason, $approvalReference): void {
            DB::table('reserve_list_entries')->where('selection_run_id', $record->selection_run_id)->where('application_id', $record->reserve_application_id)->where('status', 'recommended')->update(['status' => 'promoted', 'updated_at' => now()]);
            DB::table('final_selections')->where('application_id', $record->replaced_application_id)->where('status', 'approved')->update(['status' => 'replaced', 'updated_at' => now()]);
            DB::table('final_selections')->updateOrInsert(['application_id' => $record->reserve_application_id], [
                'id' => DB::table('final_selections')->where('application_id', $record->reserve_application_id)->value('id') ?? (string) Str::ulid(),
                'selection_outcome_id' => $selectionOutcomeId,
                'medical_result_id' => $medical->id,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('reserve_replacement_recommendations')->where('id', $record->id)->update([
                'status' => 'approved',
                'decided_by' => $request->user()->id,
                'decision_reason' => $reason ?? request()->string('reason')->toString(),
                'approval_reference' => $approvalReference ?? request()->string('approval_reference')->toString(),
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            $application = Application::query()->lockForUpdate()->findOrFail($record->reserve_application_id);
            $fromStatus = $application->status;
            $application->forceFill(['status' => 'final_selected', 'entity_version' => $application->entity_version + 1])->save();
            ApplicationStatusHistory::query()->create(['application_id' => $application->id, 'from_status' => $fromStatus, 'to_status' => 'final_selected', 'reason' => 'Approved reserve replacement after Fit medical result', 'changed_by' => $request->user()->id, 'source' => 'reserve_replacement']);
        }, 3);
    }
}
