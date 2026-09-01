<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('auditor', 'hq_recruitment_administrator', 'system_administrator'), 403);
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'entity_type' => ['nullable', 'string', 'max:120'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = AuditLog::query()->orderByDesc('occurred_at');
        foreach (['action', 'entity_type', 'entity_id', 'actor_id'] as $key) {
            $query->when(isset($filters[$key]), fn ($builder) => $builder->where($key, $filters[$key]));
        }
        $query->when(isset($filters['from']), fn ($builder) => $builder->where('occurred_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($builder) => $builder->where('occurred_at', '<=', $filters['to']));

        return response()->json($query->paginate(100));
    }

    public function verify(Request $request, CanonicalJson $canonicalJson): JsonResponse
    {
        abort_unless($request->user()->hasRole('auditor', 'system_administrator'), 403);
        $previousHash = null;
        $checked = 0;
        $failures = [];
        AuditLog::query()->oldest('occurred_at')->oldest('id')->chunk(500, function ($logs) use (&$previousHash, &$checked, &$failures, $canonicalJson): void {
            foreach ($logs as $log) {
                $payload = [
                    'actor_id' => $log->actor_id,
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'before_values' => $log->before_values,
                    'after_values' => $log->after_values,
                    'reason' => $log->reason,
                    'approval_reference' => $log->approval_reference,
                    'correlation_id' => $log->correlation_id,
                    'previous_hash' => $log->previous_hash,
                    'occurred_at' => $log->occurred_at->toISOString(),
                ];
                $expected = hash('sha256', ($previousHash ?? '').$canonicalJson->encode($payload));
                if ($log->previous_hash !== $previousHash || ! hash_equals($expected, $log->entry_hash)) {
                    $failures[] = ['id' => $log->id, 'expected' => $expected, 'actual' => $log->entry_hash];
                }
                $previousHash = $log->entry_hash;
                $checked++;
            }
        });

        return response()->json(['valid' => $failures === [], 'checked' => $checked, 'failures' => $failures]);
    }

    public function reviewFlag(Request $request, string $flag, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('auditor', 'hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'status' => ['required', 'in:confirmed,dismissed,monitoring'],
            'review_outcome' => ['required', 'string', 'min:10', 'max:4000'],
        ]);
        $updated = DB::table('integrity_flags')->where('id', $flag)->where('status', 'open')->update([
            ...$data,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        abort_if($updated === 0, 404);
        $audit->record('integrity.flag_reviewed', 'integrity_flag', $flag, actor: $request->user(), after: $data, reason: $data['review_outcome']);

        return response()->json(['flag' => DB::table('integrity_flags')->where('id', $flag)->first()]);
    }
}
