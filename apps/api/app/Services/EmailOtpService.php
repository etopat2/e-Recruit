<?php

namespace App\Services;

use App\Jobs\DeliverNotificationJob;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailOtpService
{
    public const ExpiryMinutes = 5;

    public const ResendCooldownSeconds = 60;

    public const MaximumAttempts = 5;

    /**
     * @return array{challenge_id: string, challenge_token: string, masked_email: string, expires_in: int, resend_available_in: int}
     */
    public function issue(User $user, string $purpose): array
    {
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['method' => 'Email code requires a valid email address on the account.']);
        }

        $code = $this->generateCode();
        $bindingToken = Str::random(64);
        $challenge = DB::transaction(function () use ($user, $purpose, $code, $bindingToken): EmailOtpChallenge {
            EmailOtpChallenge::query()
                ->whereBelongsTo($user)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return EmailOtpChallenge::query()->create([
                'user_id' => $user->id,
                'method' => 'email',
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'binding_hash' => hash('sha256', $bindingToken),
                'expires_at' => now()->addMinutes(self::ExpiryMinutes),
                'attempt_count' => 0,
                'last_sent_at' => now(),
            ]);
        });
        $this->deliver($challenge, $user, $code);

        return $this->challengePayload($challenge, $user, $bindingToken);
    }

    /**
     * @return array{challenge_id: string, masked_email: string, expires_in: int, resend_available_in: int, user: User}
     */
    public function resend(string $challengeId, string $bindingToken): array
    {
        $code = $this->generateCode();
        $result = DB::transaction(function () use ($challengeId, $bindingToken, $code): array {
            $challenge = EmailOtpChallenge::query()->with('user')->lockForUpdate()->find($challengeId);
            if (! $this->isUsableAndBound($challenge, $bindingToken)) {
                return ['error' => 'The email-code session is no longer valid. Start again.', 'challenge' => null];
            }
            $availableAt = $challenge->last_sent_at->copy()->addSeconds(self::ResendCooldownSeconds);
            if (now()->lt($availableAt)) {
                return [
                    'error' => 'Wait before requesting another email code.',
                    'challenge' => null,
                    'resend_available_in' => max(1, now()->diffInSeconds($availableAt)),
                ];
            }
            $challenge->forceFill([
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::ExpiryMinutes),
                'attempt_count' => 0,
                'last_sent_at' => now(),
            ])->save();

            return ['error' => null, 'challenge' => $challenge];
        });

        if ($result['error']) {
            throw ValidationException::withMessages(['challenge' => $result['error']]);
        }

        /** @var EmailOtpChallenge $challenge */
        $challenge = $result['challenge'];
        $this->deliver($challenge, $challenge->user, $code);

        return [
            'challenge_id' => $challenge->id,
            'masked_email' => $this->maskEmail($challenge->user->email),
            'expires_in' => self::ExpiryMinutes * 60,
            'resend_available_in' => self::ResendCooldownSeconds,
            'user' => $challenge->user,
        ];
    }

    public function verify(string $challengeId, string $bindingToken, string $code, string $purpose): EmailOtpChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $bindingToken, $code, $purpose): array {
            $challenge = EmailOtpChallenge::query()->with('user')->lockForUpdate()->find($challengeId);
            if (! $this->isUsableAndBound($challenge, $bindingToken) || $challenge->purpose !== $purpose) {
                return ['error' => 'The email code is invalid or expired.', 'challenge' => null];
            }
            if ($challenge->attempt_count >= self::MaximumAttempts) {
                return ['error' => 'This email-code challenge is locked. Start again.', 'challenge' => null];
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempt_count');
                $locked = $challenge->attempt_count >= self::MaximumAttempts;

                return [
                    'error' => $locked ? 'This email-code challenge is locked. Start again.' : 'The email code is invalid or expired.',
                    'challenge' => null,
                ];
            }
            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['error' => null, 'challenge' => $challenge];
        });

        if ($result['error']) {
            throw ValidationException::withMessages(['code' => $result['error']]);
        }

        return $result['challenge'];
    }

    public function invalidateFor(User $user): void
    {
        EmailOtpChallenge::query()
            ->whereBelongsTo($user)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);
    }

    private function isUsableAndBound(?EmailOtpChallenge $challenge, string $bindingToken): bool
    {
        return $challenge !== null
            && $challenge->consumed_at === null
            && $challenge->expires_at->isFuture()
            && hash_equals($challenge->binding_hash, hash('sha256', $bindingToken));
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function deliver(EmailOtpChallenge $challenge, User $user, string $code): void
    {
        $notificationId = (string) Str::ulid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'event_code' => 'auth.mfa.email_code',
            'channel' => 'email',
            'recipient' => $user->email,
            'status' => 'pending',
            'idempotency_key' => hash('sha256', "auth.mfa.email_code:{$challenge->id}:{$challenge->last_sent_at->format('Uv')}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DeliverNotificationJob::dispatch($notificationId, $code)->afterCommit();
    }

    /**
     * @return array{challenge_id: string, challenge_token: string, masked_email: string, expires_in: int, resend_available_in: int}
     */
    private function challengePayload(EmailOtpChallenge $challenge, User $user, string $bindingToken): array
    {
        return [
            'challenge_id' => $challenge->id,
            'challenge_token' => $bindingToken,
            'masked_email' => $this->maskEmail($user->email),
            'expires_in' => self::ExpiryMinutes * 60,
            'resend_available_in' => self::ResendCooldownSeconds,
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('•', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
