<?php

namespace App\Console\Commands;

use App\Models\UploadSession;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExpiredUploadSessions extends Command
{
    protected $signature = 'uploads:prune-expired';

    protected $description = 'Expire incomplete resumable uploads and remove their temporary chunks';

    public function handle(AuditService $audit): int
    {
        $count = 0;
        UploadSession::query()
            ->where('expires_at', '<', now())
            ->whereNotIn('status', ['completed', 'expired'])
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$count, $audit): void {
                foreach ($sessions as $session) {
                    $disk = Storage::disk(config('erecruit.uploads.disk'));
                    $files = $disk->files("upload-sessions/{$session->id}/chunks");
                    if ($files !== []) {
                        $disk->delete($files);
                    }
                    $session->forceFill(['status' => 'expired'])->save();
                    $audit->record('document.upload_session_expired', $session, after: ['temporary_chunks_removed' => count($files)]);
                    $count++;
                }
            });
        $this->info("Expired {$count} upload session(s).");

        return self::SUCCESS;
    }
}
