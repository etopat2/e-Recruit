<?php

namespace Database\Factories;

use App\Models\MedicalFacility;
use App\Models\PrisonRegion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalFacility>
 */
class MedicalFacilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('MF##'),
            'name' => fake()->city().' Prison Health Centre',
            'location' => fake()->city(),
            'host_administrative_unit_id' => null,
            'prison_region_id' => fn (): string => PrisonRegion::query()->firstOrCreate(
                ['code' => 'TEST-MEDICAL'],
                ['name' => 'Test Medical Region', 'active' => true],
            )->id,
            'region_attribution' => fake()->word(),
            'referral_rule' => 'Nearest listed facility',
            'source_metadata' => ['source' => 'test fixture'],
            'active' => true,
        ];
    }
}
