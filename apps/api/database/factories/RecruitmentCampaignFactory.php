<?php

namespace Database\Factories;

use App\Models\RecruitmentCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentCampaign>
 */
class RecruitmentCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'UPS-'.fake()->unique()->numerify('####'),
            'name' => 'Uganda Prisons Service '.fake()->jobTitle().' Recruitment',
            'year' => now()->year,
            'status' => 'published',
            'timezone' => 'Africa/Kampala',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
            'hard_copy_deadline_at' => now()->addMonths(2),
            'age_cutoff_date' => now()->addMonth()->toDateString(),
            'privacy_notice' => ['version' => 'test-v1', 'summary' => 'Test privacy notice.'],
            'appeals_enabled' => true,
            'published_at' => now(),
        ];
    }
}
