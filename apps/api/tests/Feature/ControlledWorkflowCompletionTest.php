<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\AssessmentDefinition;
use App\Models\AssessmentScore;
use App\Models\InterviewAssignment;
use App\Models\Panel;
use App\Models\User;
use App\Support\Nin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class ControlledWorkflowCompletionTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_closed_panel_score_correction_requires_reopen_and_independent_approval(): void
    {
        $fixture = $this->panelFixture();
        Sanctum::actingAs($fixture['panel_head']);
        $adjustmentId = $this->postJson("/api/v1/assessment-scores/{$fixture['score']->id}/adjustments", [
            'new_score' => 76,
            'reason_code' => 'TRANSCRIPTION_CORRECTION',
            'justification' => 'The signed panel sheet confirms a transcription error in the submitted score.',
        ])->assertCreated()->json('adjustment_id');

        Sanctum::actingAs($fixture['hq']);
        $decision = ['decision' => 'approve', 'reason' => 'The signed source sheet and correction request were independently reviewed.', 'approval_reference' => 'HQ/CORR/2026/001'];
        $this->postJson("/api/v1/score-adjustments/{$adjustmentId}/decision", $decision)->assertConflict();
        $this->postJson("/api/v1/panels/{$fixture['panel']->id}/reopen", [
            'reason' => 'Reopen solely to apply the independently approved transcription correction.',
            'approval_reference' => 'HQ/REOPEN/2026/001',
        ])->assertOk()->assertJsonPath('panel.status', 'open');
        $this->postJson("/api/v1/score-adjustments/{$adjustmentId}/decision", $decision)->assertOk()->assertJsonPath('adjustment.status', 'approved');

        $this->assertDatabaseHas('assessment_scores', ['id' => $fixture['score']->id, 'score' => 76, 'entity_version' => 2]);
        $this->assertDatabaseHas('panel_closures', ['panel_id' => $fixture['panel']->id, 'status' => 'reopened']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'panel.reopened', 'approval_reference' => 'HQ/REOPEN/2026/001']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment.adjustment_decided', 'approval_reference' => 'HQ/CORR/2026/001']);
    }

    public function test_interview_invitation_is_branded_hashed_idempotent_and_notified(): void
    {
        Storage::fake('local');
        $fixture = $this->panelFixture(false);
        Sanctum::actingAs($fixture['hq']);
        $payload = ['instructions' => ['Carry the original National ID.', 'Report at least thirty minutes before the scheduled time.']];
        $first = $this->postJson("/api/v1/interview-assignments/{$fixture['assignment']->id}/invitation", $payload)
            ->assertCreated()->assertJsonPath('idempotent', false);
        $this->postJson("/api/v1/interview-assignments/{$fixture['assignment']->id}/invitation", $payload)
            ->assertOk()->assertJsonPath('idempotent', true);

        $path = $first->json('invitation.document_path');
        Storage::disk('local')->assertExists($path);
        $bytes = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $first->json('invitation.sha256'));
        $this->assertDatabaseHas('notifications', ['application_id' => $fixture['assignment']->application_id, 'event_code' => 'interview.invited', 'status' => 'delivered']);
        $this->assertDatabaseHas('notification_attempts', ['channel' => 'in_portal', 'status' => 'delivered']);
    }

    public function test_approved_appeal_records_causal_eligibility_rerun(): void
    {
        $fixture = $this->recruitmentFixture(['status' => 'ineligible']);
        Sanctum::actingAs($fixture['user']);
        $appealId = $this->postJson("/api/v1/applications/{$fixture['application']->id}/appeals", [
            'category' => 'eligibility',
            'grounds' => 'The verified evidence was corrected after the original eligibility outcome was recorded.',
            'evidence_references' => [['type' => 'verified_value', 'field' => 'date_of_birth']],
        ])->assertCreated()->json('appeal_id');

        $hq = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        Sanctum::actingAs($hq);
        $this->postJson("/api/v1/appeals/{$appealId}/decision", [
            'decision' => 'approve',
            'reason' => 'The corrected verified evidence is material and requires deterministic re-evaluation.',
            'approval_reference' => 'HQ/APPEAL/2026/001',
        ])->assertOk()->assertJsonPath('appeal.status', 'approved');

        $appeal = DB::table('appeals')->where('id', $appealId)->first();
        $this->assertNotNull($appeal->resulting_eligibility_run_id);
        $this->assertDatabaseHas('eligibility_runs', ['id' => $appeal->resulting_eligibility_run_id, 'application_id' => $fixture['application']->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appeal.decided', 'approval_reference' => 'HQ/APPEAL/2026/001']);
    }

    public function test_reserve_replacement_requires_strict_order_independent_approval_and_fit_result(): void
    {
        $fixture = $this->recruitmentFixture(['status' => 'final_selected', 'reference' => 'UPS/2026/WRD/000801']);
        $reserveUser = User::factory()->create(['user_type' => 'applicant']);
        $reserveApplicant = Applicant::query()->create([
            'user_id' => $reserveUser->id,
            'nin_encrypted' => 'CM900000000099',
            'nin_hash' => Nin::fingerprint('CM900000000099'),
            'first_name' => 'Synthetic',
            'last_name' => 'Reserve',
            'date_of_birth' => '2001-05-12',
            'sex' => 'Female',
            'nationality' => 'Ugandan',
            'primary_phone' => '+256700000099',
        ]);
        $reserve = Application::query()->create([
            'applicant_id' => $reserveApplicant->id,
            'recruitment_campaign_id' => $fixture['campaign']->id,
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'reference' => 'UPS/2026/WRD/000802',
            'status' => 'reserve',
            'active' => true,
            'draft_data' => [],
            'entity_version' => 1,
        ]);
        $hq = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        $council = User::factory()->create(['user_type' => 'prisons_council_secretariat']);
        $rankingId = (string) Str::ulid();
        $selectionId = (string) Str::ulid();
        $selectedOutcomeId = (string) Str::ulid();
        $reserveOutcomeId = (string) Str::ulid();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('ranking_runs')->insert(['id' => $rankingId, 'recruitment_post_id' => $fixture['post']->id, 'campaign_version_id' => $fixture['version']->id, 'run_number' => 1, 'scope_dimension' => 'national', 'input_snapshot' => '[]', 'score_formula' => '{}', 'tie_break_policy' => '[]', 'fingerprint' => str_repeat('c', 64), 'run_by' => $hq->id, 'run_at' => now(), ...$timestamps]);
        DB::table('selection_runs')->insert(['id' => $selectionId, 'ranking_run_id' => $rankingId, 'recruitment_post_id' => $fixture['post']->id, 'campaign_version_id' => $fixture['version']->id, 'run_number' => 1, 'mode' => 'final', 'status' => 'certified', 'parameters' => '{}', 'offline_readiness' => '{}', 'input_fingerprint' => str_repeat('d', 64), 'output_fingerprint' => str_repeat('e', 64), 'run_by' => $hq->id, 'certified_by' => $council->id, 'certified_at' => now(), ...$timestamps]);
        DB::table('selection_outcomes')->insert([
            ['id' => $selectedOutcomeId, 'selection_run_id' => $selectionId, 'application_id' => $fixture['application']->id, 'bucket_key' => 'national', 'outcome' => 'selected', 'position' => 1, 'score' => 90, 'decision_trace' => '{}', ...$timestamps],
            ['id' => $reserveOutcomeId, 'selection_run_id' => $selectionId, 'application_id' => $reserve->id, 'bucket_key' => 'national', 'outcome' => 'reserve', 'position' => 2, 'score' => 85, 'decision_trace' => '{}', ...$timestamps],
        ]);
        DB::table('reserve_list_entries')->insert(['id' => (string) Str::ulid(), 'selection_run_id' => $selectionId, 'application_id' => $reserve->id, 'bucket_key' => 'national', 'position' => 1, 'status' => 'available', ...$timestamps]);
        DB::table('final_selections')->insert(['id' => (string) Str::ulid(), 'application_id' => $fixture['application']->id, 'selection_outcome_id' => $selectedOutcomeId, 'status' => 'approved', 'approved_by' => $council->id, 'approved_at' => now(), ...$timestamps]);

        Sanctum::actingAs($hq);
        $recommendationId = $this->postJson('/api/v1/training/replacement-recommendations', ['replaced_application_id' => $fixture['application']->id, 'selection_run_id' => $selectionId, 'trigger' => 'withdrawal', 'reason' => 'The selected candidate formally withdrew before training intake reporting.'])->assertCreated()->json('recommendation.id');
        Sanctum::actingAs($council);
        $decision = ['decision' => 'approve', 'reason' => 'Council reviewed the vacancy and strict reserve order.', 'approval_reference' => 'COUNCIL/REPL/2026/001'];
        $this->postJson("/api/v1/training/replacement-recommendations/{$recommendationId}/decision", $decision)->assertConflict();

        $scheduleId = (string) Str::ulid();
        $medicalId = (string) Str::ulid();
        DB::table('medical_schedules')->insert(['id' => $scheduleId, 'recruitment_post_id' => $fixture['post']->id, 'facility' => 'Synthetic Medical Board', 'scheduled_date' => now()->toDateString(), 'reporting_time' => '08:00', ...$timestamps]);
        DB::table('medical_results')->insert(['id' => $medicalId, 'application_id' => $reserve->id, 'medical_schedule_id' => $scheduleId, 'outcome' => 'Fit', 'recorded_by' => $hq->id, 'recorded_at' => now(), 'entity_version' => 1, ...$timestamps]);
        $this->postJson("/api/v1/training/replacement-recommendations/{$recommendationId}/decision", $decision)->assertOk()->assertJsonPath('recommendation.status', 'approved');

        $this->assertDatabaseHas('final_selections', ['application_id' => $fixture['application']->id, 'status' => 'replaced']);
        $this->assertDatabaseHas('final_selections', ['application_id' => $reserve->id, 'status' => 'approved', 'medical_result_id' => $medicalId]);
        $this->assertDatabaseHas('reserve_list_entries', ['application_id' => $reserve->id, 'status' => 'promoted']);
        $this->assertDatabaseHas('applications', ['id' => $reserve->id, 'status' => 'final_selected']);
    }

    /** @return array{panel_head: User, hq: User, panel: Panel, assignment: InterviewAssignment, score: AssessmentScore} */
    private function panelFixture(bool $closed = true): array
    {
        $fixture = $this->recruitmentFixture(['status' => 'eligible', 'reference' => 'UPS/2026/WRD/000777']);
        $panelHead = User::factory()->create(['user_type' => 'panel_head']);
        $hq = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        $regionId = (string) Str::ulid();
        $centreId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        DB::table('prison_regions')->insert(['id' => $regionId, 'code' => 'SYN-R', 'name' => 'Synthetic Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recruitment_centres')->insert(['id' => $centreId, 'prison_region_id' => $regionId, 'code' => 'SYN-C', 'name' => 'Synthetic Centre', 'address' => 'Synthetic address', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('centre_sessions')->insert(['id' => $sessionId, 'recruitment_centre_id' => $centreId, 'recruitment_post_id' => $fixture['post']->id, 'code' => 'AM', 'session_date' => now()->addDay()->toDateString(), 'reporting_time' => '08:00', 'room' => 'Room 1', 'capacity' => 20, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $panel = Panel::query()->create(['centre_session_id' => $sessionId, 'code' => 'P1', 'name' => 'Synthetic Panel', 'capacity' => 20, 'status' => $closed ? 'closed' : 'open']);
        $panelHead->scopes()->create(['scope_type' => 'panel', 'scope_id' => $panel->id, 'allowed_tasks' => ['*']]);
        $assignment = InterviewAssignment::query()->create(['application_id' => $fixture['application']->id, 'centre_session_id' => $sessionId, 'panel_id' => $panel->id, 'assignment_order' => 1, 'algorithm_version' => 'test', 'input_fingerprint' => str_repeat('a', 64)]);
        $definition = AssessmentDefinition::query()->create(['recruitment_post_id' => $fixture['post']->id, 'campaign_version_id' => $fixture['version']->id, 'code' => 'INT', 'name' => 'Interview', 'component_type' => 'interview', 'maximum_mark' => 100, 'weight' => 100, 'mandatory' => true, 'assessor_model' => 'single', 'aggregation_method' => 'single']);
        $score = AssessmentScore::query()->create(['interview_assignment_id' => $assignment->id, 'assessment_definition_id' => $definition->id, 'assessor_id' => $panelHead->id, 'score' => 70, 'status' => 'submitted', 'entity_version' => 1, 'submitted_at' => now()]);
        if ($closed) {
            DB::table('panel_closures')->insert(['id' => (string) Str::ulid(), 'panel_id' => $panel->id, 'closed_by' => $panelHead->id, 'closed_at' => now(), 'score_fingerprint' => str_repeat('b', 64), 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['panel_head' => $panelHead, 'hq' => $hq, 'panel' => $panel, 'assignment' => $assignment, 'score' => $score];
    }
}
