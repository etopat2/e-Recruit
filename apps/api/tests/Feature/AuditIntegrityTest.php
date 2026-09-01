<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_verification_and_sensitive_value_redaction(): void
    {
        $auditor = User::factory()->create(['user_type' => 'auditor', 'is_privileged' => true, 'mfa_confirmed_at' => now()]);
        app(AuditService::class)->record('synthetic.first', 'synthetic', 'one', after: ['nin' => 'CM000000000001', 'safe' => 'visible'], actor: $auditor);
        app(AuditService::class)->record('synthetic.second', 'synthetic', 'two', after: ['token' => 'secret'], actor: $auditor);
        Sanctum::actingAs($auditor);

        $this->getJson('/api/v1/audit-logs/verify-chain')->assertOk()->assertJson(['valid' => true, 'checked' => 2, 'failures' => []]);
        $this->assertSame('[REDACTED]', DB::table('audit_logs')->where('action', 'synthetic.first')->first()->after_values === null
            ? null
            : json_decode(DB::table('audit_logs')->where('action', 'synthetic.first')->value('after_values'), true, 512, JSON_THROW_ON_ERROR)['nin']);
    }

    public function test_database_rejects_audit_update(): void
    {
        app(AuditService::class)->record('synthetic.immutable', 'synthetic', 'one');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['action' => 'tampered']);
    }
}
