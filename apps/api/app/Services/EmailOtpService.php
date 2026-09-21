<?php

namespace App\Services;

use App\Mail\MfaEmailCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailOtpService
{
    public const ExpiryMinutes = 5;

    public const ResendCooldownSeconds = 60;

    public const MaximumAttempts = 5;

    public function __construct(private OutboundMailService $mail) {}

    /**
     * @return array{challenge_id: string, challenge_token: string, masked_email: string, expires_in: int, resend_available_in: int, delivery_status: string, delivery_message: string}
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
        $delivery = $this->deliver($challenge, $user, $code);

        return [...$this->challengePayload($challenge, $user, $bindingToken), ...$delivery];
    }

    /**
     * @return array{challenge_id: string, masked_email: string, expires_in: int, resend_available_in: int, user: User, delivery_status: string, delivery_message: string}
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
        $delivery = $this->deliver($challenge, $challenge->user, $code);

        return [
            'challenge_id' => $challenge->id,
            'masked_email' => $this->maskEmail($challenge->user->email),
            'expires_in' => self::ExpiryMinutes * 60,
            'resend_available_in' => self::ResendCooldownSeconds,
            'user' => $challenge->user,
            ...$delivery,
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

    /** @return array{delivery_status: string, delivery_message: string} */
    private function deliver(EmailOtpChallenge $challenge, User $user, string $code): array
    {
        $notificationId = (string) Str::ulid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'event_code' => 'auth.mfa.email_code',
            'channel' => 'email',
            'recipient' => $user->email,
            'status' => 'processing',
            'attempt_count' => 1,
            'idempotency_key' => hash('sha256', "auth.mfa.email_code:{$challenge->id}:{$challenge->last_sent_at->format('Uv')}"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId = (string) Str::ulid();
        DB::table('notification_attempts')->insert([
            'id' => $attemptId,
            'notification_id' => $notificationId,
            'attempt_number' => 1,
            'channel' => 'email',
            'provider' => (string) config('mail.default'),
            'status' => 'processing',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            // Expiring login codes must not sit behind document-processing jobs.
            $metadata = $this->mail->send($user->email, new MfaEmailCodeMail($code));
        } catch (Throwable) {
            $error = 'Unable to submit the security code to the email server. Please start again, or use an unused recovery code. Contact the administrator if this continues.';
            DB::transaction(function () use ($notificationId, $attemptId, $challenge, $error): void {
                $challenge->forceFill(['consumed_at' => now()])->save();
                DB::table('notifications')->where('id', $notificationId)->update([
                    'status' => 'failed', 'last_error' => $error, 'updated_at' => now(),
                ]);
                DB::table('notification_attempts')->where('id', $attemptId)->update([
                    'status' => 'failed', 'error_code' => 'MailSubmissionFailed',
                    'error_message' => $error, 'completed_at' => now(), 'updated_at' => now(),
                ]);
            });
            throw new HttpResponseException(response()->json(['message' => $error], 503));
        }
        DB::transaction(function () use ($notificationId, $attemptId, $metadata): void {
            DB::table('notifications')->where('id', $notificationId)->update([
                'status' => $metadata['status'],
                'provider_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('notification_attempts')->where('id', $attemptId)->update([
                'status' => $metadata['status'], 'provider_message_id' => $metadata['message_id'],
                'response_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
        });

        return [
            'delivery_status' => $metadata['status'],
            'delivery_message' => $metadata['status'] === 'captured'
                ? 'Development mode: the security code is in the local Mailpit inbox. No email was delivered to your external inbox.'
                : 'The email server accepted your security code for delivery. Check your inbox and spam folder; acceptance does not guarantee inbox delivery.',
        ];
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
