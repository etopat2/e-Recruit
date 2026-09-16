<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class HardCopyHeadquartersReceptionTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_verification_officer_records_server_authoritative_receiving_point(): void
    {
        $fixture = $this->recruitmentFixture([
            'reference' => 'UPS/TEST/HC/000001',
            'status' => 'awaiting_hard_copies',
        ]);
        $officer = $this->scopedUser('verification_officer', $fixture['campaign']->id);
        Sanctum::actingAs($officer);

        $this->postJson("/api/v1/applications/{$fixture['application']->id}/hard-copy-receipts", [
            'receiving_office' => 'A forged interview-centre value',
            'received_at' => now()->toISOString(),
            'items' => [
                ['document_type' => 'national_id', 'status' => 'Match'],
                ['document_type' => 'academic_certificate', 'status' => 'Missing'],
            ],
        ])->assertCreated()
            ->assertJsonPath('receipt.receiving_office', 'Uganda Prisons Service Headquarters')
            ->assertJsonPath('receipt.status', 'query_required');

        $this->assertDatabaseHas('hard_copy_receipts', [
            'application_id' => $fixture['application']->id,
            'receiving_office' => 'Uganda Prisons Service Headquarters',
            'received_by' => $officer->id,
        ]);
    }

    public function test_transmission_and_interview_roles_cannot_record_headquarters_receipt(): void
    {
        $fixture = $this->recruitmentFixture([
            'reference' => 'UPS/TEST/HC/000002',
            'status' => 'awaiting_hard_copies',
        ]);
        $payload = [
            'received_at' => now()->toISOString(),
            'items' => [['document_type' => 'national_id', 'status' => 'Match']],
        ];

        foreach (['regional_recruitment_officer', 'centre_coordinator', 'panel_head', 'data_clerk'] as $role) {
            Sanctum::actingAs($this->scopedUser($role, $fixture['campaign']->id));
            $this->postJson("/api/v1/applications/{$fixture['application']->id}/hard-copy-receipts", $payload)
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('hard_copy_receipts', ['application_id' => $fixture['application']->id]);
    }

    private function scopedUser(string $role, string $campaignId): User
    {
        $user = User::factory()->create(['user_type' => $role]);
        $user->scopes()->create([
            'scope_type' => 'campaign',
            'scope_id' => $campaignId,
            'allowed_tasks' => ['*'],
        ]);

        return $user;
    }
}
