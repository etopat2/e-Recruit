<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterApplicantRequest;
use App\Models\Applicant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TotpService;
use App\Support\Nin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthController extends Controller
{
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

    public function login(LoginRequest $request, TotpService $totpService, AuditService $audit): JsonResponse
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
            if ($user->mfa_secret === null) {
                $token = $user->createToken('mfa-enrolment', ['mfa:enrol'], now()->addMinutes(15))->plainTextToken;

                return response()->json([
                    'message' => 'MFA enrolment is required before privileged access.',
                    'requires_mfa_enrolment' => true,
                    'token' => $token,
                    'user' => $this->userPayload($user),
                ]);
            }

            $mfaValid = isset($data['totp_code']) && $totpService->verify($user->mfa_secret, $data['totp_code']);
            if (! $mfaValid && isset($data['recovery_code'])) {
                $recovery = $totpService->consumeRecoveryCode($user->mfa_recovery_codes ?? [], $data['recovery_code']);
                $mfaValid = $recovery['valid'];
                if ($mfaValid) {
                    $user->forceFill(['mfa_recovery_codes' => $recovery['remaining']])->save();
                }
            }
            if (! $mfaValid) {
                throw ValidationException::withMessages(['totp_code' => 'A valid MFA or recovery code is required.']);
            }
        }

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
        $token = $user->createToken($data['device_name'], ['*'], now()->addHours(12))->plainTextToken;
        $audit->record('auth.login', $user, actor: $user, after: ['device_name' => $data['device_name']]);

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

    public function enrolMfa(Request $request, TotpService $totpService, AuditService $audit): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'current_password']]);
        unset($validated);
        $user = $request->user();
        $secret = $totpService->generateSecret();
        $recoveryCodes = $totpService->generateRecoveryCodes();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $recoveryCodes['hashed'],
            'mfa_confirmed_at' => null,
        ])->save();
        $audit->record('auth.mfa_enrolled', $user, actor: $user);

        return response()->json([
            'provisioning_uri' => $totpService->provisioningUri($secret, $user->email ?: $user->phone),
            'recovery_codes' => $recoveryCodes['plain'],
        ]);
    }

    public function confirmMfa(Request $request, TotpService $totpService, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if ($user->mfa_secret === null || ! $totpService->verify($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();
        $audit->record('auth.mfa_confirmed', $user, actor: $user);
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
            'mfa_confirmed' => $user->mfa_confirmed_at !== null,
            'must_change_password' => (bool) $user->must_change_password,
            'scopes' => $user->relationLoaded('scopes') ? $user->scopes : [],
        ];
    }
}
