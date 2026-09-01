<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentExtraction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public string $documentId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        if ($document->processing_status === 'processed') {
            return;
        }
        $document->forceFill(['processing_status' => 'processing'])->save();
        $contents = Storage::disk($document->storage_disk)->get($document->original_path);
        $response = Http::withHeaders(['X-Worker-Token' => (string) config('erecruit.document_worker.token')])
            ->timeout((int) config('erecruit.document_worker.timeout_seconds'))
            ->attach('document', $contents, $document->original_filename, ['Content-Type' => $document->detected_mime_type])
            ->post(rtrim((string) config('erecruit.document_worker.url'), '/').'/v1/process', [
                'job_id' => $this->documentId,
                'expected_type' => $document->document_type,
            ])
            ->throw()
            ->json();

        DB::transaction(function () use ($document, $response): void {
            $extraction = DocumentExtraction::query()->updateOrCreate(
                [
                    'document_id' => $document->id,
                    'profile_code' => $document->document_type,
                    'profile_version' => 1,
                ],
                [
                    'engine' => $response['engine'],
                    'engine_version' => $response['engine_version'],
                    'status' => $response['status'],
                    'raw_text' => collect($response['pages'])->pluck('raw_text')->implode("\n"),
                    'mean_confidence' => collect($response['pages'])->avg('mean_confidence') ?: 0,
                    'quality_indicators' => collect($response['pages'])->pluck('quality')->all(),
                ],
            );
            DB::table('extracted_fields')->where('document_extraction_id', $extraction->id)->delete();
            foreach ($response['structured_fields'] as $field) {
                DB::table('extracted_fields')->insert([
                    'id' => (string) Str::ulid(),
                    'document_extraction_id' => $extraction->id,
                    'field_key' => $field['key'],
                    'raw_value' => $field['value'],
                    'normalised_value' => mb_strtoupper(trim($field['value'])),
                    'confidence' => $field['confidence'],
                    'page_number' => data_get($field, 'bounding_box.page'),
                    'bounding_polygon' => isset($field['bounding_box']) ? json_encode($field['bounding_box'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $document->forceFill([
                'processing_status' => $response['status'] === 'processed' ? 'processed' : 'review_required',
                'quality_indicators' => collect($response['pages'])->pluck('quality')->all(),
            ])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        Document::query()->whereKey($this->documentId)->update(['processing_status' => 'failed']);
    }
}
