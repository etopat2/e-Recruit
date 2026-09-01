<?php

namespace Tests\Feature;

use App\Models\RecruitmentCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_materially_different_campaigns_persist_complete_versioned_configuration(): void
    {
        $this->actingAsAdministrator();
        $warder = $this->campaignPayload('UPS-WARDER-2027', 'WARDER', false);
        $cpo = $this->campaignPayload('UPS-CPO-2027', 'CPO', true);

        $first = $this->postJson('/api/v1/campaigns', $warder)
            ->assertSuccessful()
            ->assertJsonFragment(['document_type' => 'national_id'])
            ->json('data');
        $second = $this->postJson('/api/v1/campaigns', $cpo)
            ->assertSuccessful()
            ->assertJsonPath('data.posts.0.sections.professional_registrations.required', true)
            ->json('data');

        $this->assertDatabaseCount('campaign_versions', 2);
        $this->assertDatabaseCount('campaign_document_requirements', 4);
        $this->assertDatabaseCount('campaign_stages', 8);
        $this->assertDatabaseCount('assessment_definitions', 3);
        $this->assertNotSame(
            RecruitmentCampaign::query()->findOrFail($first['id'])->versions()->firstOrFail()->fingerprint,
            RecruitmentCampaign::query()->findOrFail($second['id'])->versions()->firstOrFail()->fingerprint,
        );

        $this->postJson("/api/v1/campaigns/{$first['id']}/publish", ['change_reason' => 'Approved test configuration.'])
            ->assertOk();
        $this->getJson('/api/v1/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'UPS-WARDER-2027')
            ->assertJsonCount(2, 'data.0.posts.0.document_requirements');
    }

    public function test_invalid_weights_and_file_limits_are_rejected_before_version_creation(): void
    {
        $this->actingAsAdministrator();
        $payload = $this->campaignPayload('UPS-INVALID-2027', 'INVALID', false);
        $payload['posts'][0]['assessment_definitions'][0]['weight'] = 80;
        $payload['posts'][0]['document_requirements'][0]['minimum_files'] = 2;

        $this->postJson('/api/v1/campaigns', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'posts.0.assessment_definitions',
                'posts.0.document_requirements.0.minimum_files',
            ]);
        $this->assertDatabaseCount('recruitment_campaigns', 0);
    }

    public function test_published_configuration_can_be_revised_without_overwriting_history(): void
    {
        $this->actingAsAdministrator();
        $payload = $this->campaignPayload('UPS-VERSIONED-2027', 'WARDER', false);
        $campaignId = $this->postJson('/api/v1/campaigns', $payload)->assertSuccessful()->json('data.id');
        $this->postJson("/api/v1/campaigns/{$campaignId}/publish", ['change_reason' => 'Version one approved.'])->assertOk();

        $payload['posts'][0]['assessment_definitions'][0]['pass_mark'] = 60;
        $payload['change_reason'] = 'Council changed the configured pass mark.';
        $this->putJson("/api/v1/campaigns/{$campaignId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.published_version.version', 2);

        $campaign = RecruitmentCampaign::query()->findOrFail($campaignId);
        $this->assertSame([1, 2], $campaign->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame('published', $campaign->versions()->where('version', 1)->value('status'));
        $this->assertSame('draft', $campaign->versions()->where('version', 2)->value('status'));
        $this->assertDatabaseHas('assessment_definitions', [
            'campaign_version_id' => $campaign->versions()->where('version', 2)->value('id'),
            'pass_mark' => 60,
        ]);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->create([
            'user_type' => 'hq_recruitment_administrator',
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function campaignPayload(string $code, string $postCode, bool $professional): array
    {
        $assessments = $professional
            ? [
                $this->assessment('WRITTEN', 'Written examination', 'written', 60),
                $this->assessment('INTERVIEW', 'Technical interview', 'technical', 40),
            ]
            : [$this->assessment('INTERVIEW', 'Oral interview', 'oral_interview', 100)];

        return [
            'code' => $code,
            'name' => "Synthetic {$postCode} campaign",
            'year' => 2027,
            'timezone' => 'Africa/Kampala',
            'opens_at' => '2027-01-02T08:00:00+03:00',
            'closes_at' => '2027-01-31T17:00:00+03:00',
            'hard_copy_deadline_at' => '2027-02-07T17:00:00+03:00',
            'age_cutoff_date' => '2027-01-31',
            'privacy_notice' => ['version' => "{$code}-privacy-v1", 'summary' => 'Synthetic test privacy notice.'],
            'appeals_enabled' => true,
            'posts' => [[
                'code' => $postCode,
                'name' => "Synthetic {$postCode}",
                'description' => 'Configuration test post.',
                'reference_prefix' => $postCode,
                'section_configuration' => [
                    'personal' => ['required' => true],
                    'education' => ['required' => true],
                    'professional_registrations' => ['required' => $professional],
                ],
                'eligibility_configuration' => [[
                    'id' => 'nationality',
                    'version' => 1,
                    'type' => 'allowed_values',
                    'field' => 'nationality',
                    'allowed' => ['Ugandan'],
                ]],
                'selection_configuration' => ['total_slots' => $professional ? 20 : 100, 'reserve_size' => 10],
                'lc_source_policy' => $professional ? 'residence' : 'origin_or_residence',
                'hard_copy_required' => true,
                'active' => true,
                'document_requirements' => [
                    $this->documentRequirement('national_id', 'National identification'),
                    $this->documentRequirement($professional ? 'professional_certificate' : 'academic_certificate', $professional ? 'Professional certificate' : 'Academic certificate'),
                ],
                'stages' => [
                    ['stage_code' => 'application', 'name' => 'Application', 'sequence' => 1, 'required' => true, 'configuration' => []],
                    ['stage_code' => 'verification', 'name' => 'Verification', 'sequence' => 2, 'required' => true, 'configuration' => []],
                    ['stage_code' => 'assessment', 'name' => 'Assessment', 'sequence' => 3, 'required' => true, 'configuration' => []],
                    ['stage_code' => 'selection', 'name' => 'Selection', 'sequence' => 4, 'required' => true, 'configuration' => []],
                ],
                'assessment_definitions' => $assessments,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function documentRequirement(string $type, string $label): array
    {
        return [
            'document_type' => $type,
            'label' => $label,
            'required' => true,
            'minimum_files' => 1,
            'maximum_files' => 1,
            'maximum_size_kb' => 5120,
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'hard_copy_required' => true,
            'original_required_at_interview' => true,
            'extraction_profile' => ['fields' => ['name']],
        ];
    }

    /** @return array<string, mixed> */
    private function assessment(string $code, string $name, string $type, int $weight): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'component_type' => $type,
            'maximum_mark' => 100,
            'pass_mark' => 50,
            'weight' => $weight,
            'mandatory' => true,
            'assessor_model' => 'independent',
            'aggregation_method' => 'average',
            'divergence_threshold' => 20,
            'blind_scoring' => true,
        ];
    }
}
