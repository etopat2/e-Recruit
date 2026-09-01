<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetentionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_purge_excludes_legal_holds_and_preserves_evidence(): void
    {
        $council = $this->privilegedUser('prisons_council_secretariat');
        Sanctum::actingAs($council);
        $this->postJson('/api/v1/governance/retention/policies', [
            'record_category' => 'notifications',
            'retention_days' => 30,
            'disposition' => 'review_for_purge',
            'legal_basis_reference' => 'SYNTHETIC-POLICY-001',
            'approval_reference' => 'SYNTHETIC-COUNCIL-MINUTE-001',
        ])->assertCreated();

        $heldId = $this->notification('held', now()->subDays(60));
        $purgeableId = $this->notification('purgeable', now()->subDays(60));
        $this->postJson('/api/v1/governance/legal-holds', [
            'entity_type' => 'notifications',
            'entity_id' => $heldId,
            'reason' => 'Synthetic litigation hold for automated governance testing.',
        ])->assertCreated();

        $requester = $this->privilegedUser('hq_recruitment_administrator');
        Sanctum::actingAs($requester);
        $purgeRequest = $this->postJson('/api/v1/governance/purge-requests', [
            'record_category' => 'notifications',
            'reason' => 'Remove expired notification delivery metadata under the approved policy.',
        ])->assertCreated()
            ->assertJsonPath('purge_request.eligible_record_count', 1)
            ->json('purge_request');

        Sanctum::actingAs($council);
        $this->postJson("/api/v1/governance/purge-requests/{$purgeRequest['id']}/decision", [
            'decision' => 'approve',
            'reason' => 'Approved after confirming policy, cutoff, count, and active legal holds.',
            'approval_reference' => 'SYNTHETIC-COUNCIL-MINUTE-002',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $executor = $this->privilegedUser('system_administrator');
        Sanctum::actingAs($executor);
        $result = $this->postJson("/api/v1/governance/purge-requests/{$purgeRequest['id']}/execute", [
            'confirmation' => 'PURGE',
            'reason' => 'Execute the separately approved synthetic retention operation.',
        ])->assertOk()
            ->assertJsonPath('deleted_count', 1)
            ->json();

        $this->assertDatabaseHas('notifications', ['id' => $heldId]);
        $this->assertDatabaseMissing('notifications', ['id' => $purgeableId]);
        $this->assertDatabaseHas('purge_requests', ['id' => $purgeRequest['id'], 'status' => 'executed', 'evidence_hash' => $result['evidence_hash']]);
        $this->assertSame(64, strlen($result['evidence_hash']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.purge_execution_started', 'entity_id' => $purgeRequest['id']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.purge_executed', 'entity_id' => $purgeRequest['id']]);
    }

    public function test_active_recruitment_records_are_not_an_executable_purge_category(): void
    {
        Sanctum::actingAs($this->privilegedUser('hq_recruitment_administrator'));

        $this->postJson('/api/v1/governance/purge-requests', [
            'record_category' => 'applicants',
            'reason' => 'This intentionally unsupported request must be rejected safely.',
        ])->assertUnprocessable()->assertJsonValidationErrors('record_category');
    }

    private function privilegedUser(string $userType): User
    {
        return User::factory()->create([
            'user_type' => $userType,
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
        ]);
    }

    private function notification(string $key, \DateTimeInterface $timestamp): string
    {
        $id = (string) Str::ulid();
        DB::table('notifications')->insert([
            'id' => $id,
            'event_code' => 'synthetic.retention',
            'channel' => 'in_portal',
            'recipient' => 'synthetic@example.invalid',
            'status' => 'delivered',
            'attempt_count' => 1,
            'delivered_at' => $timestamp,
            'idempotency_key' => hash('sha256', $key),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $id;
    }
}
