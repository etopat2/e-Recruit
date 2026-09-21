<?php

namespace App\Jobs;

use App\Mail\MfaRecoveryCodesMail;
use App\Models\User;
use App\Services\MfaRecoveryCodeService;
use App\Services\OutboundMailService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliverMfaRecoveryCodesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 300, 1800];

    /** @param list<string> $codes */
    public function __construct(
        public string $notificationId,
        public int $userId,
        public string $recipient,
        public string $fingerprint,
        public array $codes,
        public int $expiresAt,
    ) {}

    public function handle(OutboundMailService $mail): void
    {
        Cache::lock('auth:mfa:recovery-delivery:'.$this->notificationId, 60)->block(2, function () use ($mail): void {
            $notification = DB::table('notifications')->where('id', $this->notificationId)->first();
            if ($notification === null || in_array($notification->status, ['submitted', 'captured', 'delivered', 'cancelled'], true)) {
                return;
            }
            $user = User::query()->find($this->userId);
            if ($user === null || $user->status !== 'active' || $user->mfa_confirmed_at === null
                || $user->email !== $this->recipient || now()->timestamp >= $this->expiresAt
                || ! hash_equals($this->fingerprint, MfaRecoveryCodeService::fingerprint($user->mfa_recovery_codes ?? []))) {
                DB::table('notifications')->where('id', $this->notificationId)->update([
                    'status' => 'cancelled',
                    'last_error' => 'Recovery codes are no longer current or the recipient is no longer eligible.',
                    'next_attempt_at' => null,
                    'updated_at' => now(),
                ]);

                return;
            }

            $attemptId = (string) Str::ulid();
            $attemptNumber = (int) $notification->attempt_count + 1;
            DB::transaction(function () use ($attemptId, $attemptNumber): void {
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
                    'channel' => 'email',
                    'provider' => config('mail.default'),
                    'status' => 'processing',
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            try {
                $metadata = $mail->send($this->recipient, new MfaRecoveryCodesMail($this->codes));
                DB::transaction(function () use ($attemptId, $metadata): void {
                    DB::table('notification_attempts')->where('id', $attemptId)->update([
                        'status' => $metadata['status'],
                        'provider_message_id' => $metadata['message_id'] ?? null,
                        'response_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('notifications')->where('id', $this->notificationId)->update([
                        'status' => $metadata['status'],
                        'provider_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                        'last_error' => null,
                        'next_attempt_at' => null,
                        'updated_at' => now(),
                    ]);
                });
            } catch (Throwable $exception) {
                $delay = $this->backoff[min($attemptNumber - 1, count($this->backoff) - 1)];
                DB::transaction(function () use ($attemptId, $exception, $delay): void {
                    DB::table('notification_attempts')->where('id', $attemptId)->update([
                        'status' => 'failed',
                        'error_code' => class_basename($exception),
                        'error_message' => 'Recovery email submission failed.',
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('notifications')->where('id', $this->notificationId)->update([
                        'status' => 'retrying',
                        'last_error' => 'Recovery email submission failed.',
                        'next_attempt_at' => now()->addSeconds($delay),
                        'updated_at' => now(),
                    ]);
                });

                throw new RuntimeException('Recovery email submission failed; delivery remains retryable.');
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notifications')->where('id', $this->notificationId)->update([
            'status' => 'failed',
            'last_error' => 'Recovery email submission failed after retries.',
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }
}
