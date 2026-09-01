<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            'recruitment_campaign_id' => RecruitmentCampaign::factory(),
            'recruitment_post_id' => RecruitmentPost::factory(),
            'status' => Application::StatusDraft,
            'active' => true,
            'draft_data' => [
                'personal' => ['full_name' => fake()->name()],
                'education' => [['level' => 'UCE']],
                'declaration' => ['accepted' => true],
            ],
            'entity_version' => 1,
        ];
    }
}
