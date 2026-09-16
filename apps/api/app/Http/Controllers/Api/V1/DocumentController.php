<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\Document;
use App\Services\AuditService;
use App\Services\DocumentIngestionService;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function store(Request $request, Application $application, DocumentIngestionService $ingestion, AuditService $audit): JsonResponse
    {
        $this->authorize('update', $application);
        $maximumKilobytes = (int) ceil(config('erecruit.uploads.maximum_bytes') / 1024);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'document' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimes:pdf,jpg,jpeg,png', "max:{$maximumKilobytes}"],
            'replaces_document_id' => ['nullable', 'exists:documents,id'],
        ]);
        $upload = $request->file('document');
        $result = $ingestion->ingest(
            $application,
            $request->user(),
            $data['document_type'],
            $upload->getRealPath(),
            $upload->getClientOriginalName(),
            $upload->getClientMimeType(),
            $data['replaces_document_id'] ?? null,
        );
        $payload = (new DocumentResource($result['document']))->resolve($request);
        if (! $result['duplicate']) {
            $audit->record('document.uploaded', $result['document'], actor: $request->user(), after: $payload);
        }

        return response()->json(['document' => $payload, 'duplicate' => $result['duplicate']], $result['duplicate'] ? 200 : 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);
        $document->load('extraction');

        return response()->json(['document' => [
            ...(new DocumentResource($document))->resolve(request()),
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

    public function preview(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->original_path), 404);

        $size = (int) $document->size_bytes;
        $rangeHeader = $request->header('Range');
        [$start, $end] = $this->requestedRange($rangeHeader, $size);
        $isPartial = $rangeHeader !== null && $rangeHeader !== '';
        $length = $size === 0 ? 0 : $end - $start + 1;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'Content-Disposition' => 'inline; filename="'.addcslashes($document->original_filename, '"\\').'"',
            'Content-Length' => (string) $length,
            'Content-Type' => $document->detected_mime_type,
            'ETag' => '"'.$document->sha256.'"',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($isPartial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($disk, $document, $start, $length): void {
            if ($document->storage_disk === 's3') {
                $this->streamS3Range($document->original_path, $start, $length);

                return;
            }

            $stream = $disk->readStream($document->original_path);
            abort_if($stream === false, 404);
            $this->advanceStream($stream, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(64 * 1024, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
        }, $isPartial ? 206 : 200, $headers);
    }

    /** @return array{0: int, 1: int} */
    private function requestedRange(?string $header, int $size): array
    {
        if ($header === null || $header === '' || $size === 0) {
            return [0, max(0, $size - 1)];
        }

        abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) === 1, 416, 'Only a single byte range is supported.');
        abort_if($matches[1] === '' && $matches[2] === '', 416, 'The requested byte range is invalid.');

        if ($matches[1] === '') {
            $suffixLength = min((int) $matches[2], $size);

            return [$size - $suffixLength, $size - 1];
        }

        $start = (int) $matches[1];
        abort_if($start >= $size, 416, 'The requested byte range is outside the document.');
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        abort_if($end < $start, 416, 'The requested byte range is invalid.');

        return [$start, $end];
    }

    /** @param resource $stream */
    private function advanceStream($stream, int $offset): void
    {
        if ($offset === 0) {
            return;
        }
        $metadata = stream_get_meta_data($stream);
        if (($metadata['seekable'] ?? false) && fseek($stream, $offset) === 0) {
            return;
        }

        $remaining = $offset;
        while ($remaining > 0 && ! feof($stream)) {
            $discarded = fread($stream, min(64 * 1024, $remaining));
            abort_if($discarded === false || $discarded === '', 500, 'Unable to seek within the protected document.');
            $remaining -= strlen($discarded);
        }
    }

    private function streamS3Range(string $path, int $start, int $length): void
    {
        $configuration = config('filesystems.disks.s3');
        $options = [
            'version' => 'latest',
            'region' => $configuration['region'],
            'endpoint' => $configuration['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($configuration['use_path_style_endpoint'] ?? false),
        ];
        if (($configuration['key'] ?? null) !== null && ($configuration['secret'] ?? null) !== null) {
            $options['credentials'] = ['key' => $configuration['key'], 'secret' => $configuration['secret']];
        }
        $prefix = trim((string) ($configuration['root'] ?? ''), '/');
        $key = $prefix === '' ? $path : "{$prefix}/{$path}";
        $result = (new S3Client(array_filter($options, fn (mixed $value): bool => $value !== null)))->getObject([
            'Bucket' => $configuration['bucket'],
            'Key' => $key,
            'Range' => 'bytes='.$start.'-'.($start + $length - 1),
        ]);
        $body = $result['Body'];
        while (! $body->eof()) {
            echo $body->read(64 * 1024);
        }
    }
}
