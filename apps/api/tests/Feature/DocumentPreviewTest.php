<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class DocumentPreviewTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_authorised_preview_supports_single_byte_ranges_for_fast_pdf_rendering(): void
    {
        Storage::fake('local');
        $fixture = $this->recruitmentFixture();
        $officer = User::factory()->create(['user_type' => 'verification_officer']);
        $officer->scopes()->create(['scope_type' => 'campaign', 'scope_id' => $fixture['campaign']->id, 'allowed_tasks' => ['decision:verification']]);
        Storage::disk('local')->put('tests/protected.pdf', '0123456789');
        $document = Document::query()->create([
            'application_id' => $fixture['application']->id,
            'document_type' => 'national_id',
            'version' => 1,
            'original_filename' => 'protected.pdf',
            'storage_disk' => 'local',
            'original_path' => 'tests/protected.pdf',
            'mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', '0123456789'),
            'malware_status' => 'clean',
            'processing_status' => 'completed',
            'uploaded_by' => $fixture['user']->id,
            'uploaded_at' => now(),
        ]);
        Sanctum::actingAs($officer);

        $this->withHeader('Range', 'bytes=2-5')
            ->get("/api/v1/documents/{$document->id}/preview")
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Content-Disposition', 'inline; filename="protected.pdf"')
            ->assertStreamedContent('2345');
    }
}
