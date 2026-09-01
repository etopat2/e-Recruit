<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MalwareScanner;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Application;
use App\Models\Document;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function store(Request $request, Application $application, MalwareScanner $malwareScanner, AuditService $audit): JsonResponse
    {
        $this->authorize('update', $application);
        $maximumKilobytes = (int) ceil(config('erecruit.uploads.maximum_bytes') / 1024);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'document' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimes:pdf,jpg,jpeg,png', "max:{$maximumKilobytes}"],
            'replaces_document_id' => ['nullable', 'exists:documents,id'],
        ]);
        $upload = $request->file('document');
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        $allowedMimeTypes = config('erecruit.uploads.allowed_mime_types');
        if (! is_string($detectedMimeType) || ! isset($allowedMimeTypes[$detectedMimeType])) {
            throw ValidationException::withMessages(['document' => 'The file signature does not match an approved PDF or image format.']);
        }

        $requirement = DB::table('campaign_document_requirements')
            ->where('recruitment_post_id', $application->recruitment_post_id)
            ->where('document_type', $data['document_type'])
            ->orderByDesc('created_at')
            ->first();
        if ($requirement !== null) {
            $extensions = json_decode($requirement->allowed_extensions, true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($allowedMimeTypes[$detectedMimeType], $extensions, true)
                || $upload->getSize() > ((int) $requirement->maximum_size_kb * 1024)) {
                throw ValidationException::withMessages(['document' => 'The file does not meet this campaign document rule.']);
            }
        }

        $checksum = hash_file('sha256', $upload->getRealPath());
        $existing = Document::query()
            ->whereBelongsTo($application)
            ->where('document_type', $data['document_type'])
            ->where('sha256', $checksum)
            ->first();
        if ($existing !== null) {
            return response()->json(['document' => $this->documentPayload($existing), 'duplicate' => true]);
        }

        $scan = $malwareScanner->scan($upload->getRealPath());
        if ($scan['status'] !== 'clean') {
            throw ValidationException::withMessages(['document' => 'The file failed malware screening and was not accepted.']);
        }

        $document = DB::transaction(function () use ($application, $data, $upload, $detectedMimeType, $allowedMimeTypes, $checksum, $request): Document {
            $version = ((int) Document::query()
                ->whereBelongsTo($application)
                ->where('document_type', $data['document_type'])
                ->lockForUpdate()
                ->max('version')) + 1;
            $documentId = (string) Str::ulid();
            $extension = $allowedMimeTypes[$detectedMimeType];
            $path = "applications/{$application->id}/originals/{$documentId}.{$extension}";
            $stream = fopen($upload->getRealPath(), 'rb');
            if ($stream === false || ! Storage::disk(config('erecruit.uploads.disk'))->put($path, $stream)) {
                throw ValidationException::withMessages(['document' => 'The protected document store is unavailable. Try again.']);
            }
            fclose($stream);

            return Document::query()->create([
                'id' => $documentId,
                'application_id' => $application->id,
                'replaces_document_id' => $data['replaces_document_id'] ?? null,
                'document_type' => $data['document_type'],
                'version' => $version,
                'original_filename' => $upload->getClientOriginalName(),
                'storage_disk' => config('erecruit.uploads.disk'),
                'original_path' => $path,
                'mime_type' => (string) $upload->getClientMimeType(),
                'detected_mime_type' => $detectedMimeType,
                'extension' => $extension,
                'size_bytes' => $upload->getSize(),
                'sha256' => $checksum,
                'malware_status' => 'clean',
                'processing_status' => 'pending',
                'uploaded_by' => $request->user()->id,
                'uploaded_at' => now(),
            ]);
        }, 3);

        ProcessDocumentJob::dispatch($document->id)->afterCommit();
        $audit->record('document.uploaded', $document, actor: $request->user(), after: $this->documentPayload($document));

        return response()->json(['document' => $this->documentPayload($document), 'duplicate' => false], 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);
        $document->load('extraction');

        return response()->json(['document' => [
            ...$this->documentPayload($document),
            'extractions' => $document->extraction,
        ]]);
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        $disk = Storage::disk($document->storage_disk);

        return response()->streamDownload(function () use ($disk, $document): void {
            $stream = $disk->readStream($document->original_path);
            abort_if($stream === false, 404);
            fpassthru($stream);
            fclose($stream);
        }, $document->original_filename, [
            'Content-Type' => $document->detected_mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'application_id' => $document->application_id,
            'document_type' => $document->document_type,
            'version' => $document->version,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->detected_mime_type,
            'size_bytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'malware_status' => $document->malware_status,
            'processing_status' => $document->processing_status,
            'quality_indicators' => $document->quality_indicators,
        ];
    }
}
