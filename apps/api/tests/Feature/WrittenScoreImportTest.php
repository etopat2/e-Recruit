<?php

namespace Tests\Feature;

use App\Models\AssessmentDefinition;
use App\Models\InterviewAssignment;
use App\Models\Panel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class WrittenScoreImportTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_valid_written_score_file_is_preserved_and_imported_atomically(): void
    {
        Storage::fake('local');
        config()->set('erecruit.uploads.disk', 'local');
        $fixture = $this->assessmentFixture('written_examination_officer', 'present', 'written');
        Sanctum::actingAs($fixture['user']);
        $csv = "application_reference,score,notes\n{$fixture['application']->reference},78.5,Synthetic written result\n";

        $result = $this->post('/api/v1/assessment-score-imports', [
            'assessment_definition_id' => $fixture['definition']->id,
            'centre_session_id' => $fixture['assignment']->centre_session_id,
            'file' => UploadedFile::fake()->createWithContent('written-scores.csv', $csv),
            'purpose' => 'Import approved synthetic written examination marks.',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('import.status', 'imported')
            ->assertJsonPath('import.accepted_rows', 1)
            ->json('import');

        $this->assertDatabaseHas('assessment_scores', [
            'interview_assignment_id' => $fixture['assignment']->id,
            'assessment_definition_id' => $fixture['definition']->id,
            'score' => 78.5,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('assessment_score_import_rows', ['assessment_score_import_id' => $result['id'], 'row_number' => 2, 'status' => 'imported']);
        Storage::disk('local')->assertExists($result['source_path']);
    }

    public function test_any_invalid_row_rejects_the_entire_import_with_row_level_report(): void
    {
        Storage::fake('local');
        config()->set('erecruit.uploads.disk', 'local');
        $fixture = $this->assessmentFixture('written_examination_officer', 'present', 'written');
        Sanctum::actingAs($fixture['user']);
        $csv = implode("\n", [
            'application_reference,score,notes',
            "{$fixture['application']->reference},74,Valid row must not be partially applied",
            'UPS/UNKNOWN/REFERENCE,130,Unknown and out of range',
        ]);

        $result = $this->post('/api/v1/assessment-score-imports', [
            'assessment_definition_id' => $fixture['definition']->id,
            'centre_session_id' => $fixture['assignment']->centre_session_id,
            'file' => UploadedFile::fake()->createWithContent('written-scores.csv', $csv),
            'purpose' => 'Validate rejection and row reporting for synthetic data.',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('import.status', 'rejected')
            ->assertJsonPath('import.accepted_rows', 0)
            ->assertJsonPath('import.rejected_rows', 1)
            ->assertJsonPath('validation_errors.0.row_number', 3)
            ->json('import');

        $this->assertDatabaseCount('assessment_scores', 0);
        $this->assertDatabaseHas('assessment_score_import_rows', ['assessment_score_import_id' => $result['id'], 'row_number' => 2, 'status' => 'not_applied']);
        $this->assertDatabaseHas('assessment_score_import_rows', ['assessment_score_import_id' => $result['id'], 'row_number' => 3, 'status' => 'rejected']);
        Storage::disk('local')->assertExists($result['error_report_path']);
    }

    public function test_online_scoring_requires_check_in_or_audited_panel_head_exception(): void
    {
        $fixture = $this->assessmentFixture('panel_head', 'absent', 'oral_interview');
        Sanctum::actingAs($fixture['user']);
        $payload = [
            'interview_assignment_id' => $fixture['assignment']->id,
            'assessment_definition_id' => $fixture['definition']->id,
            'score' => 65,
        ];

        $this->postJson('/api/v1/assessment-scores', $payload)->assertConflict();
        $this->postJson('/api/v1/assessment-scores', [
            ...$payload,
            'attendance_exception_reason' => 'Panel head approved scoring after a documented check-in exception.',
        ])->assertCreated();

        $this->assertDatabaseHas('integrity_flags', [
            'indicator_type' => 'assessment_without_normal_check_in',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.score_recorded']);
    }

    /** @return array<string, mixed> */
    private function assessmentFixture(string $userType, string $attendanceStatus, string $componentType): array
    {
        $fixture = $this->recruitmentFixture([
            'reference' => 'UPS/2027/SYN/000001',
            'status' => 'under_verification',
        ]);
        $user = User::factory()->create([
            'user_type' => $userType,
            'is_privileged' => $userType === 'panel_head',
            'mfa_confirmed_at' => $userType === 'panel_head' ? now() : null,
        ]);
        DB::table('user_scopes')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'scope_type' => 'post',
            'scope_id' => $fixture['post']->id,
            'allowed_tasks' => json_encode(['assessment.score'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $regionId = (string) Str::ulid();
        $centreId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        DB::table('prison_regions')->insert(['id' => $regionId, 'code' => 'SYN-R', 'name' => 'Synthetic Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recruitment_centres')->insert(['id' => $centreId, 'prison_region_id' => $regionId, 'code' => 'SYN-C', 'name' => 'Synthetic Centre', 'address' => 'Synthetic address', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('centre_sessions')->insert(['id' => $sessionId, 'recruitment_centre_id' => $centreId, 'recruitment_post_id' => $fixture['post']->id, 'code' => 'SYN-S', 'session_date' => '2027-03-01', 'reporting_time' => '08:00', 'capacity' => 20, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $panel = Panel::query()->create(['centre_session_id' => $sessionId, 'code' => 'SYN-P', 'name' => 'Synthetic Panel', 'capacity' => 20, 'status' => 'open']);
        $assignment = InterviewAssignment::query()->create(['application_id' => $fixture['application']->id, 'centre_session_id' => $sessionId, 'panel_id' => $panel->id, 'assignment_order' => 1, 'algorithm_version' => 'test', 'input_fingerprint' => str_repeat('a', 64)]);
        DB::table('attendance_records')->insert(['id' => (string) Str::ulid(), 'interview_assignment_id' => $assignment->id, 'status' => $attendanceStatus, 'recorded_at' => now(), 'recorded_by' => $user->id, 'entity_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $definition = AssessmentDefinition::query()->create([
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'code' => strtoupper($componentType),
            'name' => 'Synthetic assessment',
            'component_type' => $componentType,
            'maximum_mark' => 100,
            'pass_mark' => 50,
            'weight' => 100,
            'mandatory' => true,
            'assessor_model' => 'single',
            'aggregation_method' => 'single',
        ]);

        return [...$fixture, 'user' => $user, 'assignment' => $assignment, 'definition' => $definition];
    }
}
