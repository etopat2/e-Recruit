<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class SelectionReferenceDirectoryTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_selection_policy_uses_human_ranking_and_policy_directories(): void
    {
        $fixture = $this->recruitmentFixture(['reference' => 'UPS/TEST/000003']);
        $administrator = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        $rankingRunId = (string) Str::ulid();
        DB::table('ranking_runs')->insert([
            'id' => $rankingRunId,
            'recruitment_post_id' => $fixture['post']->id,
            'campaign_version_id' => $fixture['version']->id,
            'run_number' => 3,
            'scope_dimension' => 'national',
            'input_snapshot' => '[]',
            'score_formula' => '{}',
            'tie_break_policy' => '[]',
            'fingerprint' => str_repeat('a', 64),
            'run_by' => $administrator->id,
            'run_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ranking_results')->insert([
            'id' => (string) Str::ulid(),
            'ranking_run_id' => $rankingRunId,
            'application_id' => $fixture['application']->id,
            'bucket_key' => 'central_region',
            'aggregate_score' => 88.5,
            'merit_rank' => 1,
            'tie_break_values' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/v1/selection/lookups')
            ->assertOk()
            ->assertJsonPath('data.ranking_runs.0.id', $rankingRunId)
            ->assertJsonPath('data.ranking_runs.0.label', $fixture['post']->name.' — ranking run 3')
            ->assertJsonPath('data.buckets.0.value', 'central_region')
            ->assertJsonPath('data.buckets.0.label', 'Central Region');
    }
}
