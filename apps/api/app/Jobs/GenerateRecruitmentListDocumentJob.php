<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AuditService;
use App\Services\RecruitmentListDocumentService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GenerateRecruitmentListDocumentJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 180, 600];

    public function __construct(public string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function handle(RecruitmentListDocumentService $documents, AuditService $audit): void
    {
        $export = DB::table('exports')->where('id', $this->exportId)->first();
        if ($export === null || $export->status === 'ready') {
            return;
        }
        DB::table('exports')->where('id', $this->exportId)->update(['status' => 'processing', 'failure_reason' => null, 'updated_at' => now()]);
        try {
            $result = $documents->generate($export);
            DB::table('exports')->where('id', $this->exportId)->update([
                'title' => $result['title'],
                'status' => 'ready',
                'storage_path' => $result['path'],
                'sha256' => $result['sha256'],
                'filters' => json_encode(['row_count' => $result['row_count']], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'expires_at' => now()->addDays(30),
                'updated_at' => now(),
            ]);
            $actor = User::query()->find($export->requested_by);
            $audit->record('official_list.generated', 'export', $this->exportId, actor: $actor, after: [
                'type' => $export->export_type,
                'row_count' => $result['row_count'],
                'sha256' => $result['sha256'],
            ], reason: $export->purpose);
        } catch (Throwable $exception) {
            DB::table('exports')->where('id', $this->exportId)->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 2000, ''),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('exports')->where('id', $this->exportId)->update([
            'status' => 'failed',
            'failure_reason' => Str::limit((string) $exception?->getMessage(), 2000, ''),
            'updated_at' => now(),
        ]);
    }
}
