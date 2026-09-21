<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterApplicantRequest;
use App\Models\Applicant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailOtpService;
use App\Services\MfaRecoveryCodeService;
use App\Services\TotpService;
use App\Support\Nin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthController extends Controller
{
    public function __construct(private MfaRecoveryCodeService $recoveryCodeMail) {}

    public function register(RegisterApplicantRequest $request, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        try {
            $normalisedNin = Nin::validate($data['nin']);
            $ninHash = Nin::fingerprint($normalisedNin);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['nin' => $exception->getMessage()]);
        }

        if (Applicant::query()->where('nin_hash', $ninHash)->exists()) {
            throw ValidationException::withMessages(['nin' => 'An applicant account already exists for this NIN.']);
        }

        [$user, $token] = DB::transaction(function () use ($data, $normalisedNin, $ninHash): array {
            $displayName = trim(implode(' ', array_filter([
                $data['first_name'],
                $data['middle_names'] ?? null,
                $data['last_name'],
            ])));
            $user = User::query()->create([
                'name' => $displayName,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'nin_hash' => $ninHash,
                'password' => $data['password'],
                'user_type' => 'applicant',
                'status' => 'active',
                'is_privileged' => false,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]);
            Applicant::query()->create([
                'user_id' => $user->id,
                'nin_encrypted' => $normalisedNin,
                'nin_hash' => $ninHash,
                'first_name' => $data['first_name'],
                'middle_names' => $data['middle_names'] ?? null,
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'],
                'sex' => $data['sex'],
                'nationality' => $data['nationality'],
                'primary_phone' => $data['phone'],
                'email' => $data['email'] ?? null,
            ]);

            return [$user, $user->createToken('applicant-registration', ['applicant'], now()->addHours(12))->plainTextToken];
        });

        $audit->record('auth.applicant_registered', $user, actor: $user, after: ['user_type' => 'applicant']);

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)], 201);
    }

    public function login(LoginRequest $request, TotpService $totpService, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()
            ->where('email', $data['identity'])
            ->orWhere('phone', $data['identity'])
            ->first();

        if ($user === null || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
            throw ValidationException::withMessages(['identity' => 'The supplied credentials are invalid.']);
        }

        if ($user->is_privileged) {
            $mfaMethod = $user->mfa_method ?: ($user->mfa_secret !== null ? 'authenticator' : null);
            if ($mfaMethod === null || $user->mfa_confirmed_at === null) {
                $token = $user->createToken('mfa-enrolment', ['mfa:enrol'], now()->addMinutes(15))->plainTextToken;

                return response()->json([
                    'message' => 'MFA enrolment is required before privileged access.',
                    'requires_mfa_enrolment' => true,
                    'token' => $token,
                    'user' => $this->userPayload($user),
                ]);
            }

            if (isset($data['recovery_code'])) {
                $recovery = $totpService->consumeRecoveryCode($user->mfa_recovery_codes ?? [], $data['recovery_code']);
                if ($recovery['valid']) {
                    $user->forceFill(['mfa_recovery_codes' => $recovery['remaining']])->save();

                    return $this->completeLogin($user, $data['device_name'], $audit);
                }

                throw ValidationException::withMessages(['recovery_code' => 'A valid unused recovery code is required.']);
            }

            if ($mfaMethod === 'email') {
                $challenge = $emailOtpService->issue($user, 'login');
                $audit->record('auth.mfa_email_challenge_issued', $user, actor: $user, after: ['purpose' => 'login']);

                return response()->json([
                    'message' => $challenge['delivery_message'],
                    'requires_email_otp' => true,
                    ...$challenge,
                ]);
            }

            if ($user->mfa_secret === null || ! isset($data['totp_code']) || ! $totpService->verify($user->mfa_secret, $data['totp_code'])) {
                throw ValidationException::withMessages(['totp_code' => 'A valid authenticator or recovery code is required.']);
            }
        }

        return $this->completeLogin($user, $data['device_name'], $audit);
    }

    public function verifyEmailOtp(Request $request, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'ulid'],
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $challenge = $emailOtpService->verify($data['challenge_id'], $data['challenge_token'], $data['code'], 'login');
        $user = $challenge->user;
        if (! $user->is_privileged || $user->status !== 'active' || $user->mfa_method !== 'email' || $user->mfa_confirmed_at === null) {
            throw ValidationException::withMessages(['code' => 'The email code is invalid or expired.']);
        }
        $audit->record('auth.mfa_email_verified', $user, actor: $user, after: ['purpose' => 'login']);

        return $this->completeLogin($user, $data['device_name'], $audit);
    }

    public function resendEmailOtp(Request $request, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'ulid'],
            'challenge_token' => ['required', 'string', 'size:64'],
        ]);
        $challenge = $emailOtpService->resend($data['challenge_id'], $data['challenge_token']);
        $user = $challenge['user'];
        unset($challenge['user']);
        $audit->record('auth.mfa_email_resent', $user, actor: $user, after: ['purpose' => 'email_mfa']);

        return response()->json([
            'message' => $challenge['delivery_message'],
            ...$challenge,
        ]);
    }

    private function completeLogin(User $user, string $deviceName, AuditService $audit): JsonResponse
    {
        if ($user->must_change_password) {
            $user->forceFill([
                'last_login_at' => now(),
                'mfa_confirmed_at' => $user->is_privileged ? now() : $user->mfa_confirmed_at,
            ])->save();
            $token = $user->createToken('required-password-change', ['password:change'], now()->addMinutes(15))->plainTextToken;
            $audit->record('auth.password_change_required', $user, actor: $user);

            return response()->json([
                'message' => 'A new password is required before this account can continue.',
                'requires_password_change' => true,
                'token' => $token,
                'user' => $this->userPayload($user),
            ]);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'mfa_confirmed_at' => $user->is_privileged ? now() : $user->mfa_confirmed_at,
        ])->save();
        $token = $user->createToken($deviceName, ['*'], now()->addHours(12))->plainTextToken;
        $audit->record('auth.login', $user, actor: $user, after: ['device_name' => $deviceName]);

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    public function changePassword(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);
        $user = $request->user();
        abort_if($user->is_privileged && $user->mfa_confirmed_at === null, 403, 'Complete MFA enrolment before changing this password.');
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
            'entity_version' => $user->entity_version + 1,
        ])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $token = $user->createToken('password-changed', ['*'], now()->addHours(12))->plainTextToken;
        $audit->record('auth.password_changed', $user, actor: $user);

        return response()->json([
            'message' => 'Password changed and other sessions revoked.',
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user()->loadMissing('scopes'))]);
    }

    public function logout(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $audit->record('auth.logout', $user, actor: $user);
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function enrolMfa(Request $request, TotpService $totpService, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'method' => ['required', Rule::in(['authenticator', 'email'])],
        ]);
        $user = $request->user();
        abort_if($user->mfa_confirmed_at !== null, 409, 'MFA is already active. Ask a technical administrator to authorise a reset.');

        return $this->issueMfaEnrollment($user, $data['method'], $totpService, $emailOtpService, $audit, 'auth.mfa_enrolled');
    }

    public function restartMfaEnrollment(Request $request, TotpService $totpService, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'method' => ['required', Rule::in(['authenticator', 'email'])],
        ]);
        $user = $request->user();
        abort_if($user->mfa_confirmed_at !== null, 409, 'Active MFA cannot be restarted without an authorised administrator reset.');

        return $this->issueMfaEnrollment($user, $data['method'], $totpService, $emailOtpService, $audit, 'auth.mfa_enrolment_restarted');
    }

    private function issueMfaEnrollment(
        User $user,
        string $method,
        TotpService $totpService,
        EmailOtpService $emailOtpService,
        AuditService $audit,
        string $auditAction,
    ): JsonResponse {
        if ($method === 'email' && ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['method' => 'Add a valid account email before enrolling email-code MFA.']);
        }

        $recoveryCodes = $totpService->generateRecoveryCodes();
        $secret = $method === 'authenticator' ? $totpService->generateSecret() : null;
        $user->forceFill([
            'mfa_method' => $method,
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $recoveryCodes['hashed'],
            'mfa_confirmed_at' => null,
        ])->save();
        $audit->record($auditAction, $user, actor: $user, after: ['method' => $method]);
        $this->recoveryCodeMail->stage($user, $recoveryCodes['plain']);

        if ($method === 'email') {
            return response()->json([
                'method' => 'email',
                'recovery_codes' => $recoveryCodes['plain'],
                ...$emailOtpService->issue($user, 'enrolment'),
            ]);
        }

        $emailOtpService->invalidateFor($user);

        return response()->json([
            'method' => 'authenticator',
            'provisioning_uri' => $totpService->provisioningUri($secret, $user->email ?: $user->phone),
            'recovery_codes' => $recoveryCodes['plain'],
        ]);
    }

    public function confirmMfa(Request $request, TotpService $totpService, EmailOtpService $emailOtpService, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        abort_if($user->mfa_confirmed_at !== null, 409, 'MFA is already active.');
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'challenge_id' => [Rule::requiredIf($user->mfa_method === 'email'), 'nullable', 'ulid'],
            'challenge_token' => [Rule::requiredIf($user->mfa_method === 'email'), 'nullable', 'string', 'size:64'],
        ]);
        if ($user->mfa_method === 'email') {
            $challenge = $emailOtpService->verify((string) $data['challenge_id'], (string) $data['challenge_token'], $data['code'], 'enrolment');
            if ($challenge->user_id !== $user->id) {
                throw ValidationException::withMessages(['code' => 'The email code is invalid or expired.']);
            }
        } elseif ($user->mfa_method !== 'authenticator' || $user->mfa_secret === null || ! $totpService->verify($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();
        $audit->record('auth.mfa_confirmed', $user, actor: $user, after: ['method' => $user->mfa_method]);
        $user->currentAccessToken()?->delete();
        $requiresPasswordChange = (bool) $user->must_change_password;
        $token = $user->createToken(
            $requiresPasswordChange ? 'required-password-change' : 'mfa-confirmed',
            $requiresPasswordChange ? ['password:change'] : ['*'],
            $requiresPasswordChange ? now()->addMinutes(15) : now()->addHours(12),
        )->plainTextToken;

        return response()->json([
            'message' => $requiresPasswordChange ? 'MFA is active. A new password is now required.' : 'MFA is active.',
            'token' => $token,
            'requires_password_change' => $requiresPasswordChange,
            'user' => $this->userPayload($user),
            ...$this->recoveryCodeMail->sendAfterConfirmation($user),
        ]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'status' => $user->status,
            'is_privileged' => $user->is_privileged,
            'mfa_method' => $user->mfa_method ?: ($user->mfa_secret !== null ? 'authenticator' : null),
            'mfa_confirmed' => $user->mfa_confirmed_at !== null,
            'must_change_password' => (bool) $user->must_change_password,
            'scopes' => $user->relationLoaded('scopes') ? $user->scopes : [],
        ];
    }
}
