<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class OfflineExtendedWorkflowTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_hard_copy_pack_is_self_identifying_idempotent_and_revocable(): void
    {
        $fixture = $this->recruitmentFixture(['status' => 'awaiting_hard_copies']);
        $officer = User::factory()->create(['user_type' => 'hard_copy_receiving_officer']);
        $officer->scopes()->create(['scope_type' => 'campaign', 'scope_id' => $fixture['campaign']->id, 'allowed_tasks' => ['*']]);
        $deviceId = $this->device($officer);
        Sanctum::actingAs($officer);

        $issued = $this->postJson('/api/v1/offline/packages', [
            'registered_device_id' => $deviceId,
            'pack_type' => 'hard_copy',
            'scope' => ['campaign_id' => $fixture['campaign']->id],
            'permitted_actions' => ['HARDCOPY_RECEIPT_RECORDED'],
            'entity_ids' => [$fixture['application']->id],
        ])->assertCreated()
            ->assertJsonPath('package.manifest.schema_version', 1)
            ->assertJsonPath('package.manifest.entity_type', 'application')
            ->assertJsonPath('package.manifest.device_id', $deviceId)
            ->assertJsonPath('server_records.0.server_version', 1);

        $packageId = $issued->json('package.id');
        $event = [
            'id' => (string) Str::uuid(),
            'entity_type' => 'application',
            'entity_id' => $fixture['application']->id,
            'action_type' => 'HARDCOPY_RECEIPT_RECORDED',
            'payload_schema_version' => 1,
            'payload' => [
                'receiving_office' => 'Kampala Recruitment Centre',
                'received_at' => now()->toISOString(),
                'items' => [
                    ['document_type' => 'national_id', 'status' => 'Match'],
                    ['document_type' => 'academic_certificate', 'status' => 'Unreadable', 'notes' => 'Embossed seal is unclear'],
                ],
            ],
            'base_entity_version' => 1,
            'local_sequence' => 1,
            'local_timestamp' => now()->toISOString(),
        ];

        $this->postJson("/api/v1/offline/packages/{$packageId}/sync", ['events' => [$event]])
            ->assertOk()->assertJsonPath('acknowledgements.0.state', 'accepted');
        $this->postJson("/api/v1/offline/packages/{$packageId}/sync", ['events' => [$event]])
            ->assertOk()->assertJsonPath('acknowledgements.0.duplicate', true);

        $this->assertDatabaseHas('hard_copy_receipts', ['application_id' => $fixture['application']->id, 'status' => 'query_required']);
        $this->assertDatabaseHas('physical_document_checks', ['document_type' => 'academic_certificate', 'status' => 'Unreadable']);
        $this->assertDatabaseHas('applications', ['id' => $fixture['application']->id, 'status' => 'hard_copies_received', 'entity_version' => 2]);

        $this->postJson("/api/v1/offline/devices/{$deviceId}/revoke", ['reason' => 'Device was returned and must no longer hold recruitment records.'])
            ->assertOk()->assertJsonPath('status', 'revoked');
        $this->postJson("/api/v1/offline/packages/{$packageId}/sync", ['events' => [[...$event, 'id' => (string) Str::uuid(), 'local_sequence' => 2]]])
            ->assertStatus(409);
        $this->assertDatabaseHas('offline_packages', ['id' => $packageId, 'status' => 'revoked']);
    }

    public function test_verification_pack_merges_independent_fields_and_conflicts_on_the_same_field(): void
    {
        $fixture = $this->recruitmentFixture();
        $officer = User::factory()->create(['user_type' => 'verification_officer']);
        $officer->scopes()->create(['scope_type' => 'campaign', 'scope_id' => $fixture['campaign']->id, 'allowed_tasks' => ['decision:verification']]);
        $document = Document::query()->create([
            'application_id' => $fixture['application']->id,
            'document_type' => 'national_id',
            'version' => 1,
            'original_filename' => 'national-id.pdf',
            'storage_disk' => 'local',
            'original_path' => 'tests/national-id.pdf',
            'mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'malware_status' => 'clean',
            'processing_status' => 'completed',
            'uploaded_by' => $fixture['user']->id,
            'uploaded_at' => now(),
        ]);
        $deviceId = $this->device($officer);
        Sanctum::actingAs($officer);

        $packageId = $this->postJson('/api/v1/offline/packages', [
            'registered_device_id' => $deviceId,
            'pack_type' => 'verification',
            'scope' => ['campaign_id' => $fixture['campaign']->id],
            'permitted_actions' => ['DOCUMENT_VERIFICATION_RECORDED'],
            'entity_ids' => [$document->id],
        ])->assertCreated()->json('package.id');
        DB::table('offline_packages')->where('id', $packageId)->update(['issued_at' => now()->subMinute()]);

        $event = fn (string $field, string $value, int $sequence): array => [
            'id' => (string) Str::uuid(),
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'action_type' => 'DOCUMENT_VERIFICATION_RECORDED',
            'payload_schema_version' => 1,
            'payload' => [
                'field_key' => $field,
                'action' => 'verify',
                'outcome' => 'VERIFIED/CONSISTENT',
                'verified_value' => $value,
                'evidence_references' => [['document_id' => $document->id, 'page' => 1]],
            ],
            'base_entity_version' => 1,
            'local_sequence' => $sequence,
            'local_timestamp' => now()->toISOString(),
        ];

        $first = $event('name', 'Amina Nabirye', 1);
        $second = $event('date_of_birth', '2001-05-12', 2);
        $this->postJson("/api/v1/offline/packages/{$packageId}/sync", ['events' => [$first, $second]])
            ->assertOk()
            ->assertJsonPath('acknowledgements.0.state', 'accepted')
            ->assertJsonPath('acknowledgements.1.state', 'accepted');

        $sameField = $event('name', 'Amina N.', 3);
        $this->postJson("/api/v1/offline/packages/{$packageId}/sync", ['events' => [$sameField]])
            ->assertOk()->assertJsonPath('acknowledgements.0.state', 'conflict');

        $this->assertDatabaseHas('verified_values', ['application_id' => $fixture['application']->id, 'field_key' => 'name', 'current' => true]);
        $this->assertDatabaseHas('verified_values', ['application_id' => $fixture['application']->id, 'field_key' => 'date_of_birth', 'current' => true]);
        $this->assertDatabaseHas('sync_conflicts', ['entity_id' => $document->id, 'field_key' => 'name', 'status' => 'open']);
        $this->getJson("/api/v1/offline/packages/{$packageId}/changes")->assertOk()->assertJsonCount(1, 'conflicts');
    }

    private function device(User $user): string
    {
        $id = (string) Str::ulid();
        DB::table('registered_devices')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'public_identifier' => (string) Str::uuid(),
            'label' => 'Controlled test tablet',
            'platform' => 'PWA',
            'status' => 'active',
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
