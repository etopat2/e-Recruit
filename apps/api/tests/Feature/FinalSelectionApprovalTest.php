<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class FinalSelectionApprovalTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_council_can_approve_only_a_certified_selected_and_fit_candidate(): void
    {
        $fixture = $this->selectionFixture('Fit');
        Sanctum::actingAs($fixture['council']);

        $this->postJson('/api/v1/final-selections', [
            'selection_outcome_id' => $fixture['outcome_id'],
            'medical_result_id' => $fixture['medical_result_id'],
            'approval_reference' => 'COUNCIL/MIN/2026/001',
            'confirmation' => true,
        ])->assertCreated()->assertJsonPath('final_selection.status', 'approved');

        $this->assertDatabaseHas('final_selections', [
            'application_id' => $fixture['application_id'],
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('applications', ['id' => $fixture['application_id'], 'status' => 'final_selected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'selection.final_approved', 'approval_reference' => 'COUNCIL/MIN/2026/001']);
    }

    public function test_not_fit_candidate_is_blocked_from_final_approval(): void
    {
        $fixture = $this->selectionFixture('Not Fit');
        Sanctum::actingAs($fixture['council']);

        $this->postJson('/api/v1/final-selections', [
            'selection_outcome_id' => $fixture['outcome_id'],
            'medical_result_id' => $fixture['medical_result_id'],
            'approval_reference' => 'COUNCIL/MIN/2026/002',
            'confirmation' => true,
        ])->assertConflict();

        $this->assertDatabaseCount('final_selections', 0);
    }

    /** @return array{council: User, application_id: string, outcome_id: string, medical_result_id: string} */
    private function selectionFixture(string $medicalOutcome): array
    {
        $fixture = $this->recruitmentFixture(['status' => 'selected']);
        $council = User::factory()->create([
            'user_type' => 'prisons_council_secretariat',
            'is_privileged' => true,
            'mfa_confirmed_at' => now(),
        ]);
        $rankingRunId = (string) Str::ulid();
        $selectionRunId = (string) Str::ulid();
        $outcomeId = (string) Str::ulid();
        $scheduleId = (string) Str::ulid();
        $medicalResultId = (string) Str::ulid();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        DB::table('ranking_runs')->insert([
            'id' => $rankingRunId,
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'run_number' => 1,
            'scope_dimension' => 'national',
            'input_snapshot' => '[]',
            'score_formula' => '{}',
            'tie_break_policy' => '[]',
            'fingerprint' => str_repeat('a', 64),
            'run_by' => $council->id,
            'run_at' => now(),
            ...$timestamps,
        ]);
        DB::table('selection_runs')->insert([
            'id' => $selectionRunId,
            'ranking_run_id' => $rankingRunId,
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'run_number' => 1,
            'mode' => 'final',
            'status' => 'certified',
            'parameters' => '{}',
            'offline_readiness' => '{}',
            'input_fingerprint' => str_repeat('b', 64),
            'output_fingerprint' => str_repeat('c', 64),
            'run_by' => $council->id,
            'certified_by' => $council->id,
            'certified_at' => now(),
            ...$timestamps,
        ]);
        DB::table('selection_outcomes')->insert([
            'id' => $outcomeId,
            'selection_run_id' => $selectionRunId,
            'application_id' => $fixture['application']->id,
            'bucket_key' => 'national',
            'outcome' => 'selected',
            'position' => 1,
            'score' => 88,
            'decision_trace' => '{}',
            ...$timestamps,
        ]);
        DB::table('medical_schedules')->insert([
            'id' => $scheduleId,
            'recruitment_post_id' => $fixture['post']->id,
            'facility' => 'UPS Medical Board',
            'scheduled_date' => now()->toDateString(),
            'reporting_time' => '08:00',
            ...$timestamps,
        ]);
        DB::table('medical_results')->insert([
            'id' => $medicalResultId,
            'application_id' => $fixture['application']->id,
            'medical_schedule_id' => $scheduleId,
            'outcome' => $medicalOutcome,
            'recorded_by' => $council->id,
            'recorded_at' => now(),
            'entity_version' => 1,
            ...$timestamps,
        ]);

        return [
            'council' => $council,
            'application_id' => $fixture['application']->id,
            'outcome_id' => $outcomeId,
            'medical_result_id' => $medicalResultId,
        ];
    }
}
