<?php

namespace Tests\Feature;

use App\Models\ApplicationStatusHistory;
use App\Models\CampaignStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ApplicationStatusProgressTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_applicant_status_exposes_ordered_campaign_stages_without_internal_reasons(): void
    {
        $fixture = $this->recruitmentFixture(['status' => 'eligible']);
        foreach ([
            ['application', 'Application', 1],
            ['verification', 'Verification', 2],
            ['eligibility', 'Eligibility', 3],
        ] as [$code, $name, $sequence]) {
            CampaignStage::query()->create([
                'recruitment_post_id' => $fixture['post']->id,
                'campaign_version_id' => $fixture['version']->id,
                'stage_code' => $code,
                'name' => $name,
                'sequence' => $sequence,
                'required' => true,
                'configuration' => [],
            ]);
        }
        ApplicationStatusHistory::query()->create([
            'application_id' => $fixture['application']->id,
            'to_status' => 'eligible',
            'reason' => 'Internal eligibility evidence and officer notes.',
            'changed_by' => $fixture['user']->id,
        ]);
        Sanctum::actingAs($fixture['user']);

        $this->getJson("/api/v1/applications/{$fixture['application']->id}")
            ->assertOk()
            ->assertJsonPath('data.stages.0.stage_code', 'application')
            ->assertJsonPath('data.stages.2.stage_code', 'eligibility')
            ->assertJsonPath('data.timeline.0.status', 'eligible')
            ->assertJsonMissingPath('data.timeline.0.reason');
    }
}
