<?php

namespace App\Services;

use App\Jobs\DeliverMfaRecoveryCodesJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MfaRecoveryCodeService
{
    /** @param list<string> $codes */
    public function stage(User $user, array $codes): void
    {
        try {
            Cache::forget($this->cacheKey($user));
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            Cache::put($this->cacheKey($user), Crypt::encryptString(json_encode([
                'recipient' => $user->email,
                'fingerprint' => self::fingerprint($user->mfa_recovery_codes ?? []),
                'codes' => $codes,
            ], JSON_THROW_ON_ERROR)), now()->addMinutes(15));
        } catch (Throwable $exception) {
            Log::warning('MFA recovery email staging is unavailable.', ['user_id' => $user->id, 'failure_type' => class_basename($exception)]);
        }
    }

    /** @return array{recovery_email_status: string, recovery_email_message: string} */
    public function sendAfterConfirmation(User $user): array
    {
        try {
            return Cache::lock($this->cacheKey($user).':dispatch', 60)->block(2, function () use ($user): array {
                $user = $user->fresh();
                $encrypted = Cache::get($this->cacheKey($user));
                if ($user->mfa_confirmed_at === null || ! is_string($encrypted)) {
                    return $this->unavailable('not_available');
                }

                $staged = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
                $fingerprint = self::fingerprint($user->mfa_recovery_codes ?? []);
                if ($staged['recipient'] !== $user->email || ! hash_equals($staged['fingerprint'], $fingerprint)) {
                    Cache::forget($this->cacheKey($user));

                    return $this->unavailable('not_available');
                }

                $notificationId = (string) Str::ulid();
                $idempotencyKey = hash('sha256', "mfa-recovery:{$user->id}:{$fingerprint}");
                $inserted = DB::table('notifications')->insertOrIgnore([
                    'id' => $notificationId,
                    'event_code' => 'auth.mfa.recovery_codes',
                    'channel' => 'email',
                    'recipient' => $user->email,
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted === 1) {
                    try {
                        Bus::dispatch(new DeliverMfaRecoveryCodesJob(
                            $notificationId, $user->id, $user->email, $fingerprint, $staged['codes'], now()->addDay()->timestamp,
                        ));
                    } catch (Throwable $exception) {
                        DB::table('notifications')->where('id', $notificationId)->update([
                            'status' => 'failed',
                            'last_error' => 'The recovery email could not be queued.',
                            'updated_at' => now(),
                        ]);

                        throw $exception;
                    }
                } elseif (in_array(DB::table('notifications')->where('idempotency_key', $idempotencyKey)->value('status'), ['failed', 'cancelled'], true)) {
                    return $this->unavailable('unavailable');
                }
                Cache::forget($this->cacheKey($user));

                return [
                    'recovery_email_status' => 'queued',
                    'recovery_email_message' => config('mail.delivery_mode') === 'capture'
                        ? 'Recovery codes are queued for the local test mailbox only; no external email will be delivered. Save these codes securely.'
                        : 'A separate recovery-code email has been queued to your account email address. Save these codes securely; delivery is not yet confirmed.',
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('MFA recovery email could not be queued.', ['user_id' => $user->id, 'failure_type' => class_basename($exception)]);

            return $this->unavailable('unavailable');
        }
    }

    /** @param list<string> $hashedCodes */
    public static function fingerprint(array $hashedCodes): string
    {
        return hash('sha256', json_encode($hashedCodes, JSON_THROW_ON_ERROR));
    }

    private function cacheKey(User $user): string
    {
        return 'auth:mfa:recovery-email:'.$user->id;
    }

    /** @return array{recovery_email_status: string, recovery_email_message: string} */
    private function unavailable(string $status): array
    {
        return [
            'recovery_email_status' => $status,
            'recovery_email_message' => 'MFA is active, but a recovery email could not be queued. Save the recovery codes shown here securely.',
        ];
    }
}
