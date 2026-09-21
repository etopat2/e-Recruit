<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationJob;
use App\Mail\MfaEmailCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Services\OutboundMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_mfa_delivery_uses_the_security_code_mailable(): void
    {
        Mail::fake();
        config()->set(['mail.default' => 'smtp', 'mail.delivery_mode' => 'capture']);
        $user = User::factory()->create(['email' => 'officer@example.test']);
        EmailOtpChallenge::query()->create([
            'user_id' => $user->id, 'method' => 'email', 'purpose' => 'login',
            'code_hash' => Hash::make('123456'), 'binding_hash' => hash('sha256', 'synthetic'),
            'expires_at' => now()->addMinutes(5), 'last_sent_at' => now(),
        ]);
        $emailId = $this->notification('email', 'officer@example.test', 'auth.mfa.email_code');

        (new DeliverNotificationJob($emailId, '123456'))->handle(new OutboundMailService);

        Mail::assertSent(MfaEmailCodeMail::class, function ($mail): bool {
            return $mail->hasTo('officer@example.test')
                && $mail->code === '123456';
        });
        $this->assertDatabaseHas('notifications', ['id' => $emailId, 'status' => 'captured', 'delivered_at' => null]);
    }

    public function test_portal_and_sms_delivery_create_per_attempt_evidence(): void
    {
        $user = User::factory()->create();
        $portalId = $this->notification('in_portal', (string) $user->id);
        (new DeliverNotificationJob($portalId))->handle(new OutboundMailService);
        $this->assertDatabaseHas('notifications', ['id' => $portalId, 'status' => 'delivered', 'attempt_count' => 1]);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $portalId, 'attempt_number' => 1, 'provider' => 'internal', 'status' => 'delivered']);

        config()->set('services.sms', ['driver' => 'approved-gateway', 'base_url' => 'https://sms.example.test/messages', 'token' => 'synthetic-token', 'timeout' => 5]);
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'synthetic-message-1'], 202)]);
        $smsId = $this->notification('sms', '+256700000099');
        (new DeliverNotificationJob($smsId))->handle(new OutboundMailService);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/messages' && $request['recipient'] === '+256700000099');
        $this->assertDatabaseHas('notifications', ['id' => $smsId, 'status' => 'delivered', 'attempt_count' => 1]);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $smsId, 'provider_message_id' => 'synthetic-message-1', 'status' => 'delivered']);
    }

    public function test_unconfigured_channel_stays_retryable_and_push_subscription_is_encrypted(): void
    {
        config()->set('services.sms', ['driver' => 'null', 'base_url' => null, 'token' => null, 'timeout' => 5]);
        $smsId = $this->notification('sms', '+256700000098');
        try {
            (new DeliverNotificationJob($smsId))->handle(new OutboundMailService);
            $this->fail('An unconfigured SMS gateway should throw for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }
        $this->assertDatabaseHas('notifications', ['id' => $smsId, 'status' => 'retrying', 'attempt_count' => 1]);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $smsId, 'status' => 'failed', 'error_code' => 'RuntimeException']);

        config()->set('services.push', ['driver' => 'approved-push', 'base_url' => 'https://push.example.test/send', 'token' => 'synthetic-token', 'public_key' => 'BEl6SyntheticPublicKey', 'timeout' => 5]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $endpoint = 'https://push.example.test/subscriptions/synthetic-browser-endpoint';
        $subscriptionId = $this->postJson('/api/v1/notifications/push/subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'synthetic-public-key', 'auth' => 'synthetic-auth-token'],
            'content_encoding' => 'aes128gcm',
        ])->assertCreated()->json('subscription_id');
        $raw = DB::table('push_subscriptions')->where('id', $subscriptionId)->first();
        $this->assertNotSame($endpoint, $raw->endpoint_encrypted);
        $this->assertStringNotContainsString('synthetic-public-key', $raw->public_key_encrypted);

        Http::fake(['push.example.test/*' => Http::response(['message_id' => 'push-message-1'], 202)]);
        $pushId = $this->notification('push', (string) $user->id);
        (new DeliverNotificationJob($pushId))->handle(new OutboundMailService);
        $this->assertDatabaseHas('notifications', ['id' => $pushId, 'status' => 'delivered']);
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $pushId, 'status' => 'delivered', 'provider' => 'approved-push']);
    }

    public function test_stale_queued_email_code_is_cancelled_without_sending(): void
    {
        Mail::fake();
        $id = $this->notification('email', 'officer@example.test', 'auth.mfa.email_code');

        (new DeliverNotificationJob($id, '123456'))->handle(new OutboundMailService);

        Mail::assertNothingSent();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'cancelled', 'attempt_count' => 0]);
    }

    private function notification(string $channel, string $recipient, string $eventCode = 'test.synthetic'): string
    {
        $id = (string) Str::ulid();
        DB::table('notifications')->insert([
            'id' => $id,
            'event_code' => $eventCode,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => 'pending',
            'idempotency_key' => hash('sha256', "{$channel}:{$recipient}:{$id}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
