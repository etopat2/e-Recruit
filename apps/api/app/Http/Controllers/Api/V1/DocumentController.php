<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\Document;
use App\Services\AuditService;
use App\Services\DocumentIngestionService;
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
}
