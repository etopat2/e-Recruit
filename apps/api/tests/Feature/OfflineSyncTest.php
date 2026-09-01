<?php

namespace Tests\Feature;

use App\Domain\Offline\OfflineSyncService;
use App\Models\AssessmentDefinition;
use App\Models\AssessmentScore;
use App\Models\InterviewAssignment;
use App\Models\OfflinePackage;
use App\Models\Panel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_event_uuid_is_idempotent_and_updates_a_versioned_score_once(): void
    {
        $fixture = $this->recruitmentFixture();
        $user = User::factory()->create(['user_type' => 'panel_member']);
        $regionId = (string) Str::ulid();
        $centreId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        DB::table('prison_regions')->insert(['id' => $regionId, 'code' => 'CTR', 'name' => 'Central', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recruitment_centres')->insert(['id' => $centreId, 'prison_region_id' => $regionId, 'code' => 'KLA', 'name' => 'Kampala', 'address' => 'Kampala', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('centre_sessions')->insert(['id' => $sessionId, 'recruitment_centre_id' => $centreId, 'recruitment_post_id' => $fixture['post']->id, 'code' => 'AM', 'session_date' => now()->toDateString(), 'reporting_time' => '08:00', 'capacity' => 20, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $panel = Panel::query()->create(['centre_session_id' => $sessionId, 'code' => 'P1', 'name' => 'Panel 1', 'capacity' => 20, 'status' => 'open']);
        $assignment = InterviewAssignment::query()->create(['application_id' => $fixture['application']->id, 'centre_session_id' => $sessionId, 'panel_id' => $panel->id, 'assignment_order' => 1, 'algorithm_version' => 'test', 'input_fingerprint' => str_repeat('a', 64)]);
        $definition = AssessmentDefinition::query()->create(['recruitment_post_id' => $fixture['post']->id, 'campaign_version_id' => $fixture['version']->id, 'code' => 'INT', 'name' => 'Interview', 'component_type' => 'interview', 'maximum_mark' => 100, 'weight' => 100, 'mandatory' => true, 'assessor_model' => 'single', 'aggregation_method' => 'single']);
        $score = AssessmentScore::query()->create(['interview_assignment_id' => $assignment->id, 'assessment_definition_id' => $definition->id, 'assessor_id' => $user->id, 'score' => 50, 'status' => 'draft', 'entity_version' => 1]);
        $deviceId = (string) Str::ulid();
        DB::table('registered_devices')->insert(['id' => $deviceId, 'user_id' => $user->id, 'public_identifier' => (string) Str::uuid(), 'label' => 'Test tablet', 'status' => 'active', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $package = OfflinePackage::query()->create(['registered_device_id' => $deviceId, 'user_id' => $user->id, 'pack_type' => 'score_capture', 'scope' => ['panel_id' => $panel->id], 'permitted_actions' => ['ASSESSMENT_SCORE_RECORDED'], 'manifest' => ['entity_ids' => [$score->id]], 'manifest_fingerprint' => str_repeat('b', 64), 'status' => 'active', 'issued_at' => now(), 'expires_at' => now()->addHour(), 'outstanding_events' => 1]);
        $event = ['id' => (string) Str::uuid(), 'entity_type' => 'assessment_score', 'entity_id' => $score->id, 'action_type' => 'ASSESSMENT_SCORE_RECORDED', 'payload_schema_version' => 1, 'payload' => ['score' => 77], 'base_entity_version' => 1, 'local_sequence' => 1, 'local_timestamp' => now()->toISOString()];

        $first = app(OfflineSyncService::class)->push($package, $user, [$event]);
        $second = app(OfflineSyncService::class)->push($package->fresh(), $user, [$event]);
        $conflictingEvent = [
            ...$event,
            'id' => (string) Str::uuid(),
            'payload' => ['score' => 91],
            'local_sequence' => 2,
        ];
        $conflict = app(OfflineSyncService::class)->push($package->fresh(), $user, [$conflictingEvent]);

        $this->assertSame('accepted', $first['acknowledgements'][0]['state']);
        $this->assertTrue($second['acknowledgements'][0]['duplicate']);
        $this->assertSame('77.00', $score->fresh()->score);
        $this->assertSame(2, $score->fresh()->entity_version);
        $this->assertSame('conflict', $conflict['acknowledgements'][0]['state']);
        $this->assertDatabaseHas('sync_conflicts', ['entity_id' => $score->id, 'field_key' => 'score', 'status' => 'open']);
        $this->assertDatabaseCount('offline_events', 2);
    }
}
