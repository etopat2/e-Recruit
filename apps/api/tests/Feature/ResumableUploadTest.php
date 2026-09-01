<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ResumableUploadTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_chunk_retry_and_finalise_are_idempotent_and_create_one_protected_document(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('erecruit.uploads.disk', 'local');
        $fixture = $this->recruitmentFixture();
        $this->documentRequirement($fixture['post']->id, $fixture['version']->id);
        Sanctum::actingAs($fixture['user']);
        $bytes = $this->syntheticPng();
        $idempotencyKey = hash('sha256', 'synthetic-resumable-upload');
        $initiation = [
            'document_type' => 'national_id',
            'original_filename' => 'synthetic-id.png',
            'expected_bytes' => strlen($bytes),
            'chunk_size' => 65536,
            'idempotency_key' => $idempotencyKey,
        ];

        $session = $this->postJson("/api/v1/applications/{$fixture['application']->id}/upload-sessions", $initiation)
            ->assertCreated()
            ->assertJsonPath('session.expected_chunks', 1)
            ->json('session');
        $this->postJson("/api/v1/applications/{$fixture['application']->id}/upload-sessions", $initiation)
            ->assertOk()
            ->assertJsonPath('session.id', $session['id']);

        $chunkPayload = [
            'chunk' => UploadedFile::fake()->createWithContent('0.part', $bytes),
            'sha256' => hash('sha256', $bytes),
        ];
        $this->put("/api/v1/upload-sessions/{$session['id']}/chunks/0", $chunkPayload, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('received_chunks.0', 0);
        $this->put("/api/v1/upload-sessions/{$session['id']}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('0.part', $bytes),
            'sha256' => hash('sha256', $bytes),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $completed = $this->postJson("/api/v1/upload-sessions/{$session['id']}/complete", [
            'sha256' => hash('sha256', $bytes),
            'client_mime_type' => 'image/png',
        ])->assertCreated()
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('duplicate', false)
            ->json();
        $this->assertDatabaseHas('documents', [
            'id' => $completed['document']['id'],
            'upload_session_id' => $session['id'],
            'sha256' => hash('sha256', $bytes),
        ]);
        $expectedPath = DB::table('documents')->where('id', $completed['document']['id'])->value('original_path');
        $this->assertTrue(
            Storage::disk('local')->exists($expectedPath),
            'Expected '.$expectedPath.'; stored files: '.json_encode(Storage::disk('local')->allFiles(), JSON_THROW_ON_ERROR),
        );
        $this->assertSame([], Storage::disk('local')->files("upload-sessions/{$session['id']}/chunks"));
        Queue::assertPushed(ProcessDocumentJob::class, 1);

        $this->postJson("/api/v1/upload-sessions/{$session['id']}/complete", ['sha256' => hash('sha256', $bytes)])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('document.id', $completed['document']['id']);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_bad_chunk_checksum_and_incomplete_finalisation_leave_no_document(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('erecruit.uploads.disk', 'local');
        $fixture = $this->recruitmentFixture();
        $this->documentRequirement($fixture['post']->id, $fixture['version']->id);
        Sanctum::actingAs($fixture['user']);
        $bytes = $this->syntheticPng();
        $sessionId = $this->postJson("/api/v1/applications/{$fixture['application']->id}/upload-sessions", [
            'document_type' => 'national_id',
            'original_filename' => 'synthetic-id.png',
            'expected_bytes' => strlen($bytes),
            'chunk_size' => 65536,
            'idempotency_key' => hash('sha256', 'synthetic-bad-chunk'),
        ])->assertCreated()->json('session.id');

        $this->put("/api/v1/upload-sessions/{$sessionId}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('0.part', $bytes),
            'sha256' => str_repeat('0', 64),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sha256');
        $this->postJson("/api/v1/upload-sessions/{$sessionId}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chunks');
        $this->assertDatabaseCount('documents', 0);
    }

    private function documentRequirement(string $postId, string $versionId): void
    {
        DB::table('campaign_document_requirements')->insert([
            'id' => (string) Str::ulid(),
            'recruitment_post_id' => $postId,
            'campaign_version_id' => $versionId,
            'document_type' => 'national_id',
            'label' => 'Synthetic national identification',
            'required' => true,
            'minimum_files' => 1,
            'maximum_files' => 1,
            'maximum_size_kb' => 5120,
            'allowed_extensions' => json_encode(['png'], JSON_THROW_ON_ERROR),
            'hard_copy_required' => true,
            'original_required_at_interview' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syntheticPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    }
}
