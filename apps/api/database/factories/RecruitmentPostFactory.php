<?php

namespace Database\Factories;

use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentPost>
 */
class RecruitmentPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruitment_campaign_id' => RecruitmentCampaign::factory(),
            'code' => fake()->unique()->bothify('POST-##??'),
            'name' => fake()->jobTitle(),
            'description' => fake()->sentence(),
            'reference_prefix' => fake()->unique()->lexify('???'),
            'section_configuration' => [
                'personal' => ['required' => true],
                'education' => ['required' => true],
                'declaration' => ['required' => true],
            ],
            'eligibility_configuration' => [],
            'selection_configuration' => ['total_slots' => 10],
            'lc_source_policy' => 'origin_or_residence',
            'hard_copy_required' => true,
            'active' => true,
        ];
    }
}
