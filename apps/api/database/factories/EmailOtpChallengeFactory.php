<?php

namespace Database\Factories;

use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<EmailOtpChallenge>
 */
class EmailOtpChallengeFactory extends Factory
{
    protected $model = EmailOtpChallenge::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'method' => 'email',
            'purpose' => 'login',
            'code_hash' => Hash::make('123456'),
            'binding_hash' => hash('sha256', 'synthetic-binding-token'),
            'expires_at' => now()->addMinutes(5),
            'attempt_count' => 0,
            'consumed_at' => null,
            'last_sent_at' => now(),
        ];
    }
}
