<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ApplicationSubmissionTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_application_list_uses_the_lightweight_summary_shape(): void
    {
        $fixture = $this->recruitmentFixture([
            'draft_data' => ['personal' => ['notes' => str_repeat('x', 5000)]],
        ]);
        Sanctum::actingAs($fixture['user']);

        $this->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fixture['application']->id)
            ->assertJsonPath('data.0.campaign.name', $fixture['campaign']->name)
            ->assertJsonPath('data.0.post.name', $fixture['post']->name)
            ->assertJsonMissingPath('data.0.draft_data')
            ->assertJsonMissingPath('data.0.documents')
            ->assertJsonMissingPath('data.0.timeline');
    }

    public function test_campaign_defined_draft_sections_are_retained(): void
    {
        $fixture = $this->recruitmentFixture();
        Sanctum::actingAs($fixture['user']);
        $draft = [
            'personal' => ['full_name' => 'Amina Nabirye'],
            'address' => ['district' => 'Kampala'],
            'declaration' => ['criminal_record' => false],
            'campaign_specific' => ['cadre_preference' => 'General duties'],
        ];

        $this->putJson("/api/v1/applications/{$fixture['application']->id}", [
            'draft_data' => $draft,
            'entity_version' => 1,
        ])->assertSuccessful()
            ->assertJsonPath('data.draft_data.address.district', 'Kampala')
            ->assertJsonPath('data.draft_data.declaration.criminal_record', false)
            ->assertJsonPath('data.draft_data.campaign_specific.cadre_preference', 'General duties');

        $this->assertSame($draft, $fixture['application']->fresh()->draft_data);
    }

    public function test_official_institution_metadata_is_canonicalized_when_a_draft_is_saved(): void
    {
        $fixture = $this->recruitmentFixture();
        $institution = EducationInstitution::factory()->create([
            'name' => 'Official University of Uganda',
            'source' => 'nche',
            'registration_status' => 'Chartered University',
            'active' => true,
        ]);
        Sanctum::actingAs($fixture['user']);

        $this->putJson("/api/v1/applications/{$fixture['application']->id}", [
            'draft_data' => ['education' => [[
                'level' => "Bachelor's Degree",
                'institution_id' => $institution->id,
                'institution_not_listed' => false,
                'institution' => 'Spoofed institution name',
                'institution_source' => 'spoofed-source',
                'institution_registration_status' => 'Spoofed status',
                'completion_year' => '2025',
                'result' => 'First Class Honours',
            ]]],
            'entity_version' => 1,
        ])->assertSuccessful()
            ->assertJsonPath('data.draft_data.education.0.institution', 'Official University of Uganda')
            ->assertJsonPath('data.draft_data.education.0.institution_source', 'nche')
            ->assertJsonPath('data.draft_data.education.0.institution_registration_status', 'Chartered University');
    }

    public function test_submission_requires_an_official_institution_selection_or_explicit_manual_fallback(): void
    {
        $fixture = $this->recruitmentFixture(
            ['draft_data' => ['education' => [[
                'level' => 'UCE',
                'institution' => 'Typed but not selected school',
                'institution_not_listed' => false,
                'completion_year' => '2025',
                'result' => 'Result 1 (Certificate awarded)',
            ]]]],
            ['section_configuration' => ['education' => ['required' => true]]],
        );
        Sanctum::actingAs($fixture['user']);

        $this->postJson("/api/v1/applications/{$fixture['application']->id}/submit", [
            'entity_version' => 1,
            'privacy_accepted' => true,
            'declaration_accepted' => true,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('education.0.institution_id');
    }

    public function test_submission_is_atomic_idempotent_and_locks_the_draft(): void
    {
        Storage::fake('local');
        $fixture = $this->recruitmentFixture(
            ['draft_data' => ['personal' => ['full_name' => 'Amina Nabirye']]],
            ['hard_copy_required' => false],
        );
        Sanctum::actingAs($fixture['user']);
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'entity_version' => 1,
            'privacy_accepted' => true,
            'declaration_accepted' => true,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->postJson("/api/v1/applications/{$fixture['application']->id}/submit", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'under_verification');
        $application = $fixture['application']->fresh();
        $this->assertSame('UPS/2026/WRD/000001', $application->reference);
        $this->assertNotNull($application->submission_fingerprint);
        Storage::disk('local')->assertExists($application->acknowledgement_path);

        $this->postJson("/api/v1/applications/{$application->id}/submit", [
            ...$payload,
            'entity_version' => $application->entity_version,
        ])->assertSuccessful()->assertJsonPath('data.reference', $application->reference);

        $this->putJson("/api/v1/applications/{$application->id}", [
            'draft_data' => ['personal' => ['full_name' => 'Changed']],
            'entity_version' => $application->entity_version,
        ])->assertForbidden();
    }
}
