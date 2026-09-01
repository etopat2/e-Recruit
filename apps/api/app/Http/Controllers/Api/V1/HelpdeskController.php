<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Eligibility\EligibilityEngine;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EligibilityRun;
use App\Models\VerifiedValue;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HelpdeskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('helpdesk_tickets')->orderByDesc('created_at');
        if (! $request->user()->hasRole('helpdesk_officer', 'hq_recruitment_administrator', 'auditor')) {
            $query->where('opened_by', $request->user()->id);
        }

        return response()->json(['tickets' => $query->paginate(30)]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'application_id' => ['nullable', 'exists:applications,id'],
            'recruitment_campaign_id' => ['required', 'exists:recruitment_campaigns,id'],
            'category' => ['required', 'in:application,document,access,interview,appeal,other'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        if (isset($data['application_id'])) {
            $this->authorize('view', Application::query()->findOrFail($data['application_id']));
        }
        $id = (string) Str::ulid();
        DB::table('helpdesk_tickets')->insert([
            'id' => $id,
            ...$data,
            'opened_by' => $request->user()->id,
            'status' => 'open',
            'first_response_due_at' => now()->addHours(8),
            'resolution_due_at' => now()->addHours(48),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('helpdesk.ticket_opened', 'helpdesk_ticket', $id, actor: $request->user(), after: ['category' => $data['category']]);

        return response()->json(['ticket' => DB::table('helpdesk_tickets')->where('id', $id)->first()], 201);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $record = DB::table('helpdesk_tickets')->where('id', $ticket)->firstOrFail();
        abort_unless((int) $record->opened_by === (int) $request->user()->id || $request->user()->hasRole('helpdesk_officer', 'hq_recruitment_administrator', 'auditor'), 403);

        return response()->json([
            'ticket' => $record,
            'messages' => DB::table('helpdesk_messages')->where('helpdesk_ticket_id', $ticket)
                ->when(! $request->user()->hasRole('helpdesk_officer', 'hq_recruitment_administrator', 'auditor'), fn ($query) => $query->where('internal_only', false))
                ->orderBy('created_at')->get(),
        ]);
    }

    public function message(Request $request, string $ticket, AuditService $audit): JsonResponse
    {
        $record = DB::table('helpdesk_tickets')->where('id', $ticket)->firstOrFail();
        $isStaff = $request->user()->hasRole('helpdesk_officer', 'hq_recruitment_administrator');
        abort_unless((int) $record->opened_by === (int) $request->user()->id || $isStaff, 403);
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'attachment_references' => ['nullable', 'array', 'max:10'],
            'internal_only' => ['nullable', 'boolean'],
        ]);
        abort_if(($data['internal_only'] ?? false) && ! $isStaff, 403, 'Only helpdesk staff may create internal notes.');
        $id = (string) Str::ulid();
        DB::table('helpdesk_messages')->insert([
            'id' => $id,
            'helpdesk_ticket_id' => $ticket,
            'author_id' => $request->user()->id,
            'message' => $data['message'],
            'attachment_references' => isset($data['attachment_references']) ? json_encode($data['attachment_references'], JSON_THROW_ON_ERROR) : null,
            'internal_only' => $data['internal_only'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($isStaff && $record->status === 'open') {
            DB::table('helpdesk_tickets')->where('id', $ticket)->update(['status' => 'in_progress', 'updated_at' => now()]);
        }
        $audit->record('helpdesk.message_added', 'helpdesk_ticket', $ticket, actor: $request->user(), after: ['message_id' => $id]);

        return response()->json(['message_id' => $id], 201);
    }

    public function appeal(Request $request, Application $application, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $application);
        $application->loadMissing('post');
        abort_if(data_get($application->post->section_configuration, 'appeals.enabled') === false, 403, 'Appeals are not enabled for this recruitment post.');
        $data = $request->validate([
            'category' => ['required', 'in:eligibility,verification,assessment,selection'],
            'grounds' => ['required', 'string', 'min:20', 'max:6000'],
            'evidence_references' => ['nullable', 'array', 'max:20'],
        ]);
        $id = (string) Str::ulid();
        DB::table('appeals')->insert([
            'id' => $id,
            'application_id' => $application->id,
            'submitted_by' => $request->user()->id,
            'category' => $data['category'],
            'grounds' => $data['grounds'],
            'evidence_references' => isset($data['evidence_references']) ? json_encode($data['evidence_references'], JSON_THROW_ON_ERROR) : null,
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audit->record('appeal.submitted', $application, actor: $request->user(), after: ['appeal_id' => $id, 'category' => $data['category']]);

        return response()->json(['appeal_id' => $id, 'status' => 'submitted'], 201);
    }

    public function decideAppeal(Request $request, string $appeal, EligibilityEngine $engine, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'verification_officer'), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:20', 'max:4000'],
            'approval_reference' => ['required', 'string', 'max:255'],
        ]);
        $record = DB::table('appeals')->where('id', $appeal)->firstOrFail();
        abort_unless($record->status === 'submitted', 409, 'This appeal has already been decided.');
        abort_if((int) $record->submitted_by === (int) $request->user()->id, 409, 'The appellant cannot decide their own appeal.');
        $application = Application::query()->with('post')->findOrFail($record->application_id);
        $this->authorize('view', $application);

        $eligibilityRun = DB::transaction(function () use ($application, $record, $data, $engine, $request): ?EligibilityRun {
            $run = null;
            if ($data['decision'] === 'approve') {
                $verifiedValues = VerifiedValue::query()->whereBelongsTo($application)->where('current', true)->get()
                    ->mapWithKeys(fn (VerifiedValue $value): array => [$value->field_key => data_get($value->verified_value, 'value')])->all();
                $result = $engine->evaluate($application->post->eligibility_configuration, $verifiedValues);
                $run = EligibilityRun::query()->create([
                    'application_id' => $application->id,
                    'campaign_version_id' => $application->campaign_version_id,
                    'status' => $result['outcome'],
                    'input_snapshot' => $verifiedValues,
                    'input_fingerprint' => $result['fingerprint'],
                    'run_by' => $request->user()->id,
                    'run_at' => now(),
                ]);
                foreach ($result['results'] as $ruleResult) {
                    DB::table('eligibility_rule_results')->insert([
                        'id' => (string) Str::ulid(),
                        'eligibility_run_id' => $run->id,
                        'rule_id' => $ruleResult['rule_id'],
                        'rule_version' => $ruleResult['rule_version'],
                        'outcome' => $ruleResult['outcome'],
                        'explanation' => $ruleResult['explanation'],
                        'input_values' => json_encode(['value' => $ruleResult['input_value']], JSON_THROW_ON_ERROR),
                        'evidence_references' => json_encode($ruleResult['evidence_references'], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::table('appeals')->where('id', $record->id)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $request->user()->id,
                'decision_reason' => $data['reason'],
                'decided_at' => now(),
                'resulting_eligibility_run_id' => $run?->id,
                'updated_at' => now(),
            ]);

            return $run;
        }, 3);
        $audit->record('appeal.decided', $application, actor: $request->user(), after: ['appeal_id' => $record->id, 'decision' => $data['decision'], 'eligibility_run_id' => $eligibilityRun?->id], reason: $data['reason'], approvalReference: $data['approval_reference']);

        return response()->json(['appeal' => DB::table('appeals')->where('id', $record->id)->first(), 'eligibility_run' => $eligibilityRun]);
    }
}
