<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ScopeAuthorizer;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class TechnicalUserAdministrationTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    private const TemporaryPassword = 'TemporaryPass2026'; // gitleaks:allow -- synthetic test credential

    private const PermanentPassword = 'PermanentSecurePass2026'; // gitleaks:allow -- synthetic test credential

    public function test_technical_administrator_creates_a_staff_account_with_a_one_time_password(): void
    {
        $administrator = $this->technicalAdministrator();
        $this->role('helpdesk_officer', 'Helpdesk Officer');
        Sanctum::actingAs($administrator, ['*']);

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Synthetic Helpdesk Officer',
            'email' => 'HelpDesk@Example.Test',
            'phone' => '+256700555001',
            'role_code' => 'helpdesk_officer',
        ])->assertCreated()
            ->assertJsonPath('user.user_type', 'helpdesk_officer')
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonStructure(['temporary_password'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $temporaryPassword = $response->json('temporary_password');
        $created = User::query()->where('email', 'helpdesk@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($temporaryPassword, $created->password));
        $this->assertTrue($created->roles()->where('code', 'helpdesk_officer')->exists());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'action' => 'user.created', 'entity_id' => (string) $created->id]);
    }

    public function test_temporary_password_blocks_application_access_until_it_is_changed(): void
    {
        $this->role('helpdesk_officer', 'Helpdesk Officer');
        $user = User::factory()->create([
            'email' => 'temporary@example.test',
            'password' => self::TemporaryPassword,
            'user_type' => 'helpdesk_officer',
            'must_change_password' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => self::TemporaryPassword,
            'device_name' => 'Synthetic browser',
        ])->assertOk()->assertJsonPath('requires_password_change', true);
        $temporaryToken = $login->json('token');

        $this->withToken($temporaryToken)->getJson('/api/v1/applications')
            ->assertForbidden()
            ->assertJsonPath('message', 'A password change is required before this account can continue.');
        $changed = $this->withToken($temporaryToken)->putJson('/api/v1/auth/password', [
            'current_password' => self::TemporaryPassword,
            'password' => self::PermanentPassword,
            'password_confirmation' => self::PermanentPassword,
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        [$temporaryTokenId] = explode('|', $temporaryToken, 2);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $temporaryTokenId]);
        $this->withToken($changed->json('token'))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue(Hash::check(self::PermanentPassword, $user->fresh()->password));
    }

    public function test_privileged_temporary_account_completes_mfa_before_the_required_password_change(): void
    {
        $this->mock(TotpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateSecret')->once()->andReturn('SYNTHETICMFASECRET');
            $mock->shouldReceive('generateRecoveryCodes')->once()->andReturn([
                'plain' => ['SYNTH-ETIC1'],
                'hashed' => [hash('sha256', 'SYNTH-ETIC1')],
            ]);
            $mock->shouldReceive('provisioningUri')->once()->andReturn('otpauth://synthetic');
            $mock->shouldReceive('verify')->once()->with('SYNTHETICMFASECRET', '123456')->andReturnTrue();
        });
        $role = $this->role('system_administrator', 'System Administrator');
        $user = User::factory()->create([
            'email' => 'new-administrator@example.test',
            'password' => self::TemporaryPassword,
            'user_type' => $role->code,
            'is_privileged' => true,
            'must_change_password' => true,
        ]);
        $user->roles()->attach($role->id);

        $login = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => self::TemporaryPassword,
            'device_name' => 'Synthetic browser',
        ])->assertOk()->assertJsonPath('requires_mfa_enrolment', true);
        $enrolmentToken = $login->json('token');
        $this->withToken($enrolmentToken)->putJson('/api/v1/auth/password', [
            'current_password' => self::TemporaryPassword,
            'password' => self::PermanentPassword,
            'password_confirmation' => self::PermanentPassword,
        ])->assertForbidden()->assertJsonPath('message', 'Complete MFA enrolment before changing this password.');
        $this->withToken($enrolmentToken)->postJson('/api/v1/auth/mfa/enrol', [
            'password' => self::TemporaryPassword,
        ])->assertOk()->assertJsonPath('provisioning_uri', 'otpauth://synthetic');
        $confirmed = $this->withToken($enrolmentToken)->postJson('/api/v1/auth/mfa/confirm', [
            'code' => '123456',
        ])->assertOk()->assertJsonPath('requires_password_change', true);
        $this->withToken($confirmed->json('token'))->putJson('/api/v1/auth/password', [
            'current_password' => self::TemporaryPassword,
            'password' => self::PermanentPassword,
            'password_confirmation' => self::PermanentPassword,
        ])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
    }

    public function test_technical_administrator_manages_role_status_scopes_and_session_revocation(): void
    {
        $administrator = $this->technicalAdministrator();
        $helpdeskRole = $this->role('helpdesk_officer', 'Helpdesk Officer');
        $verificationRole = $this->role('verification_officer', 'Verification Officer');
        $target = User::factory()->create(['user_type' => $helpdeskRole->code, 'entity_version' => 1]);
        $target->roles()->attach($helpdeskRole->id);
        $target->createToken('existing-session');
        Sanctum::actingAs($administrator, ['*']);

        $updated = $this->putJson("/api/v1/admin/users/{$target->id}", [
            'name' => 'Updated Synthetic Officer',
            'status' => 'active',
            'role_code' => $verificationRole->code,
            'entity_version' => 1,
            'reason' => 'Approved transfer into the verification team.',
        ])->assertOk()->assertJsonPath('user.user_type', 'verification_officer');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $scoped = $this->putJson("/api/v1/admin/users/{$target->id}/scopes", [
            'entity_version' => $updated->json('user.entity_version'),
            'reason' => 'Assign the approved campaign verification scope.',
            'scopes' => [[
                'scope_type' => 'campaign',
                'scope_id' => '01JTESTCAMPAIGN0000000000',
                'allowed_tasks' => ['view:application', 'decision:verification'],
            ]],
        ])->assertOk()->assertJsonPath('user.scopes.0.scope_type', 'campaign');

        $this->postJson("/api/v1/admin/users/{$target->id}/sessions/revoke", [
            'entity_version' => $scoped->json('user.entity_version'),
            'reason' => 'Routine technical security session revocation.',
        ])->assertOk()->assertJsonPath('message', 'All sessions and API tokens were revoked.');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'action' => 'user.scopes_replaced', 'entity_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'action' => 'user.sessions_revoked', 'entity_id' => (string) $target->id]);
    }

    public function test_only_a_system_administrator_can_manage_accounts_and_the_last_one_is_protected(): void
    {
        $administrator = $this->technicalAdministrator();
        $ordinaryStaff = User::factory()->create(['user_type' => 'helpdesk_officer', 'is_privileged' => false]);
        Sanctum::actingAs($ordinaryStaff, ['*']);
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        Sanctum::actingAs($administrator, ['*']);
        $this->putJson("/api/v1/admin/users/{$administrator->id}", [
            'status' => 'disabled',
            'entity_version' => $administrator->entity_version,
            'reason' => 'Attempt to disable the currently signed-in administrator.',
        ])->assertUnprocessable();
    }

    public function test_password_and_mfa_resets_are_audited_and_revoke_tokens(): void
    {
        $administrator = $this->technicalAdministrator();
        $role = $this->role('medical_officer', 'Medical Officer');
        $target = User::factory()->create([
            'user_type' => $role->code,
            'is_privileged' => true,
            'mfa_secret' => 'SYNTHETICMFASECRET',
            'mfa_recovery_codes' => ['synthetic-code-hash'],
            'mfa_confirmed_at' => now(),
            'entity_version' => 1,
        ]);
        $target->roles()->attach($role->id);
        $target->createToken('active-token');
        Sanctum::actingAs($administrator, ['*']);

        $passwordReset = $this->postJson("/api/v1/admin/users/{$target->id}/password-reset", [
            'entity_version' => 1,
            'reason' => 'Authorised local account recovery exercise.',
        ])->assertOk()->assertJsonPath('user.must_change_password', true)->assertJsonStructure(['temporary_password']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson("/api/v1/admin/users/{$target->id}/mfa-reset", [
            'entity_version' => $passwordReset->json('user.entity_version'),
            'reason' => 'Authorised replacement of the registered authenticator.',
        ])->assertOk()->assertJsonPath('user.mfa_enabled', false);
        $this->assertNull($target->fresh()->mfa_secret);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'action' => 'user.password_reset', 'entity_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'action' => 'user.mfa_reset', 'entity_id' => (string) $target->id]);
    }

    public function test_system_administrator_cannot_receive_recruitment_decision_authority_from_a_wildcard_scope(): void
    {
        $administrator = $this->technicalAdministrator();
        $administrator->scopes()->create([
            'scope_type' => 'national',
            'scope_id' => null,
            'allowed_tasks' => ['*'],
            'assigned_by' => $administrator->id,
        ]);
        $fixture = $this->recruitmentFixture();

        $this->assertFalse(app(ScopeAuthorizer::class)->canPerform($administrator, 'decision:selection', $fixture['application']));
    }

    private function technicalAdministrator(): User
    {
        $role = $this->role('system_administrator', 'System Administrator');
        $administrator = User::factory()->create([
            'user_type' => $role->code,
            'status' => 'active',
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
            'must_change_password' => false,
        ]);
        $administrator->roles()->attach($role->id);

        return $administrator;
    }

    private function role(string $code, string $name): Role
    {
        return Role::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_decision_role' => false]);
    }
}
