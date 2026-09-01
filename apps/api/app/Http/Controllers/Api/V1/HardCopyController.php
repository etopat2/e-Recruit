<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HardCopyController extends Controller
{
    public function store(Request $request, Application $application, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $application);
        abort_unless($request->user()->hasRole('hard_copy_receiving_officer', 'centre_coordinator', 'regional_recruitment_officer'), 403);
        $data = $request->validate([
            'receiving_office' => ['required', 'string', 'max:255'],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.document_type' => ['required', 'string', 'max:80'],
            'items.*.status' => ['required', 'in:Match,Different Document,Missing,Unreadable,Original Required at Interview'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.discrepancy_evidence' => ['nullable', 'array'],
        ]);

        $receipt = DB::transaction(function () use ($data, $application, $request): object {
            $receiptId = (string) Str::ulid();
            $receiptNumber = 'HC/'.now()->format('Ymd').'/'.mb_strtoupper(Str::random(8));
            DB::table('hard_copy_receipts')->insert([
                'id' => $receiptId,
                'application_id' => $application->id,
                'receiving_office' => $data['receiving_office'],
                'received_by' => $request->user()->id,
                'received_at' => $data['received_at'],
                'receipt_number' => $receiptNumber,
                'status' => collect($data['items'])->contains(fn (array $item): bool => $item['status'] !== 'Match') ? 'query_required' : 'received',
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                DB::table('physical_document_checks')->insert([
                    'id' => (string) Str::ulid(),
                    'hard_copy_receipt_id' => $receiptId,
                    'document_type' => $item['document_type'],
                    'status' => $item['status'],
                    'notes' => $item['notes'] ?? null,
                    'discrepancy_evidence' => isset($item['discrepancy_evidence']) ? json_encode($item['discrepancy_evidence'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $previousStatus = $application->status;
            $application->forceFill(['status' => 'hard_copies_received', 'entity_version' => $application->entity_version + 1])->save();
            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => $previousStatus,
                'to_status' => 'hard_copies_received',
                'changed_by' => $request->user()->id,
                'source' => 'hard_copy_reception',
            ]);

            return DB::table('hard_copy_receipts')->where('id', $receiptId)->first();
        }, 3);
        $audit->record('hard_copy.received', $application, actor: $request->user(), after: ['receipt_number' => $receipt->receipt_number]);

        return response()->json(['receipt' => $receipt], 201);
    }
}
