<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\UploadSession;
use App\Services\AuditService;
use App\Services\DocumentIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class UploadSessionController extends Controller
{
    public function store(Request $request, Application $application, AuditService $audit): JsonResponse
    {
        $this->authorize('update', $application);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'original_filename' => ['required', 'string', 'max:255', 'regex:/\.(pdf|jpe?g|png)$/i'],
            'expected_bytes' => ['required', 'integer', 'min:1', 'max:'.config('erecruit.uploads.maximum_bytes')],
            'chunk_size' => ['sometimes', 'integer', 'between:65536,5242880'],
            'idempotency_key' => ['required', 'string', 'size:64'],
        ]);
        $chunkSize = min((int) ($data['chunk_size'] ?? 1048576), (int) $data['expected_bytes']);
        $expectedChunks = (int) ceil($data['expected_bytes'] / $chunkSize);
        if ($expectedChunks > 1000) {
            throw ValidationException::withMessages(['chunk_size' => 'The selected chunk size would create too many parts.']);
        }

        $existing = UploadSession::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            abort_unless($existing->application_id === $application->id && $existing->initiated_by === $request->user()->id, 409, 'The upload idempotency key is already in use.');

            return response()->json($this->sessionPayload($existing->load('document')));
        }

        $session = UploadSession::query()->create([
            'application_id' => $application->id,
            'initiated_by' => $request->user()->id,
            'document_type' => $data['document_type'],
            'original_filename' => basename($data['original_filename']),
            'expected_bytes' => $data['expected_bytes'],
            'chunk_size' => $chunkSize,
            'received_chunks' => 0,
            'idempotency_key' => $data['idempotency_key'],
            'status' => 'initiated',
            'expires_at' => now()->addHours(24),
        ]);
        $audit->record('document.upload_session_started', $session, actor: $request->user(), after: ['application_id' => $application->id, 'document_type' => $session->document_type, 'expected_bytes' => $session->expected_bytes]);

        return response()->json($this->sessionPayload($session), 201);
    }

    public function show(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $this->authorize('update', $uploadSession->application);
        abort_unless($uploadSession->initiated_by === $request->user()->id, 403);

        return response()->json($this->sessionPayload($uploadSession->load('document')));
    }

    public function chunk(Request $request, UploadSession $uploadSession, int $index): JsonResponse
    {
        $this->authorize('update', $uploadSession->application);
        abort_unless($uploadSession->initiated_by === $request->user()->id, 403);
        $maximumKilobytes = (int) ceil($uploadSession->chunk_size / 1024);
        $data = $request->validate([
            'chunk' => ['required', 'file', "max:{$maximumKilobytes}"],
            'sha256' => ['nullable', 'string', 'size:64'],
        ]);
        $expectedChunks = $this->expectedChunks($uploadSession);
        if ($index < 0 || $index >= $expectedChunks) {
            throw ValidationException::withMessages(['chunk' => 'The chunk index is outside this upload session.']);
        }
        $expectedBytes = $index === $expectedChunks - 1
            ? $uploadSession->expected_bytes - ($index * $uploadSession->chunk_size)
            : $uploadSession->chunk_size;
        if ($data['chunk']->getSize() !== $expectedBytes) {
            throw ValidationException::withMessages(['chunk' => "Chunk {$index} must contain exactly {$expectedBytes} bytes."]);
        }
        if (isset($data['sha256']) && ! hash_equals(strtolower($data['sha256']), hash_file('sha256', $data['chunk']->getRealPath()))) {
            throw ValidationException::withMessages(['sha256' => 'The chunk checksum does not match its content.']);
        }

        $duplicate = DB::transaction(function () use ($uploadSession, $data, $index): bool {
            $locked = UploadSession::query()->whereKey($uploadSession->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->expires_at->isPast(), 410, 'The upload session expired.');
            abort_unless(in_array($locked->status, ['initiated', 'uploading'], true), 409, 'This upload session no longer accepts chunks.');
            $path = $this->chunkPath($locked, $index);
            $disk = Storage::disk(config('erecruit.uploads.disk'));
            if ($disk->exists($path)) {
                $existingHash = hash('sha256', $disk->get($path));
                $incomingHash = hash_file('sha256', $data['chunk']->getRealPath());
                if (! hash_equals($existingHash, $incomingHash)) {
                    throw ValidationException::withMessages(['chunk' => 'A different chunk already exists at this index.']);
                }

                return true;
            }
            $stream = fopen($data['chunk']->getRealPath(), 'rb');
            try {
                if ($stream === false || ! $disk->put($path, $stream)) {
                    throw ValidationException::withMessages(['chunk' => 'The protected upload store is unavailable.']);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $locked->forceFill(['status' => 'uploading', 'received_chunks' => min($locked->received_chunks + 1, $this->expectedChunks($locked))])->save();

            return false;
        }, 3);

        return response()->json([
            'session_id' => $uploadSession->id,
            'chunk_index' => $index,
            'duplicate' => $duplicate,
            'received_chunks' => $this->receivedIndexes($uploadSession->fresh()),
        ]);
    }

    public function complete(Request $request, UploadSession $uploadSession, DocumentIngestionService $ingestion, AuditService $audit): JsonResponse
    {
        $this->authorize('update', $uploadSession->application);
        abort_unless($uploadSession->initiated_by === $request->user()->id, 403);
        $data = $request->validate([
            'sha256' => ['nullable', 'string', 'size:64'],
            'client_mime_type' => ['nullable', 'string', 'max:100'],
            'replaces_document_id' => ['nullable', 'exists:documents,id'],
        ]);
        $claimed = DB::transaction(function () use ($uploadSession): bool {
            $locked = UploadSession::query()->whereKey($uploadSession->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return false;
            }
            abort_if($locked->expires_at->isPast(), 410, 'The upload session expired.');
            abort_unless(in_array($locked->status, ['initiated', 'uploading'], true), 409, 'The upload is already being finalised.');
            if (count($this->receivedIndexes($locked)) !== $this->expectedChunks($locked)) {
                throw ValidationException::withMessages(['chunks' => 'Every expected chunk must be uploaded before finalisation.']);
            }
            $locked->forceFill(['status' => 'finalizing'])->save();

            return true;
        }, 3);
        if (! $claimed) {
            $document = $uploadSession->document()->first();

            return response()->json(['session' => $this->sessionPayload($uploadSession->fresh()->load('document'))['session'], 'document' => $document ? (new DocumentResource($document))->resolve($request) : null, 'duplicate' => true]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'ups-upload-finalize-');
        try {
            $destination = fopen($temporaryPath, 'wb');
            if ($destination === false) {
                throw new \RuntimeException('A safe assembly file could not be created.');
            }
            try {
                $disk = Storage::disk(config('erecruit.uploads.disk'));
                for ($index = 0; $index < $this->expectedChunks($uploadSession); $index++) {
                    $source = $disk->readStream($this->chunkPath($uploadSession, $index));
                    if ($source === false) {
                        throw ValidationException::withMessages(['chunks' => "Chunk {$index} is unavailable."]);
                    }
                    stream_copy_to_stream($source, $destination);
                    fclose($source);
                }
            } finally {
                fclose($destination);
            }
            if (filesize($temporaryPath) !== $uploadSession->expected_bytes) {
                throw ValidationException::withMessages(['chunks' => 'The assembled file size does not match the initiated upload.']);
            }
            $checksum = hash_file('sha256', $temporaryPath);
            if (isset($data['sha256']) && ! hash_equals(strtolower($data['sha256']), $checksum)) {
                throw ValidationException::withMessages(['sha256' => 'The assembled file checksum does not match.']);
            }
            $result = $ingestion->ingest(
                $uploadSession->application,
                $request->user(),
                $uploadSession->document_type,
                $temporaryPath,
                $uploadSession->original_filename,
                $data['client_mime_type'] ?? null,
                $data['replaces_document_id'] ?? null,
                $uploadSession->id,
            );
            $uploadSession->forceFill(['status' => 'completed', 'received_chunks' => $this->expectedChunks($uploadSession)])->save();
            $this->deleteChunks($uploadSession);
            $payload = (new DocumentResource($result['document']))->resolve($request);
            if (! $result['duplicate']) {
                $audit->record('document.uploaded', $result['document'], actor: $request->user(), after: $payload);
            }
            $audit->record('document.upload_session_completed', $uploadSession, actor: $request->user(), after: ['document_id' => $result['document']->id, 'sha256' => $checksum]);

            return response()->json(['session' => $this->sessionPayload($uploadSession->fresh())['session'], 'document' => $payload, 'duplicate' => $result['duplicate']], $result['duplicate'] ? 200 : 201);
        } catch (Throwable $exception) {
            $uploadSession->fresh()->forceFill(['status' => 'uploading'])->save();
            throw $exception;
        } finally {
            if (is_string($temporaryPath) && file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /** @return array<string, mixed> */
    private function sessionPayload(UploadSession $session): array
    {
        return ['session' => [
            'id' => $session->id,
            'application_id' => $session->application_id,
            'document_type' => $session->document_type,
            'original_filename' => $session->original_filename,
            'expected_bytes' => $session->expected_bytes,
            'chunk_size' => $session->chunk_size,
            'expected_chunks' => $this->expectedChunks($session),
            'received_chunks' => $this->receivedIndexes($session),
            'status' => $session->status,
            'expires_at' => $session->expires_at,
            'document' => $session->relationLoaded('document') && $session->document
                ? (new DocumentResource($session->document))->resolve(request())
                : null,
        ]];
    }

    /** @return list<int> */
    private function receivedIndexes(UploadSession $session): array
    {
        $prefix = "upload-sessions/{$session->id}/chunks";
        $indexes = collect(Storage::disk(config('erecruit.uploads.disk'))->files($prefix))
            ->map(fn (string $path): int => (int) pathinfo($path, PATHINFO_FILENAME))
            ->filter(fn (int $index): bool => $index >= 0 && $index < $this->expectedChunks($session))
            ->unique()->sort()->values()->all();

        return $indexes;
    }

    private function expectedChunks(UploadSession $session): int
    {
        return (int) ceil($session->expected_bytes / $session->chunk_size);
    }

    private function chunkPath(UploadSession $session, int $index): string
    {
        return "upload-sessions/{$session->id}/chunks/{$index}.part";
    }

    private function deleteChunks(UploadSession $session): void
    {
        $disk = Storage::disk(config('erecruit.uploads.disk'));
        $files = $disk->files("upload-sessions/{$session->id}/chunks");
        if ($files !== []) {
            $disk->delete($files);
        }
    }
}
