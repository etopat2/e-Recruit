<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrainingReporting;
use App\Services\AuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrainingController extends Controller
{
    public function invite(Request $request, AuditService $audit): JsonResponse
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
        $id = (string) Str::ulid();
        $path = "artefacts/training/{$id}.pdf";
        $pdf = Pdf::loadView('pdf.training-invite', ['selection' => $selection, 'invite' => (object) $data])->setPaper('a4');
        Storage::disk(config('erecruit.uploads.disk'))->put($path, $pdf->output());
        DB::table('training_invites')->insert([
            'id' => $id,
            ...$data,
            'instructions' => json_encode($data['instructions'], JSON_THROW_ON_ERROR),
            'document_path' => $path,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('training.invitation_issued', 'training_invite', $id, actor: $request->user(), after: ['final_selection_id' => $data['final_selection_id']]);

        return response()->json(['invite' => DB::table('training_invites')->where('id', $id)->first()], 201);
    }

    public function report(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('training_school_officer', 'hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'training_invite_id' => ['required', 'exists:training_invites,id'],
            'status' => ['required', 'in:reported,not_reported,declined,withdrawn,admitted'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $record = TrainingReporting::query()->create([
            ...$data,
            'recorded_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);
        $audit->record('training.reporting_recorded', $record, actor: $request->user(), after: $data, reason: $data['notes'] ?? null);

        return response()->json(['reporting' => $record], 201);
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
        $nextReserve = DB::table('reserve_list_entries')->where('selection_run_id', $data['selection_run_id'])
            ->where('status', 'available')->orderBy('position')->first();
        abort_if($nextReserve === null || $nextReserve->application_id !== $data['reserve_application_id'], 422, 'The replacement must be the next available candidate in reserve order.');
        DB::transaction(function () use ($data, $nextReserve, $request): void {
            DB::table('reserve_list_entries')->where('id', $nextReserve->id)->update(['status' => 'promoted', 'updated_at' => now()]);
            DB::table('final_selections')->where('application_id', $data['replaced_application_id'])->update(['status' => 'replaced', 'updated_at' => now()]);
            $selectionOutcomeId = DB::table('selection_outcomes')->where('selection_run_id', $data['selection_run_id'])
                ->where('application_id', $data['reserve_application_id'])->value('id');
            DB::table('final_selections')->insert([
                'id' => (string) Str::ulid(),
                'application_id' => $data['reserve_application_id'],
                'selection_outcome_id' => $selectionOutcomeId,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
        $audit->record('training.reserve_promoted', 'application', $data['reserve_application_id'], actor: $request->user(), after: $data, reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['message' => 'Reserve candidate promoted in strict order.']);
    }
}
