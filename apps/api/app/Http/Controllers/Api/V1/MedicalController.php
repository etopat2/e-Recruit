<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalResultRequest;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\MedicalResult;
use App\Models\SelectionOutcome;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
}
