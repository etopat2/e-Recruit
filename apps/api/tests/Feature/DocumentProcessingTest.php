<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_worker_source_coordinates_are_normalised_for_the_verification_overlay(): void
    {
        $fixture = $this->recruitmentFixture();
        Storage::fake('local');
        Storage::disk('local')->put('tests/national-id.pdf', 'synthetic document');
        config()->set('erecruit.document_worker.url', 'http://document-worker:8001');
        config()->set('erecruit.document_worker.token', 'synthetic-worker-token');
        config()->set('erecruit.document_worker.timeout_seconds', 10);

        $document = Document::query()->create([
            'application_id' => $fixture['application']->id,
            'document_type' => 'national_id',
            'version' => 1,
            'original_filename' => 'national-id.pdf',
            'storage_disk' => 'local',
            'original_path' => 'tests/national-id.pdf',
            'mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 18,
            'sha256' => hash('sha256', 'synthetic document'),
            'malware_status' => 'clean',
            'processing_status' => 'pending',
            'uploaded_by' => $fixture['user']->id,
            'uploaded_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response([
                'engine' => 'synthetic-ocr',
                'engine_version' => '1.0',
                'status' => 'processed',
                'pages' => [[
                    'page' => 1,
                    'width' => 1000,
                    'height' => 2000,
                    'raw_text' => 'NAME AMINA NABIRYE',
                    'mean_confidence' => 0.92,
                    'quality' => ['blur' => false],
                ]],
                'structured_fields' => [[
                    'key' => 'name',
                    'value' => 'Amina Nabirye',
                    'confidence' => 0.95,
                    'bounding_box' => [
                        'page' => 1,
                        'x' => 100,
                        'y' => 400,
                        'width' => 500,
                        'height' => 200,
                    ],
                ]],
            ]),
        ]);

        (new ProcessDocumentJob($document->id))->handle();

        $field = DB::table('extracted_fields')->where('field_key', 'name')->first();
        $box = json_decode((string) $field->bounding_polygon, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $box['page']);
        $this->assertSame(0.1, $box['x']);
        $this->assertSame(0.2, $box['y']);
        $this->assertSame(0.5, $box['width']);
        $this->assertSame(0.1, $box['height']);
        $this->assertSame('normalised', $box['coordinate_space']);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'processing_status' => 'processed']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Worker-Token', 'synthetic-worker-token'));
    }
}
