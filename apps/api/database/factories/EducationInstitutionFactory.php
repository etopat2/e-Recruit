<?php

namespace Database\Factories;

use App\Models\EducationInstitution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EducationInstitution>
 */
class EducationInstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Institute';

        return [
            'source' => 'nche',
            'source_id' => fake()->unique()->numerify('NCHE-#####'),
            'name' => $name,
            'normalized_name' => Str::upper(Str::ascii($name)),
            'institution_type' => 'University',
            'district' => fake()->randomElement(['Kampala', 'Wakiso', 'Gulu', 'Mbarara']),
            'registration_number' => null,
            'registration_status' => 'Registered',
            'operational_status' => 'Active',
            'qualification_levels' => ["Bachelor's Degree", "Master's Degree"],
            'source_url' => 'https://unche.or.ug/institutions/',
            'source_payload' => ['fixture' => true],
            'last_verified_at' => now(),
            'active' => true,
        ];
    }
}
