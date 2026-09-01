<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        DB::table('campaign_document_requirements')->insert([
            'id' => (string) Str::ulid(),
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'document_type' => 'national_id',
            'label' => 'Synthetic national identification',
            'required' => true,
            'minimum_files' => 1,
            'maximum_files' => 1,
            'maximum_size_kb' => 5120,
            'allowed_extensions' => json_encode(['pdf'], JSON_THROW_ON_ERROR),
            'hard_copy_required' => true,
            'original_required_at_interview' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
