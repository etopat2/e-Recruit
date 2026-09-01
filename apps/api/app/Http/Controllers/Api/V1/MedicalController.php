<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalResultRequest;
use App\Jobs\DeliverNotificationJob;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\MedicalResult;
use App\Models\SelectionOutcome;
use App\Services\AuditService;
use App\Services\InvitationArtifactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalController extends Controller
{
    public function schedule(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'medical_officer'), 403);
        $data = $request->validate([
            'recruitment_post_id' => ['required', 'exists:recruitment_posts,id'],
            'facility' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date'],
            'reporting_time' => ['required', 'date_format:H:i'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        if (! $request->user()->hasRole('hq_recruitment_administrator')) {
            $campaignId = DB::table('recruitment_posts')->where('id', $data['recruitment_post_id'])->value('recruitment_campaign_id');
            $hasScope = $request->user()->scopes()->where(function ($query) use ($data, $campaignId): void {
                $query->where(fn ($scope) => $scope->where('scope_type', 'post')->where('scope_id', $data['recruitment_post_id']))
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'campaign')->where('scope_id', $campaignId));
            })->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->get()->contains(fn ($scope): bool => in_array('*', $scope->allowed_tasks ?? [], true) || in_array('decision:medical', $scope->allowed_tasks ?? [], true));
            abort_unless($hasScope, 403, 'The recruitment post is outside your medical scope.');
        }
        $id = (string) Str::ulid();
        DB::table('medical_schedules')->insert(['id' => $id, ...$data, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('medical.schedule_created', 'medical_schedule', $id, actor: $request->user(), after: $data);

        return response()->json(['schedule' => DB::table('medical_schedules')->where('id', $id)->first()], 201);
    }

    public function store(StoreMedicalResultRequest $request, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        $application = Application::query()->findOrFail($data['application_id']);
        $this->authorize('view', $application);
        abort_unless(DB::table('medical_schedules')->where('id', $data['medical_schedule_id'])->where('recruitment_post_id', $application->recruitment_post_id)->exists(), 422, 'The medical schedule does not belong to this recruitment post.');
        abort_unless($this->hasCertifiedSelection($application), 409, 'Only a candidate on a certified provisional selection may receive a medical result.');
        $result = DB::transaction(function () use ($data, $request): array {
            $result = MedicalResult::query()->lockForUpdate()->firstOrNew([
                'application_id' => $data['application_id'],
                'medical_schedule_id' => $data['medical_schedule_id'],
            ]);
            if ($result->exists && isset($data['entity_version']) && (int) $result->entity_version !== (int) $data['entity_version']) {
                return ['conflict' => true, 'result' => $result];
            }
            $before = $result->exists ? $result->toArray() : null;
            $result->fill([
                'outcome' => $data['outcome'],
                'restricted_notes' => $data['restricted_notes'] ?? null,
                'clinical_reference' => $data['clinical_reference'] ?? null,
                'recorded_by' => $request->user()->id,
                'recorded_at' => now(),
                'entity_version' => ((int) ($result->entity_version ?? 0)) + 1,
            ])->save();

            return ['conflict' => false, 'result' => $result, 'before' => $before];
        }, 3);
        if ($result['conflict']) {
            return response()->json(['message' => 'The medical result changed on the server.', 'current' => $result['result']], 409);
        }
        $audit->record('medical.result_recorded', $result['result'], before: $result['before'], after: ['outcome' => $data['outcome']], actor: $request->user());

        return response()->json(['result' => $this->payload($result['result'], true)], $result['before'] === null ? 201 : 200);
    }

    public function invite(Request $request, InvitationArtifactService $artifacts, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'medical_officer'), 403);
        $data = $request->validate([
            'application_id' => ['required', 'exists:applications,id'],
            'medical_schedule_id' => ['required', 'exists:medical_schedules,id'],
            'instructions' => ['required', 'array', 'min:1', 'max:50'],
            'instructions.*' => ['string', 'max:500'],
        ]);
        $application = Application::query()->findOrFail($data['application_id']);
        $this->authorize('view', $application);
        abort_unless($this->hasCertifiedSelection($application), 409, 'Only a candidate on a certified provisional selection may receive a medical invitation.');
        $schedule = DB::table('medical_schedules')->where('id', $data['medical_schedule_id'])->where('recruitment_post_id', $application->recruitment_post_id)->first();
        abort_if($schedule === null, 422, 'The medical schedule does not belong to this recruitment post.');
        $existing = DB::table('medical_invitations')->where('application_id', $application->id)->where('medical_schedule_id', $schedule->id)->first();
        if ($existing !== null) {
            return response()->json(['invitation' => $existing, 'idempotent' => true]);
        }
        $id = (string) Str::ulid();
        $verificationUrl = rtrim((string) config('app.url'), '/').'/official-artifacts/verify?reference='.rawurlencode((string) $application->reference);
        $artifact = $artifacts->create('pdf.medical-invite', ['application' => $application, 'schedule' => $schedule, 'instructions' => $data['instructions']], "artefacts/medical/{$id}.pdf", $verificationUrl);
        $notificationId = (string) Str::ulid();
        DB::transaction(function () use ($id, $application, $schedule, $data, $artifact, $request, $notificationId): void {
            DB::table('medical_invitations')->insert([
                'id' => $id,
                'application_id' => $application->id,
                'medical_schedule_id' => $schedule->id,
                'instructions' => json_encode($data['instructions'], JSON_THROW_ON_ERROR),
                'document_path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'issued_by' => $request->user()->id,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $recipient = DB::table('applicants')->where('id', $application->applicant_id)->value('user_id');
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'application_id' => $application->id,
                'event_code' => 'medical.invited',
                'channel' => 'in_portal',
                'recipient' => (string) $recipient,
                'status' => 'pending',
                'idempotency_key' => hash('sha256', "medical.invited:{$application->id}:{$schedule->id}"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
        DeliverNotificationJob::dispatch($notificationId);
        $audit->record('medical.invitation_issued', $application, actor: $request->user(), after: ['invitation_id' => $id, 'schedule_id' => $schedule->id, 'sha256' => $artifact['sha256']]);

        return response()->json(['invitation' => DB::table('medical_invitations')->where('id', $id)->first(), 'idempotent' => false], 201);
    }

    public function downloadInvitation(Request $request, string $invitation): StreamedResponse
    {
        $record = DB::table('medical_invitations')->where('id', $invitation)->firstOrFail();
        $application = Application::query()->findOrFail($record->application_id);
        $this->authorize('view', $application);

        return Storage::disk(config('erecruit.uploads.disk'))->download($record->document_path, "{$application->reference}-medical-invitation.pdf", ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, MedicalResult $medicalResult): JsonResponse
    {
        $this->authorize('view', $medicalResult);
        $restricted = $request->user()->can('viewRestricted', $medicalResult);

        return response()->json(['result' => $this->payload($medicalResult, $restricted)]);
    }

    public function approveFinalSelection(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('prisons_council_secretariat'), 403);
        $data = $request->validate([
            'selection_outcome_id' => ['required', 'exists:selection_outcomes,id'],
            'medical_result_id' => ['required', 'exists:medical_results,id'],
            'approval_reference' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'accepted'],
        ]);

        $finalSelection = DB::transaction(function () use ($data, $request): object {
            $outcome = SelectionOutcome::query()->with('selectionRun')->lockForUpdate()->findOrFail($data['selection_outcome_id']);
            $medical = MedicalResult::query()->lockForUpdate()->findOrFail($data['medical_result_id']);

            abort_unless($outcome->selectionRun->status === 'certified', 409, 'The selection run must be certified before final approval.');
            abort_unless($outcome->outcome === 'selected', 409, 'Only a selected outcome may receive final approval.');
            abort_unless($medical->application_id === $outcome->application_id, 422, 'The medical result belongs to a different application.');
            abort_unless($medical->outcome === 'Fit', 409, 'Only a Fit medical result may receive final approval.');

            $application = Application::query()->lockForUpdate()->findOrFail($outcome->application_id);
            $existing = DB::table('final_selections')->where('application_id', $application->id)->first();
            $values = [
                'selection_outcome_id' => $outcome->id,
                'medical_result_id' => $medical->id,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('final_selections')->where('id', $existing->id)->update($values);
                $id = $existing->id;
            } else {
                $id = (string) Str::ulid();
                DB::table('final_selections')->insert(['id' => $id, 'application_id' => $application->id, ...$values, 'created_at' => now()]);
            }

            if ($application->status !== 'final_selected') {
                $fromStatus = $application->status;
                $application->forceFill(['status' => 'final_selected', 'entity_version' => $application->entity_version + 1])->save();
                ApplicationStatusHistory::query()->create([
                    'application_id' => $application->id,
                    'from_status' => $fromStatus,
                    'to_status' => 'final_selected',
                    'reason' => 'Council-approved selection after Fit medical result',
                    'changed_by' => $request->user()->id,
                    'source' => 'online',
                ]);
            }

            return DB::table('final_selections')->where('id', $id)->firstOrFail();
        }, 3);

        $audit->record(
            'selection.final_approved',
            'final_selection',
            $finalSelection->id,
            actor: $request->user(),
            after: ['application_id' => $finalSelection->application_id, 'status' => 'approved'],
            approvalReference: $data['approval_reference'],
        );

        return response()->json(['final_selection' => $finalSelection], 201);
    }

    /** @return array<string, mixed> */
    private function payload(MedicalResult $result, bool $restricted): array
    {
        return [
            'id' => $result->id,
            'application_id' => $result->application_id,
            'medical_schedule_id' => $result->medical_schedule_id,
            'outcome' => $result->outcome,
            'recorded_at' => $result->recorded_at,
            'entity_version' => $result->entity_version,
            ...($restricted ? [
                'restricted_notes' => $result->restricted_notes,
                'clinical_reference' => $result->clinical_reference,
            ] : []),
        ];
    }

    private function hasCertifiedSelection(Application $application): bool
    {
        return DB::table('selection_outcomes')->join('selection_runs', 'selection_runs.id', '=', 'selection_outcomes.selection_run_id')
            ->where('selection_outcomes.application_id', $application->id)->where('selection_outcomes.outcome', 'selected')->where('selection_runs.status', 'certified')->exists()
            || DB::table('reserve_replacement_recommendations')->join('selection_runs', 'selection_runs.id', '=', 'reserve_replacement_recommendations.selection_run_id')
                ->where('reserve_replacement_recommendations.reserve_application_id', $application->id)->where('reserve_replacement_recommendations.status', 'pending_approval')->where('selection_runs.status', 'certified')->exists();
    }
}
