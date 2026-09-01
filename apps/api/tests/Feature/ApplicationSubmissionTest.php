<?php

namespace Tests\Feature;

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
