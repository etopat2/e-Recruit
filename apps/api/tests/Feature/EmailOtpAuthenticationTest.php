<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationJob;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailOtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const Password = 'PermanentSecurePass2026'; // gitleaks:allow -- synthetic test credential

    public function test_privileged_user_can_enrol_email_mfa_and_challenge_is_purpose_bound(): void
    {
        Queue::fake();
        $user = $this->privilegedUser(['mfa_method' => null, 'mfa_confirmed_at' => null]);
        $login = $this->login($user)->assertJsonPath('requires_mfa_enrolment', true);

        $enrolment = $this->withToken($login->json('token'))->postJson('/api/v1/auth/mfa/enrol', [
            'password' => self::Password,
            'method' => 'email',
        ])->assertOk()
            ->assertJsonPath('method', 'email')
            ->assertJsonStructure(['challenge_id', 'challenge_token', 'masked_email', 'expires_in', 'resend_available_in', 'recovery_codes']);

        $code = $this->queuedCode();
        $challenge = EmailOtpChallenge::query()->findOrFail($enrolment->json('challenge_id'));
        $this->assertSame('enrolment', $challenge->purpose);
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertDatabaseHas('notifications', ['event_code' => 'auth.mfa.email_code', 'recipient' => $user->email]);

        $this->postJson('/api/v1/auth/mfa/email/verify', [
            'challenge_id' => $challenge->id,
            'challenge_token' => $enrolment->json('challenge_token'),
            'code' => $code,
            'device_name' => 'Wrong-purpose browser',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->withToken($login->json('token'))->postJson('/api/v1/auth/mfa/confirm', [
            'challenge_id' => $challenge->id,
            'challenge_token' => $enrolment->json('challenge_token'),
            'code' => $code,
        ])->assertOk()->assertJsonPath('user.mfa_method', 'email');

        $freshUser = $user->fresh();
        $this->assertSame('email', $freshUser->mfa_method);
        $this->assertNull($freshUser->mfa_secret);
        $this->assertNotNull($freshUser->mfa_confirmed_at);
        $this->assertNotEmpty($freshUser->mfa_recovery_codes);
    }

    public function test_enrolled_email_code_is_single_use_and_completes_login(): void
    {
        Queue::fake();
        $user = $this->privilegedUser([
            'mfa_method' => 'email',
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => [hash('sha256', 'SYNTH-REC01')],
        ]);

        $challengeResponse = $this->login($user)
            ->assertOk()
            ->assertJsonPath('requires_email_otp', true)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');
        $code = $this->queuedCode();
        $payload = [
            'challenge_id' => $challengeResponse->json('challenge_id'),
            'challenge_token' => $challengeResponse->json('challenge_token'),
            'code' => $code,
            'device_name' => 'Email MFA browser',
        ];

        $this->postJson('/api/v1/auth/mfa/email/verify', $payload)
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.mfa_method', 'email');
        $this->postJson('/api/v1/auth/mfa/email/verify', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_email_code_resend_cooldown_and_five_attempt_lockout_are_enforced(): void
    {
        Queue::fake();
        $user = $this->privilegedUser(['mfa_method' => 'email', 'mfa_confirmed_at' => now()]);
        $challengeResponse = $this->login($user)->assertJsonPath('requires_email_otp', true);
        $binding = [
            'challenge_id' => $challengeResponse->json('challenge_id'),
            'challenge_token' => $challengeResponse->json('challenge_token'),
        ];

        $this->postJson('/api/v1/auth/mfa/email/resend', $binding)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('challenge');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/mfa/email/resend', $binding)
            ->assertOk()
            ->assertJsonPath('resend_available_in', 60);

        $challenge = EmailOtpChallenge::query()->findOrFail($binding['challenge_id']);
        $invalidCode = Hash::check('000000', $challenge->code_hash) ? '999999' : '000000';
        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"]);
            $response = $this->postJson('/api/v1/auth/mfa/email/verify', [
                ...$binding,
                'code' => $invalidCode,
                'device_name' => 'Locked browser',
            ])->assertUnprocessable()->assertJsonValidationErrors('code');
            if ($attempt === 5) {
                $response->assertJsonPath('errors.code.0', 'This email-code challenge is locked. Start again.');
            }
        }

        $this->assertSame(5, EmailOtpChallenge::query()->findOrFail($binding['challenge_id'])->attempt_count);
    }

    private function privilegedUser(array $attributes): User
    {
        return User::factory()->create([
            'email' => 'email-mfa@example.test',
            'password' => self::Password,
            'user_type' => 'system_administrator',
            'is_privileged' => true,
            'must_change_password' => false,
            ...$attributes,
        ]);
    }

    private function login(User $user)
    {
        return $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => self::Password,
            'device_name' => 'Synthetic browser',
        ]);
    }

    private function queuedCode(): string
    {
        $code = null;
        Queue::assertPushed(DeliverNotificationJob::class, function (DeliverNotificationJob $job) use (&$code): bool {
            $code = $job->oneTimeCode;

            return $job instanceof ShouldBeEncrypted && $code !== null;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        return (string) $code;
    }
}
