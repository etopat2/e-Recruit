<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_register_and_nin_is_not_stored_in_plaintext(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Amina',
            'last_name' => 'Nabirye',
            'nin' => 'CM900000000001',
            'phone' => '+256700100200',
            'email' => 'amina@example.test',
            'date_of_birth' => '2001-05-12',
            'sex' => 'Female',
            'nationality' => 'Ugandan',
            'password' => 'SecurePass2026',
            'password_confirmation' => 'SecurePass2026',
        ]);

        $response->assertCreated()->assertJsonPath('user.user_type', 'applicant')->assertJsonStructure(['token']);
        $this->assertDatabaseHas('users', ['email' => 'amina@example.test', 'user_type' => 'applicant']);
        $this->assertNotSame('CM900000000001', (string) $this->getConnection()->table('applicants')->value('nin_encrypted'));
    }

    public function test_invalid_credentials_do_not_disclose_account_existence(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identity' => 'nobody@example.test',
            'password' => 'WrongPassword1',
            'device_name' => 'Browser',
        ])->assertUnprocessable()->assertJsonValidationErrors('identity');
    }
}
