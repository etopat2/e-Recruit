<?php

namespace App\Services;

use App\Contracts\MalwareScanner;
use App\Jobs\ProcessDocumentJob;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentIngestionService
{
    public function __construct(private MalwareScanner $malwareScanner) {}

    /**
     * @return array{document: Document, duplicate: bool}
     */
    public function ingest(
        Application $application,
        User $actor,
        string $documentType,
        string $localPath,
        string $originalFilename,
        ?string $clientMimeType = null,
        ?string $replacesDocumentId = null,
        ?string $uploadSessionId = null,
    ): array {
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
        $allowedMimeTypes = config('erecruit.uploads.allowed_mime_types');
        if (! is_string($detectedMimeType) || ! isset($allowedMimeTypes[$detectedMimeType])) {
            throw ValidationException::withMessages(['document' => 'The file signature does not match an approved PDF or image format.']);
        }

        $size = filesize($localPath);
        if ($size === false || $size <= 0 || $size > (int) config('erecruit.uploads.maximum_bytes')) {
            throw ValidationException::withMessages(['document' => 'The document is empty or exceeds the global upload limit.']);
        }
        $requirement = DB::table('campaign_document_requirements')
            ->where('recruitment_post_id', $application->recruitment_post_id)
            ->where('campaign_version_id', $application->campaign_version_id)
            ->where('document_type', $documentType)
            ->first();
        if ($requirement === null) {
            throw ValidationException::withMessages(['document_type' => 'This document type is not configured for the application campaign version.']);
        }
        $extensions = json_decode($requirement->allowed_extensions, true, 512, JSON_THROW_ON_ERROR);
        $extension = $allowedMimeTypes[$detectedMimeType];
        if (! in_array($extension, $extensions, true) || $size > ((int) $requirement->maximum_size_kb * 1024)) {
            throw ValidationException::withMessages(['document' => 'The file does not meet this campaign document rule.']);
        }

        $replacement = null;
        if ($replacesDocumentId !== null) {
            $replacement = Document::query()
                ->whereKey($replacesDocumentId)
                ->whereBelongsTo($application)
                ->where('document_type', $documentType)
                ->first();
            if ($replacement === null) {
                throw ValidationException::withMessages(['replaces_document_id' => 'The replaced document must be a matching document in this application.']);
            }
            $alreadyReplaced = Document::query()->where('replaces_document_id', $replacement->id)->exists();
            if ($alreadyReplaced) {
                throw ValidationException::withMessages(['replaces_document_id' => 'Only the current document version can be replaced.']);
            }
        } else {
            $currentCount = Document::query()
                ->whereBelongsTo($application)
                ->where('document_type', $documentType)
                ->whereNotIn('id', Document::query()->whereNotNull('replaces_document_id')->select('replaces_document_id'))
                ->count();
            if ($currentCount >= (int) $requirement->maximum_files) {
                throw ValidationException::withMessages(['document' => 'The configured maximum number of current files has been reached. Replace an existing file instead.']);
            }
        }

        $checksum = hash_file('sha256', $localPath);
        $existing = Document::query()
            ->whereBelongsTo($application)
            ->where('document_type', $documentType)
            ->where('sha256', $checksum)
            ->first();
        if ($existing !== null) {
            return ['document' => $existing, 'duplicate' => true];
        }

        $scan = $this->malwareScanner->scan($localPath);
        if ($scan['status'] !== 'clean') {
            throw ValidationException::withMessages(['document' => 'The file failed malware screening and was not accepted.']);
        }

        // Match Laravel's HasUlids canonical lowercase representation so the
        // object key and persisted identifier are byte-for-byte consistent.
        $documentId = strtolower((string) Str::ulid());
        $storagePath = "applications/{$application->id}/originals/{$documentId}.{$extension}";
        try {
            $document = DB::transaction(function () use ($application, $actor, $documentType, $localPath, $originalFilename, $clientMimeType, $replacesDocumentId, $uploadSessionId, $detectedMimeType, $extension, $size, $checksum, $documentId, $storagePath): Document {
                Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
                $version = ((int) Document::query()->whereBelongsTo($application)->where('document_type', $documentType)->max('version')) + 1;
                $stream = fopen($localPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('The accepted upload could not be opened.');
                }
                try {
                    if (! Storage::disk(config('erecruit.uploads.disk'))->put($storagePath, $stream)) {
                        throw new \RuntimeException('The document store did not acknowledge the write.');
                    }
                } finally {
                    fclose($stream);
                }

                return Document::query()->create([
                    'id' => $documentId,
                    'application_id' => $application->id,
                    'upload_session_id' => $uploadSessionId,
                    'replaces_document_id' => $replacesDocumentId,
                    'document_type' => $documentType,
                    'version' => $version,
                    'original_filename' => Str::limit(basename($originalFilename), 255, ''),
                    'storage_disk' => config('erecruit.uploads.disk'),
                    'original_path' => $storagePath,
                    'mime_type' => $clientMimeType ?: $detectedMimeType,
                    'detected_mime_type' => $detectedMimeType,
                    'extension' => $extension,
                    'size_bytes' => $size,
                    'sha256' => $checksum,
                    'malware_status' => 'clean',
                    'processing_status' => 'pending',
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            try {
                Storage::disk(config('erecruit.uploads.disk'))->delete($storagePath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
            report($exception);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['document' => 'The protected document store is unavailable. Try again.']);
        }

        ProcessDocumentJob::dispatch($document->id)->afterCommit();

        return ['document' => $document, 'duplicate' => false];
    }
}
