<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nin_encrypted' => fake()->unique()->bothify('CM########??#?'),
            'nin_hash' => hash('sha256', fake()->unique()->uuid()),
            'first_name' => fake()->firstName(),
            'middle_names' => null,
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-30 years', '-18 years'),
            'sex' => fake()->randomElement(['Female', 'Male']),
            'nationality' => 'Ugandan',
            'primary_phone' => '+2567'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'preferred_language' => 'en',
        ];
    }
}
