<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecruitmentListDocumentJob;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PdfBrandingService;
use App\Services\RecruitmentListDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class RecruitmentListDocumentTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_pdf_branding_uses_the_supplied_national_emblem_as_a_separate_asset(): void
    {
        $path = resource_path('brand/uganda-national-emblem.png');
        $this->assertFileExists($path);
        $this->assertSame('184a3d8153098214037ab9df4736d5a9d8822a56a5b711e7b7363461748e2596', hash_file('sha256', $path));

        $assets = app(PdfBrandingService::class)->assets(requireNationalEmblem: true);
        $encoded = str($assets['nationalEmblemDataUri'])->after('base64,')->toString();

        $this->assertSame(file_get_contents($path), base64_decode($encoded, true));
        $this->assertArrayNotHasKey('officialHeaderDataUri', $assets);
        $this->assertNull(app(PdfBrandingService::class)->assets()['nationalEmblemDataUri']);
    }

    public function test_hq_administrator_can_queue_a_tahoma_official_list(): void
    {
        Queue::fake();
        $fixture = $this->recruitmentFixture();
        $administrator = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/v1/reports/recruitment-documents', [
            'document_type' => 'interview_shortlist',
            'recruitment_post_id' => $fixture['post']->id,
            'purpose' => 'Issue the approved interview shortlist to recruitment operations.',
        ])->assertStatus(202)->assertJsonPath('status', 'pending');

        $documentId = $response->json('document_id');
        $this->assertDatabaseHas('exports', [
            'id' => $documentId,
            'export_type' => 'interview_shortlist',
            'format' => 'pdf',
            'status' => 'pending',
        ]);
        Queue::assertPushed(GenerateRecruitmentListDocumentJob::class, fn (GenerateRecruitmentListDocumentJob $job): bool => $job->exportId === $documentId);
    }

    public function test_verification_officer_cannot_generate_an_official_list(): void
    {
        Queue::fake();
        $fixture = $this->recruitmentFixture();
        Sanctum::actingAs(User::factory()->create(['user_type' => 'verification_officer']));

        $this->postJson('/api/v1/reports/recruitment-documents', [
            'document_type' => 'final_successful_candidates',
            'recruitment_post_id' => $fixture['post']->id,
            'purpose' => 'Attempt an unauthorised official publication request.',
        ])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_worker_renders_and_stores_a_real_official_pdf_with_integrity_metadata(): void
    {
        Storage::fake('s3');
        config([
            'erecruit.uploads.disk' => 's3',
            'erecruit.pdf.tahoma_regular_path' => base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            'erecruit.pdf.tahoma_bold_path' => base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'),
        ]);
        $fixture = $this->recruitmentFixture();
        $administrator = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        foreach (['interview_shortlist', 'medical_examination_shortlist', 'final_successful_candidates'] as $documentType) {
            $exportId = (string) Str::ulid();
            DB::table('exports')->insert([
                'id' => $exportId,
                'requested_by' => $administrator->id,
                'export_type' => $documentType,
                'title' => 'Official Recruitment List',
                'format' => 'pdf',
                'scope' => json_encode([
                    'campaign_id' => $fixture['campaign']->id,
                    'recruitment_post_id' => $fixture['post']->id,
                ], JSON_THROW_ON_ERROR),
                'filters' => json_encode([], JSON_THROW_ON_ERROR),
                'masking_policy' => json_encode(['contact_details' => 'excluded'], JSON_THROW_ON_ERROR),
                'purpose' => 'Verify the official document worker output.',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            (new GenerateRecruitmentListDocumentJob($exportId))->handle(
                app(RecruitmentListDocumentService::class),
                app(AuditService::class),
            );

            $export = DB::table('exports')->where('id', $exportId)->first();
            $this->assertSame('ready', $export->status);
            $this->assertNotNull($export->completed_at);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $export->sha256);
            $this->assertSame('%PDF', substr(Storage::disk('s3')->get($export->storage_path), 0, 4));
            Storage::disk('s3')->assertExists($export->storage_path);
            $this->assertDatabaseHas('audit_logs', ['action' => 'official_list.generated', 'entity_id' => $exportId]);
        }
    }
}
