<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnicalUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'role' => ['nullable', 'exists:roles,code'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $users = User::query()
            ->with(['roles:id,code,name', 'scopes'])
            ->when(filled($data['search'] ?? null), function ($query) use ($data): void {
                $search = '%'.trim($data['search']).'%';
                $query->where(fn ($users) => $users
                    ->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->when(filled($data['status'] ?? null), fn ($query) => $query->where('status', $data['status']))
            ->when(filled($data['role'] ?? null), fn ($query) => $query->where(function ($users) use ($data): void {
                $users->where('user_type', $data['role'])
                    ->orWhereHas('roles', fn ($roles) => $roles->where('code', $data['role']));
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user): array => $this->payload($user))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->orderBy('name')->get()->map(fn (Role $role): array => [
            'code' => $role->code,
            'name' => $role->name,
            'is_decision_role' => $role->is_decision_role,
            'is_privileged' => $this->isPrivilegedRole($role->code),
        ])]);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{9,15}$/', 'unique:users,phone'],
            'role_code' => ['required', 'exists:roles,code', Rule::notIn(['applicant'])],
        ]);
        $role = Role::query()->where('code', $data['role_code'])->firstOrFail();
        $temporaryPassword = Str::password(20);
        $user = DB::transaction(function () use ($data, $role, $request, $temporaryPassword): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'email_verified_at' => now(),
                'password' => $temporaryPassword,
                'user_type' => $role->code,
                'status' => 'active',
                'is_privileged' => $this->isPrivilegedRole($role->code),
                'must_change_password' => true,
            ]);
            $user->roles()->sync([$role->id => ['assigned_by' => $request->user()->id]]);
            if ($role->code === 'system_administrator') {
                UserScope::query()->create([
                    'user_id' => $user->id,
                    'scope_type' => 'national',
                    'scope_id' => null,
                    'allowed_tasks' => ['system:users', 'view:operations'],
                    'assigned_by' => $request->user()->id,
                ]);
            }

            return $user->load(['roles:id,code,name', 'scopes']);
        }, 3);
        $audit->record('user.created', $user, actor: $request->user(), after: [
            'email' => $user->email,
            'role' => $role->code,
            'must_change_password' => true,
        ]);

        return response()->json([
            'user' => $this->payload($user),
            'temporary_password' => $temporaryPassword,
            'message' => 'Account created. The temporary password is shown once.',
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, User $user, AuditService $audit): JsonResponse
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{9,15}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'disabled'])],
            'role_code' => ['sometimes', 'required', 'exists:roles,code'],
            'entity_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $nextRoleCode = $data['role_code'] ?? $user->user_type;
        if ($user->user_type === 'applicant' && $nextRoleCode !== 'applicant') {
            throw ValidationException::withMessages(['role_code' => 'Applicant identities cannot be converted into staff accounts.']);
        }
        if ($user->user_type !== 'applicant' && $nextRoleCode === 'applicant') {
            throw ValidationException::withMessages(['role_code' => 'Staff identities cannot be converted into applicant accounts.']);
        }
        $nextStatus = $data['status'] ?? $user->status;
        $role = Role::query()->where('code', $nextRoleCode)->firstOrFail();

        [$updated, $before] = DB::transaction(function () use ($data, $user, $role, $nextStatus, $request): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertVersion($locked, (int) $data['entity_version']);
            $this->assertAdministratorContinuity($request->user(), $locked, $role->code, $nextStatus);
            $before = $this->payload($locked->load(['roles:id,code,name', 'scopes']));
            $roleChanged = $locked->user_type !== $role->code;
            $statusChanged = $locked->status !== $nextStatus;
            $locked->forceFill([
                'name' => $data['name'] ?? $locked->name,
                'email' => isset($data['email']) ? mb_strtolower($data['email']) : $locked->email,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : $locked->phone,
                'status' => $nextStatus,
                'user_type' => $role->code,
                'is_privileged' => $this->isPrivilegedRole($role->code),
                'entity_version' => $locked->entity_version + 1,
            ])->save();
            if ($locked->user_type !== 'applicant') {
                $locked->roles()->sync([$role->id => ['assigned_by' => $request->user()->id]]);
            }
            if ($roleChanged || $statusChanged) {
                $this->deleteSessions($locked);
            }

            return [$locked->load(['roles:id,code,name', 'scopes']), $before];
        }, 3);
        $audit->record('user.updated', $updated, before: $before, after: $this->payload($updated), actor: $request->user(), reason: $data['reason']);

        return response()->json(['user' => $this->payload($updated)]);
    }

    public function replaceScopes(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($user->user_type === 'applicant', 422, 'Applicant accounts do not accept staff scopes.');
        $data = $request->validate([
            'entity_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'scopes' => ['required', 'array', 'max:50'],
            'scopes.*.scope_type' => ['required', Rule::in(['national', 'region', 'centre', 'panel', 'campaign', 'post', 'stage', 'task'])],
            'scopes.*.scope_id' => ['nullable', 'string', 'max:26'],
            'scopes.*.allowed_tasks' => ['required', 'array', 'min:1', 'max:100'],
            'scopes.*.allowed_tasks.*' => ['required', 'string', 'max:120', 'distinct'],
            'scopes.*.expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $keys = collect($data['scopes'])->map(function (array $scope): string {
            if ($scope['scope_type'] !== 'national' && blank($scope['scope_id'] ?? null)) {
                throw ValidationException::withMessages(['scopes' => "Scope ID is required for {$scope['scope_type']} scope."]);
            }

            return $scope['scope_type'].':'.($scope['scope_id'] ?? '');
        });
        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['scopes' => 'Duplicate scope type and ID combinations are not allowed.']);
        }

        [$updated, $beforeScopes] = DB::transaction(function () use ($data, $user, $request): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertVersion($locked, (int) $data['entity_version']);
            $beforeScopes = $locked->scopes()->orderBy('scope_type')->get()->toArray();
            $locked->scopes()->delete();
            foreach ($data['scopes'] as $scope) {
                UserScope::query()->create([
                    'user_id' => $locked->id,
                    'scope_type' => $scope['scope_type'],
                    'scope_id' => $scope['scope_type'] === 'national' ? null : $scope['scope_id'],
                    'allowed_tasks' => array_values($scope['allowed_tasks']),
                    'expires_at' => $scope['expires_at'] ?? null,
                    'assigned_by' => $request->user()->id,
                ]);
            }
            $locked->increment('entity_version');

            return [$locked->fresh()->load(['roles:id,code,name', 'scopes']), $beforeScopes];
        }, 3);
        $audit->record('user.scopes_replaced', $updated, before: ['scopes' => $beforeScopes], after: ['scopes' => $updated->scopes->toArray()], actor: $request->user(), reason: $data['reason']);

        return response()->json(['user' => $this->payload($updated)]);
    }

    public function resetPassword(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'Use the personal password-change flow for your own account.');
        $data = $this->validateSensitiveAction($request);
        [$updated, $temporaryPassword] = DB::transaction(function () use ($data, $user): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertVersion($locked, (int) $data['entity_version']);
            $temporaryPassword = Str::password(20);
            $locked->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'password_changed_at' => null,
                'entity_version' => $locked->entity_version + 1,
            ])->save();
            $this->deleteSessions($locked);

            return [$locked->load(['roles:id,code,name', 'scopes']), $temporaryPassword];
        }, 3);
        $audit->record('user.password_reset', $updated, actor: $request->user(), after: ['must_change_password' => true], reason: $data['reason']);

        return response()->json([
            'user' => $this->payload($updated),
            'temporary_password' => $temporaryPassword,
            'message' => 'Password reset. The temporary password is shown once and all sessions were revoked.',
        ])->header('Cache-Control', 'no-store');
    }

    public function resetMfa(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'Another technical administrator must reset your MFA.');
        $data = $this->validateSensitiveAction($request);
        $updated = DB::transaction(function () use ($data, $user): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertVersion($locked, (int) $data['entity_version']);
            $locked->forceFill([
                'mfa_secret' => null,
                'mfa_recovery_codes' => null,
                'mfa_confirmed_at' => null,
                'entity_version' => $locked->entity_version + 1,
            ])->save();
            $this->deleteSessions($locked);

            return $locked->load(['roles:id,code,name', 'scopes']);
        }, 3);
        $audit->record('user.mfa_reset', $updated, actor: $request->user(), reason: $data['reason']);

        return response()->json(['user' => $this->payload($updated), 'message' => 'MFA reset and all sessions revoked.']);
    }

    public function revokeSessions(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'Use sign out to end your own session.');
        $data = $this->validateSensitiveAction($request);
        $updated = DB::transaction(function () use ($data, $user): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertVersion($locked, (int) $data['entity_version']);
            $this->deleteSessions($locked);
            $locked->increment('entity_version');

            return $locked->fresh()->load(['roles:id,code,name', 'scopes']);
        }, 3);
        $audit->record('user.sessions_revoked', $updated, actor: $request->user(), reason: $data['reason']);

        return response()->json(['user' => $this->payload($updated), 'message' => 'All sessions and API tokens were revoked.']);
    }

    /** @return array{entity_version: int, reason: string} */
    private function validateSensitiveAction(Request $request): array
    {
        return $request->validate([
            'entity_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
    }

    private function assertVersion(User $user, int $expectedVersion): void
    {
        abort_if((int) $user->entity_version !== $expectedVersion, 409, 'The account changed on the server. Refresh before continuing.');
    }

    private function assertAdministratorContinuity(User $actor, User $target, string $nextRoleCode, string $nextStatus): void
    {
        $removingSystemAccess = $target->user_type === 'system_administrator'
            && ($nextRoleCode !== 'system_administrator' || $nextStatus !== 'active');
        if (! $removingSystemAccess) {
            return;
        }
        abort_if($actor->is($target), 422, 'A technical administrator cannot disable or demote their own account.');
        $activeAdministratorIds = User::query()
            ->where('user_type', 'system_administrator')
            ->where('status', 'active')
            ->lockForUpdate()
            ->pluck('id');
        abort_if($activeAdministratorIds->count() <= 1, 409, 'The last active technical administrator cannot be disabled or demoted.');
    }

    private function deleteSessions(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function isPrivilegedRole(string $roleCode): bool
    {
        return in_array($roleCode, config('erecruit.security.privileged_roles'), true);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $user->loadMissing(['roles:id,code,name', 'scopes']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'status' => $user->status,
            'is_privileged' => (bool) $user->is_privileged,
            'must_change_password' => (bool) $user->must_change_password,
            'mfa_enabled' => $user->mfa_secret !== null,
            'mfa_confirmed' => $user->mfa_confirmed_at !== null,
            'last_login_at' => $user->last_login_at,
            'password_changed_at' => $user->password_changed_at,
            'entity_version' => (int) $user->entity_version,
            'roles' => $user->roles->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name])->values(),
            'scopes' => $user->scopes->map(fn (UserScope $scope): array => [
                'scope_type' => $scope->scope_type,
                'scope_id' => $scope->scope_id,
                'allowed_tasks' => $scope->allowed_tasks ?? [],
                'expires_at' => $scope->expires_at,
            ])->values(),
        ];
    }
}
