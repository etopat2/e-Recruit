<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
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
}
