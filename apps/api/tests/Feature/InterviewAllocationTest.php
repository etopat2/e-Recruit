<?php

namespace Tests\Feature;

use App\Jobs\IssueInterviewInvitationJob;
use App\Models\Applicant;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\CreatesRecruitmentFixtures;
use Tests\TestCase;

class InterviewAllocationTest extends TestCase
{
    use CreatesRecruitmentFixtures;
    use RefreshDatabase;

    public function test_preview_and_commit_keep_districts_intact_and_balance_centres(): void
    {
        Queue::fake();
        $fixture = $this->recruitmentFixture(['reference' => 'UPS/2026/WRD/000001', 'status' => 'eligible'], ['lc_source_policy' => 'origin_or_residence']);
        $hq = User::factory()->create(['user_type' => 'hq_recruitment_administrator']);
        $regionId = (string) Str::ulid();
        DB::table('prison_regions')->insert(['id' => $regionId, 'code' => 'CENTRAL-TEST', 'name' => 'Central Test Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $centreIds = $this->createCentres($fixture['post']->id, $regionId);

        $districtCounts = ['Alpha District' => 8, 'Bravo District' => 7, 'Charlie District' => 6, 'Delta District' => 5];
        $applicationIdsByDistrict = [];
        $referenceSequence = 1;
        $districtSequence = 1;
        foreach ($districtCounts as $districtName => $count) {
            $districtId = (string) Str::ulid();
            DB::table('administrative_units')->insert(['id' => $districtId, 'code' => 'DIST-'.$districtSequence, 'name' => $districtName, 'level' => 'district', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('district_centre_mappings')->insert([
                'id' => (string) Str::ulid(), 'recruitment_campaign_id' => $fixture['campaign']->id,
                'district_id' => $districtId, 'recruitment_centre_id' => $centreIds[0],
                'effective_from' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            for ($candidate = 0; $candidate < $count; $candidate++) {
                if ($districtSequence === 1 && $candidate === 0) {
                    $application = $fixture['application'];
                    $application->forceFill(['routing_address_type' => 'origin', 'routing_district_id' => $districtId])->save();
                } else {
                    $applicant = Applicant::factory()->create();
                    $referenceSequence++;
                    $application = Application::query()->create([
                        'applicant_id' => $applicant->id,
                        'recruitment_campaign_id' => $fixture['campaign']->id,
                        'recruitment_post_id' => $fixture['post']->id,
                        'campaign_version_id' => $fixture['version']->id,
                        'reference' => 'UPS/2026/WRD/'.str_pad((string) $referenceSequence, 6, '0', STR_PAD_LEFT),
                        'status' => 'eligible', 'routing_address_type' => 'origin', 'routing_district_id' => $districtId,
                        'active' => true, 'draft_data' => ['lc1_letter' => ['address_type' => 'origin']], 'entity_version' => 1,
                    ]);
                }
                $applicationIdsByDistrict[$districtId][] = $application->id;
            }
            $districtSequence++;
        }

        Sanctum::actingAs($hq);
        $preview = $this->postJson('/api/v1/interview-allocation-runs/preview', [
            'recruitment_post_id' => $fixture['post']->id,
            'prison_region_id' => $regionId,
        ])->assertCreated()->assertJsonPath('data.run_number', 1)->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.candidate_count', array_sum($districtCounts));

        $centreLoads = collect($preview->json('data.centres'))->pluck('candidate_count')->map(fn ($count) => (int) $count)->sort()->values();
        $this->assertLessThanOrEqual(1, $centreLoads->last() - $centreLoads->first());
        $this->assertCount(count($districtCounts), $preview->json('data.districts'));

        $runId = $preview->json('data.id');
        $this->postJson("/api/v1/interview-allocation-runs/{$runId}/commit")->assertOk()->assertJsonPath('data.status', 'committed');

        foreach ($applicationIdsByDistrict as $applicationIds) {
            $assignedCentres = DB::table('interview_assignments')
                ->join('centre_sessions', 'centre_sessions.id', '=', 'interview_assignments.centre_session_id')
                ->whereIn('interview_assignments.application_id', $applicationIds)->distinct()
                ->pluck('centre_sessions.recruitment_centre_id');
            $this->assertCount(1, $assignedCentres, 'Candidates from one routing district must never be split between centres.');
        }
        $this->assertDatabaseCount('interview_assignments', array_sum($districtCounts));
        Queue::assertPushed(IssueInterviewInvitationJob::class, array_sum($districtCounts));

        $this->postJson('/api/v1/interview-allocation-runs/preview', [
            'recruitment_post_id' => $fixture['post']->id,
            'prison_region_id' => $regionId,
        ])->assertCreated()->assertJsonPath('data.run_number', 2);
    }

    /** @return list<string> */
    private function createCentres(string $postId, string $regionId): array
    {
        $centreIds = [];
        foreach (['East Centre', 'West Centre'] as $index => $name) {
            $centreId = (string) Str::ulid();
            $sessionId = (string) Str::ulid();
            $centreIds[] = $centreId;
            DB::table('recruitment_centres')->insert(['id' => $centreId, 'prison_region_id' => $regionId, 'code' => 'CENTRE-'.$index, 'name' => $name, 'address' => $name.' address', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('centre_sessions')->insert(['id' => $sessionId, 'recruitment_centre_id' => $centreId, 'recruitment_post_id' => $postId, 'code' => 'SESSION-'.$index, 'session_date' => now()->addDay()->toDateString(), 'reporting_time' => '08:00', 'capacity' => 100, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('panels')->insert(['id' => (string) Str::ulid(), 'centre_session_id' => $sessionId, 'code' => 'PANEL-'.$index, 'name' => $name.' Panel', 'capacity' => 100, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $centreIds;
    }
}
