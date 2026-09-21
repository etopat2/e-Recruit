<?php

namespace Tests\Feature;

use App\Jobs\DeliverMfaRecoveryCodesJob;
use App\Mail\MfaEmailCodeMail;
use App\Mail\MfaRecoveryCodesMail;
use App\Models\User;
use App\Services\MfaRecoveryCodeService;
use App\Services\OutboundMailService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MfaRecoveryCodeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const Password = 'SyntheticSecurePass2026'; // gitleaks:allow -- synthetic test credential

    public function test_authenticator_confirmation_queues_the_same_codes_separately_and_only_once(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $user = $this->pendingUser();
        Sanctum::actingAs($user, ['mfa:enrol']);
        $enrolment = $this->postJson('/api/v1/auth/mfa/enrol', ['password' => self::Password, 'method' => 'authenticator'])
            ->assertOk();
        $codes = $enrolment->json('recovery_codes');
        $encrypted = Cache::get('auth:mfa:recovery-email:'.$user->id);
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString($codes[0], $encrypted);
        Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);
        $this->mock(TotpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturnTrue();
        });

        $confirmed = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])
            ->assertOk()->assertJsonPath('recovery_email_status', 'queued')
            ->assertJsonPath('user.mfa_confirmed', true)->assertJsonStructure(['token', 'recovery_email_message']);

        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, fn (DeliverMfaRecoveryCodesJob $job): bool => $job->codes === $codes && $job->recipient === $user->email);
        $this->assertDatabaseHas('notifications', ['event_code' => 'auth.mfa.recovery_codes', 'recipient' => $user->email, 'status' => 'pending']);
        $this->assertNull(Cache::get('auth:mfa:recovery-email:'.$user->id));
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
        $this->withToken($confirmed->json('token'))->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertConflict();
        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, 1);
    }

    public function test_email_confirmation_queues_a_separate_recovery_email_after_the_security_code(): void
    {
        Mail::fake();
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        config(['mail.default' => 'smtp', 'mail.delivery_mode' => 'capture']);
        $user = $this->pendingUser();
        Sanctum::actingAs($user, ['mfa:enrol']);
        $enrolment = $this->postJson('/api/v1/auth/mfa/enrol', ['password' => self::Password, 'method' => 'email'])->assertOk();
        $code = Mail::sent(MfaEmailCodeMail::class)->sole()->code;
        Mail::assertSent(MfaEmailCodeMail::class, 1);
        Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);

        $this->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $code,
            'challenge_id' => $enrolment->json('challenge_id'),
            'challenge_token' => $enrolment->json('challenge_token'),
        ])->assertOk()->assertJsonPath('recovery_email_status', 'queued');

        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, fn (DeliverMfaRecoveryCodesJob $job): bool => $job->codes === $enrolment->json('recovery_codes') && $job->recipient === $user->email);
        Mail::assertNotSent(MfaRecoveryCodesMail::class);
    }

    public function test_returns_422_without_sending_recovery_codes_for_a_failed_confirmation(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $user = $this->pendingUser();
        Sanctum::actingAs($user, ['mfa:enrol']);
        $this->postJson('/api/v1/auth/mfa/enrol', ['password' => self::Password, 'method' => 'authenticator'])->assertOk();
        $this->mock(TotpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturnFalse();
        });

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'The authenticator code is invalid.');

        Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);
        $this->assertDatabaseMissing('notifications', ['event_code' => 'auth.mfa.recovery_codes']);
        $this->assertNull($user->fresh()->mfa_confirmed_at);
    }

    public function test_restart_replaces_the_staged_codes_before_confirmation(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $user = $this->pendingUser();
        Sanctum::actingAs($user, ['mfa:enrol']);
        $first = $this->postJson('/api/v1/auth/mfa/enrol', ['password' => self::Password, 'method' => 'authenticator'])->assertOk();
        $second = $this->postJson('/api/v1/auth/mfa/restart', ['password' => self::Password, 'method' => 'authenticator'])->assertOk();
        $this->mock(TotpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturnTrue();
        });

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertOk()->assertJsonPath('recovery_email_status', 'queued');

        $this->assertNotSame($first->json('recovery_codes'), $second->json('recovery_codes'));
        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, fn (DeliverMfaRecoveryCodesJob $job): bool => $job->codes === $second->json('recovery_codes'));
        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, 1);
    }

    public function test_recovery_codes_are_encrypted_in_the_persisted_queue_payload(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->stagedConfirmedUser();

        $result = app(MfaRecoveryCodeService::class)->sendAfterConfirmation($user);

        $this->assertSame('queued', $result['recovery_email_status']);
        $payload = DB::table('jobs')->sole()->payload;
        $this->assertStringNotContainsString('SYNTH-REC01', $payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertDatabaseCount('jobs', 1);
        $notification = (array) DB::table('notifications')->where('event_code', 'auth.mfa.recovery_codes')->sole();
        $this->assertStringNotContainsString('SYNTH-REC01', json_encode($notification, JSON_THROW_ON_ERROR));
    }

    public function test_recovery_job_sends_the_mail_and_records_submission_without_claiming_delivery(): void
    {
        Mail::fake();
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        config(['mail.default' => 'smtp', 'mail.delivery_mode' => 'capture']);
        $job = $this->queuedRecoveryJob();

        $job->handle(app(OutboundMailService::class));
        $job->handle(app(OutboundMailService::class));

        Mail::assertSent(MfaRecoveryCodesMail::class, fn (MfaRecoveryCodesMail $mail): bool => $mail->hasTo($job->recipient) && $mail->codes === ['SYNTH-REC01']);
        Mail::assertSent(MfaRecoveryCodesMail::class, 1);
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'captured', 'delivered_at' => null, 'attempt_count' => 1]);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $job->notificationId, 'status' => 'captured']);
    }

    public function test_recovery_job_discards_codes_after_an_mfa_reset(): void
    {
        Mail::fake();
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $job = $this->queuedRecoveryJob();
        User::query()->findOrFail($job->userId)->forceFill(['mfa_confirmed_at' => null, 'mfa_recovery_codes' => null])->save();

        $job->handle(app(OutboundMailService::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'cancelled']);
    }

    public function test_recovery_job_does_not_send_to_a_changed_email_address(): void
    {
        Mail::fake();
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $job = $this->queuedRecoveryJob();
        User::query()->findOrFail($job->userId)->forceFill(['email' => 'changed@example.test'])->save();

        $job->handle(app(OutboundMailService::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'cancelled']);
    }

    public function test_expired_staging_does_not_enqueue_an_email(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $this->freezeTime();
        $user = $this->stagedConfirmedUser();
        $this->travel(16)->minutes();

        $result = app(MfaRecoveryCodeService::class)->sendAfterConfirmation($user);

        $this->assertSame('not_available', $result['recovery_email_status']);
        Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);
        $this->assertDatabaseMissing('notifications', ['event_code' => 'auth.mfa.recovery_codes']);
    }

    public function test_expired_queued_recovery_email_is_cancelled(): void
    {
        Mail::fake();
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $this->freezeTime();
        $job = $this->queuedRecoveryJob();
        $this->travel(25)->hours();

        $job->handle(app(OutboundMailService::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'cancelled', 'attempt_count' => 0]);
    }

    public function test_smtp_failure_retries_recovery_mail_without_exposing_secrets(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        config(['mail.default' => 'smtp', 'mail.delivery_mode' => 'capture']);
        $job = $this->queuedRecoveryJob();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Secret message SYNTH-REC01'));

        try {
            $job->handle(app(OutboundMailService::class));
            $this->fail('SMTP failure must remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Recovery email submission failed; delivery remains retryable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'retrying', 'attempt_count' => 1]);
        $attempt = DB::table('notification_attempts')->where('notification_id', $job->notificationId)->sole();
        $this->assertSame('Recovery email submission failed.', $attempt->error_message);
        $job->failed(new RuntimeException('Secret message SYNTH-REC01'));
        $this->assertDatabaseHas('notifications', ['id' => $job->notificationId, 'status' => 'failed', 'next_attempt_at' => null]);
    }

    public function test_previously_failed_dispatch_is_not_reported_as_queued(): void
    {
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $user = $this->stagedConfirmedUser();
        DB::table('notifications')->insert([
            'id' => (string) Str::ulid(),
            'event_code' => 'auth.mfa.recovery_codes', 'channel' => 'email', 'recipient' => $user->email,
            'status' => 'failed', 'idempotency_key' => hash('sha256', 'mfa-recovery:'.$user->id.':'.MfaRecoveryCodeService::fingerprint($user->mfa_recovery_codes)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(MfaRecoveryCodeService::class)->sendAfterConfirmation($user);

        $this->assertSame('unavailable', $result['recovery_email_status']);
        Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);
    }

    public function test_queue_failure_does_not_break_successful_mfa_confirmation(): void
    {
        $user = $this->stagedConfirmedUser();
        $user->forceFill(['mfa_confirmed_at' => null])->save();
        Sanctum::actingAs($user, ['mfa:enrol']);
        $this->mock(TotpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturnTrue();
        });
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic queue unavailable'));

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456'])->assertOk()
            ->assertJsonPath('user.mfa_confirmed', true)
            ->assertJsonPath('recovery_email_status', 'unavailable')->assertJsonStructure(['token']);

        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
        $this->assertDatabaseHas('notifications', ['event_code' => 'auth.mfa.recovery_codes', 'status' => 'failed']);
    }

    public function test_recovery_email_contains_codes_in_html_and_text_and_escapes_html(): void
    {
        $mail = new MfaRecoveryCodesMail(['SYNTH-REC01', '<script>test</script>']);

        $mail->assertSeeInHtml('SYNTH-REC01');
        $mail->assertSeeInText('SYNTH-REC01');
        $mail->assertSeeInHtml('&lt;script&gt;test&lt;/script&gt;', false);
        $mail->assertDontSeeInHtml('<script>test</script>', false);
        $mail->assertSeeInText('not the six-digit email login code');
    }

    private function pendingUser(): User
    {
        return User::factory()->create([
            'email' => 'mfa-recovery@example.test',
            'password' => self::Password,
            'is_privileged' => true,
            'user_type' => 'system_administrator',
            'must_change_password' => false,
            'mfa_confirmed_at' => null,
        ]);
    }

    private function stagedConfirmedUser(): User
    {
        $user = $this->pendingUser();
        $user->forceFill([
            'mfa_method' => 'authenticator',
            'mfa_secret' => 'SYNTHETICMFASECRET',
            'mfa_recovery_codes' => [hash('sha256', 'SYNTH-REC01')],
            'mfa_confirmed_at' => now(),
        ])->save();
        app(MfaRecoveryCodeService::class)->stage($user, ['SYNTH-REC01']);

        return $user;
    }

    private function queuedRecoveryJob(): DeliverMfaRecoveryCodesJob
    {
        $user = $this->stagedConfirmedUser();
        $result = app(MfaRecoveryCodeService::class)->sendAfterConfirmation($user);
        $this->assertSame('queued', $result['recovery_email_status']);
        $job = Queue::pushed(DeliverMfaRecoveryCodesJob::class)->sole();
        Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, 1);

        return $job;
    }
}
