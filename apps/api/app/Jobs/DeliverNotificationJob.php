<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliverNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(public string $notificationId) {}

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function handle(): void
    {
        $notification = DB::table('notifications')->where('id', $this->notificationId)->first();
        if ($notification === null || $notification->status === 'delivered') {
            return;
        }
        $attemptNumber = (int) $notification->attempt_count + 1;
        $attemptId = (string) Str::ulid();
        DB::transaction(function () use ($attemptId, $attemptNumber, $notification): void {
            DB::table('notifications')->where('id', $this->notificationId)->update([
                'attempt_count' => $attemptNumber,
                'status' => 'processing',
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('notification_attempts')->insert([
                'id' => $attemptId,
                'notification_id' => $this->notificationId,
                'attempt_number' => $attemptNumber,
                'channel' => $notification->channel,
                'provider' => $this->provider($notification->channel),
                'status' => 'processing',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $metadata = match ($notification->channel) {
                'in_portal' => ['provider' => 'internal'],
                'email' => $this->sendEmail($notification),
                'sms' => $this->sendSms($notification),
                'push' => $this->sendPush($notification),
                default => throw new RuntimeException("Notification channel [{$notification->channel}] is not supported."),
            };
            DB::transaction(function () use ($attemptId, $metadata): void {
                DB::table('notification_attempts')->where('id', $attemptId)->update([
                    'status' => 'delivered',
                    'provider_message_id' => $metadata['provider_message_id'] ?? null,
                    'response_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('notifications')->where('id', $this->notificationId)->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'provider_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $delay = $this->backoff[min($attemptNumber - 1, count($this->backoff) - 1)] ?? 1800;
            DB::transaction(function () use ($attemptId, $exception, $delay): void {
                DB::table('notification_attempts')->where('id', $attemptId)->update([
                    'status' => 'failed',
                    'error_code' => class_basename($exception),
                    'error_message' => Str::limit($exception->getMessage(), 4000, ''),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('notifications')->where('id', $this->notificationId)->update([
                    'status' => 'retrying',
                    'last_error' => Str::limit($exception->getMessage(), 4000, ''),
                    'next_attempt_at' => now()->addSeconds($delay),
                    'updated_at' => now(),
                ]);
            });

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notifications')->where('id', $this->notificationId)->update([
            'status' => 'failed',
            'last_error' => Str::limit((string) $exception?->getMessage(), 4000, ''),
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function sendEmail(object $notification): array
    {
        Mail::raw('A new UPS e-Recruit update is available in your secure applicant portal.', function ($message) use ($notification): void {
            $message->to($notification->recipient)->subject('UPS e-Recruit update');
        });

        return ['provider' => config('mail.default'), 'accepted' => true];
    }

    /** @return array<string, mixed> */
    private function sendSms(object $notification): array
    {
        $baseUrl = (string) config('services.sms.base_url');
        $token = (string) config('services.sms.token');
        throw_if($baseUrl === '' || $token === '', RuntimeException::class, 'SMS gateway is not configured; the notification remains retryable.');
        $response = Http::withToken($token)->acceptJson()->timeout((int) config('services.sms.timeout', 15))->post($baseUrl, [
            'recipient' => $notification->recipient,
            'message' => 'A UPS e-Recruit update is available. Sign in to the official portal for details.',
            'event_code' => $notification->event_code,
            'idempotency_key' => $notification->idempotency_key,
        ]);
        throw_unless($response->successful(), RuntimeException::class, "SMS gateway returned HTTP {$response->status()}.");

        return ['provider' => config('services.sms.driver'), 'provider_message_id' => $response->json('message_id') ?? $response->json('id'), 'http_status' => $response->status()];
    }

    /** @return array<string, mixed> */
    private function sendPush(object $notification): array
    {
        $baseUrl = (string) config('services.push.base_url');
        $token = (string) config('services.push.token');
        throw_if($baseUrl === '' || $token === '', RuntimeException::class, 'Push gateway is not configured; the notification remains retryable.');
        $subscriptions = PushSubscription::query()->where('user_id', (int) $notification->recipient)->whereNull('revoked_at')->get();
        throw_if($subscriptions->isEmpty(), RuntimeException::class, 'No active push subscription exists for this recipient.');
        $messageIds = [];
        foreach ($subscriptions as $subscription) {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('services.push.timeout', 15))->post($baseUrl, [
                'subscription' => [
                    'endpoint' => $subscription->endpoint_encrypted,
                    'keys' => ['p256dh' => $subscription->public_key_encrypted, 'auth' => $subscription->auth_token_encrypted],
                    'content_encoding' => $subscription->content_encoding,
                ],
                'notification' => ['title' => 'UPS e-Recruit update', 'body' => 'Sign in to view your latest recruitment update.', 'url' => '/dashboard'],
                'idempotency_key' => "{$notification->idempotency_key}:{$subscription->id}",
            ]);
            throw_unless($response->successful(), RuntimeException::class, "Push gateway returned HTTP {$response->status()}.");
            $messageIds[] = $response->json('message_id') ?? $response->json('id');
            $subscription->forceFill(['last_used_at' => now()])->save();
        }

        return ['provider' => config('services.push.driver'), 'provider_message_ids' => array_values(array_filter($messageIds)), 'subscription_count' => $subscriptions->count()];
    }

    private function provider(string $channel): ?string
    {
        return match ($channel) {
            'in_portal' => 'internal',
            'email' => (string) config('mail.default'),
            'sms' => (string) config('services.sms.driver'),
            'push' => (string) config('services.push.driver'),
            default => null,
        };
    }
}
