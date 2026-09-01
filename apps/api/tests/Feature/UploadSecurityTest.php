<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_malware_test_signature_is_rejected_before_storage(): void
    {
        Storage::fake('local');
        $fixture = $this->recruitmentFixture();
        Sanctum::actingAs($fixture['user']);
        $file = UploadedFile::fake()->createWithContent(
            'identity.pdf',
            "%PDF-1.4\nX5O!P%@AP[4\\PZX54(P^)7CC)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!\$H+H*\n%%EOF",
        );

        $this->postJson("/api/v1/applications/{$fixture['application']->id}/documents", [
            'document_type' => 'national_id',
            'document' => $file,
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
