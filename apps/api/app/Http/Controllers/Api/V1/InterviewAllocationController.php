<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Interviews\DistrictAllocationService;
use App\Http\Controllers\Controller;
use App\Jobs\IssueInterviewInvitationJob;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\InterviewAllocationResult;
use App\Models\InterviewAllocationRun;
use App\Models\InterviewAssignment;
use App\Models\PrisonRegion;
use App\Models\RecruitmentPost;
use App\Services\ApplicationRoutingService;
use App\Services\AuditService;
use App\Services\ScopeAuthorizer;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class InterviewAllocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertAllocator($request);
        $runs = InterviewAllocationRun::query()
            ->with(['post:id,name', 'region:id,name'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (InterviewAllocationRun $run): array => $this->runPayload($run));

        return response()->json(['data' => $runs]);
    }

    public function preview(
        Request $request,
        DistrictAllocationService $allocationService,
        ApplicationRoutingService $routingService,
        ScopeAuthorizer $scopeAuthorizer,
        CanonicalJson $canonicalJson,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAllocator($request);
        $data = $request->validate([
            'recruitment_post_id' => ['required', 'exists:recruitment_posts,id'],
            'prison_region_id' => ['required', 'exists:prison_regions,id'],
        ]);
        $post = RecruitmentPost::query()->with('campaign')->findOrFail($data['recruitment_post_id']);
        $region = PrisonRegion::query()->whereKey($data['prison_region_id'])->where('active', true)->firstOrFail();
        $built = $this->buildAllocation($post, $region, $allocationService, $routingService, $scopeAuthorizer, $request);
        $inputFingerprint = $canonicalJson->hash($built['input']);
        $outputFingerprint = $canonicalJson->hash($built['result']);

        $run = DB::transaction(function () use ($post, $region, $built, $inputFingerprint, $outputFingerprint, $request): InterviewAllocationRun {
            RecruitmentPost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $runNumber = ((int) InterviewAllocationRun::query()
                ->where('recruitment_post_id', $post->id)
                ->where('prison_region_id', $region->id)
                ->max('run_number')) + 1;
            $run = InterviewAllocationRun::query()->create([
                'recruitment_campaign_id' => $post->recruitment_campaign_id,
                'recruitment_post_id' => $post->id,
                'campaign_version_id' => $built['campaign_version_id'],
                'prison_region_id' => $region->id,
                'run_number' => $runNumber,
                'status' => 'preview',
                'algorithm_version' => 'district-lpt-v1',
                'candidate_count' => count($built['result']['assignments']),
                'input_snapshot' => $built['input'],
                'result_snapshot' => $built['result'],
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'run_by' => $request->user()->id,
            ]);
            foreach ($built['result']['districts'] as $district) {
                InterviewAllocationResult::query()->create([
                    'interview_allocation_run_id' => $run->id,
                    'district_id' => $district['district_id'],
                    'recruitment_centre_id' => $district['recruitment_centre_id'],
                    'candidate_count' => $district['candidate_count'],
                    'centre_load_after_assignment' => $district['centre_load_after_assignment'],
                ]);
            }

            return $run;
        }, 3);
        $audit->record('interview.allocation_previewed', $run, actor: $request->user(), after: [
            'algorithm_version' => $run->algorithm_version,
            'candidate_count' => $run->candidate_count,
            'input_fingerprint' => $run->input_fingerprint,
            'output_fingerprint' => $run->output_fingerprint,
        ]);

        return response()->json(['data' => $this->runPayload($run->load(['post:id,name', 'region:id,name']))], 201);
    }

    public function commit(
        Request $request,
        InterviewAllocationRun $allocationRun,
        DistrictAllocationService $allocationService,
        ApplicationRoutingService $routingService,
        ScopeAuthorizer $scopeAuthorizer,
        CanonicalJson $canonicalJson,
        AuditService $audit,
    ): JsonResponse {
        $this->assertAllocator($request);
        abort_unless($allocationRun->status === 'preview', 409, 'Only a preview allocation can be committed.');
        $post = RecruitmentPost::query()->with('campaign')->findOrFail($allocationRun->recruitment_post_id);
        $region = PrisonRegion::query()->findOrFail($allocationRun->prison_region_id);
        $built = $this->buildAllocation($post, $region, $allocationService, $routingService, $scopeAuthorizer, $request);
        if (! hash_equals($allocationRun->input_fingerprint, $canonicalJson->hash($built['input']))
            || ! hash_equals($allocationRun->output_fingerprint, $canonicalJson->hash($built['result']))) {
            abort(409, 'Candidates or interview reference data changed after this preview. Create a new allocation version.');
        }

        $candidateIds = collect($built['result']['assignments'])->pluck('application_id')->all();
        $existingAssignmentIds = InterviewAssignment::query()->whereIn('application_id', $candidateIds)->pluck('id');
        if ($existingAssignmentIds->isNotEmpty() && (
            DB::table('attendance_records')->whereIn('interview_assignment_id', $existingAssignmentIds)->exists()
            || DB::table('assessment_scores')->whereIn('interview_assignment_id', $existingAssignmentIds)->exists()
            || DB::table('interview_invitations')->whereIn('interview_assignment_id', $existingAssignmentIds)->exists()
        )) {
            abort(409, 'This allocation cannot be replaced after attendance, scoring, or invitation evidence exists.');
        }

        $assignmentIds = DB::transaction(function () use ($allocationRun, $built, $candidateIds, $request): array {
            $lockedRun = InterviewAllocationRun::query()->lockForUpdate()->findOrFail($allocationRun->id);
            abort_unless($lockedRun->status === 'preview', 409, 'This allocation preview has already been committed.');
            $maximumOrder = (int) InterviewAssignment::query()->max('assignment_order');
            InterviewAssignment::query()->whereIn('application_id', $candidateIds)
                ->increment('assignment_order', $maximumOrder + 1000);
            $ids = [];
            foreach ($built['result']['assignments'] as $assignmentData) {
                $assignment = InterviewAssignment::query()->updateOrCreate(
                    ['application_id' => $assignmentData['application_id']],
                    [
                        'interview_allocation_run_id' => $lockedRun->id,
                        'centre_session_id' => $assignmentData['centre_session_id'],
                        'panel_id' => $assignmentData['panel_id'],
                        'assignment_order' => $assignmentData['assignment_order'],
                        'algorithm_version' => $lockedRun->algorithm_version,
                        'input_fingerprint' => $lockedRun->input_fingerprint,
                        'manual_adjustment' => false,
                        'adjustment_reason' => null,
                        'assigned_by' => $request->user()->id,
                    ],
                );
                $ids[] = $assignment->id;
                $application = Application::query()->lockForUpdate()->findOrFail($assignment->application_id);
                if ($application->status !== 'interview_scheduled') {
                    $previousStatus = $application->status;
                    $application->forceFill([
                        'status' => 'interview_scheduled',
                        'entity_version' => $application->entity_version + 1,
                    ])->save();
                    ApplicationStatusHistory::query()->create([
                        'application_id' => $application->id,
                        'from_status' => $previousStatus,
                        'to_status' => 'interview_scheduled',
                        'changed_by' => $request->user()->id,
                        'source' => 'interview_allocation',
                    ]);
                }
            }
            $lockedRun->forceFill([
                'status' => 'committed',
                'committed_by' => $request->user()->id,
                'committed_at' => now(),
            ])->save();

            return $ids;
        }, 3);
        foreach ($assignmentIds as $assignmentId) {
            IssueInterviewInvitationJob::dispatch($assignmentId, $request->user()->id)->afterCommit();
        }
        $audit->record('interview.allocation_committed', $allocationRun, actor: $request->user(), after: [
            'assignment_count' => count($assignmentIds),
            'output_fingerprint' => $allocationRun->output_fingerprint,
        ]);

        return response()->json([
            'data' => $this->runPayload($allocationRun->fresh()->load(['post:id,name', 'region:id,name'])),
            'message' => 'Interview assignments committed and invitation generation queued.',
        ]);
    }

    /**
     * @return array{campaign_version_id: string, input: array<string, mixed>, result: array<string, mixed>}
     */
    private function buildAllocation(
        RecruitmentPost $post,
        PrisonRegion $region,
        DistrictAllocationService $allocationService,
        ApplicationRoutingService $routingService,
        ScopeAuthorizer $scopeAuthorizer,
        Request $request,
    ): array {
        $applications = Application::query()
            ->with(['applicant:id,first_name,middle_names,last_name', 'post:id,lc_source_policy'])
            ->where('recruitment_post_id', $post->id)
            ->whereNotNull('reference')
            ->where(function ($query): void {
                $query->whereIn('status', ['eligible', 'interview_scheduled'])
                    ->orWhereExists(function ($eligibility): void {
                        $eligibility->selectRaw('1')->from('eligibility_runs')
                            ->whereColumn('eligibility_runs.application_id', 'applications.id')
                            ->where('eligibility_runs.status', 'PASS');
                    });
            })
            ->orderBy('reference')
            ->get();
        abort_if($applications->isEmpty(), 422, 'There are no validated eligible applications for this recruitment post.');

        $selected = new Collection;
        $unmapped = 0;
        foreach ($applications as $application) {
            $routing = $routingService->resolvePersisted($application);
            $mapping = $this->districtMapping($application, $routing['district_id']);
            if ($mapping === null) {
                $unmapped++;

                continue;
            }
            if ($mapping->prison_region_id !== $region->id) {
                continue;
            }
            abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:schedule', $application), 403, 'One or more applications are outside your authorised scheduling scope.');
            $application->setAttribute('routing_district_name', $mapping->district_name);
            $selected->push($application);
        }
        if ($unmapped > 0) {
            throw ValidationException::withMessages([
                'routing' => "{$unmapped} validated application(s) have no effective district jurisdiction mapping. Resolve them before allocation.",
            ]);
        }
        abort_if($selected->isEmpty(), 422, 'There are no validated candidates routed to this prison region.');

        $candidateIds = $selected->pluck('id')->all();
        $districts = $selected->groupBy('routing_district_id')->map(function (Collection $districtApplications, string $districtId): array {
            $first = $districtApplications->first();

            return [
                'id' => $districtId,
                'name' => (string) $first->routing_district_name,
                'applications' => $districtApplications->map(fn (Application $application): array => [
                    'id' => $application->id,
                    'reference' => (string) $application->reference,
                    'campaign_version_id' => $application->campaign_version_id,
                ])->values()->all(),
            ];
        })->values()->all();
        $centres = $this->centreCapacity($post, $region, $candidateIds);
        try {
            $result = $allocationService->allocate($districts, $centres);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['allocation' => $exception->getMessage()]);
        }
        $campaignVersionId = (string) (DB::table('campaign_versions')
            ->where('recruitment_campaign_id', $post->recruitment_campaign_id)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->value('id') ?: $selected->first()->campaign_version_id);
        $input = [
            'algorithm_version' => 'district-lpt-v1',
            'recruitment_post_id' => $post->id,
            'campaign_version_id' => $campaignVersionId,
            'prison_region_id' => $region->id,
            'districts' => $districts,
            'centres' => $centres,
        ];

        return ['campaign_version_id' => $campaignVersionId, 'input' => $input, 'result' => $result];
    }

    private function districtMapping(Application $application, string $districtId): ?object
    {
        return DB::table('district_centre_mappings')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'district_centre_mappings.recruitment_centre_id')
            ->join('administrative_units', 'administrative_units.id', '=', 'district_centre_mappings.district_id')
            ->where('district_centre_mappings.district_id', $districtId)
            ->where(function ($query) use ($application): void {
                $query->whereNull('district_centre_mappings.recruitment_campaign_id')
                    ->orWhere('district_centre_mappings.recruitment_campaign_id', $application->recruitment_campaign_id);
            })
            ->where('district_centre_mappings.effective_from', '<=', now()->toDateString())
            ->where(function ($query): void {
                $query->whereNull('district_centre_mappings.effective_to')
                    ->orWhere('district_centre_mappings.effective_to', '>=', now()->toDateString());
            })
            ->orderByRaw('CASE WHEN district_centre_mappings.recruitment_campaign_id = ? THEN 0 ELSE 1 END', [$application->recruitment_campaign_id])
            ->orderByDesc('district_centre_mappings.effective_from')
            ->select('recruitment_centres.prison_region_id', 'administrative_units.name as district_name')
            ->first();
    }

    /**
     * @param  list<string>  $candidateIds
     * @return list<array<string, mixed>>
     */
    private function centreCapacity(RecruitmentPost $post, PrisonRegion $region, array $candidateIds): array
    {
        $centres = DB::table('recruitment_centres')
            ->where('prison_region_id', $region->id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return $centres->map(function (object $centre) use ($post, $candidateIds): ?array {
            $panels = DB::table('panels')
                ->join('centre_sessions', 'centre_sessions.id', '=', 'panels.centre_session_id')
                ->where('centre_sessions.recruitment_centre_id', $centre->id)
                ->where('centre_sessions.recruitment_post_id', $post->id)
                ->whereIn('centre_sessions.status', ['scheduled', 'open'])
                ->where('panels.status', 'open')
                ->orderBy('centre_sessions.session_date')
                ->orderBy('centre_sessions.reporting_time')
                ->orderBy('panels.code')
                ->get([
                    'panels.id', 'panels.capacity', 'centre_sessions.id as session_id',
                    'centre_sessions.session_date', 'centre_sessions.reporting_time',
                ]);
            if ($panels->isEmpty()) {
                return null;
            }
            $panelIds = $panels->pluck('id');
            $existingLoads = DB::table('interview_assignments')
                ->whereIn('panel_id', $panelIds)
                ->when($candidateIds !== [], fn ($query) => $query->whereNotIn('application_id', $candidateIds))
                ->selectRaw('panel_id, count(*) as aggregate')
                ->groupBy('panel_id')
                ->pluck('aggregate', 'panel_id');

            return [
                'id' => $centre->id,
                'name' => $centre->name,
                'panels' => $panels->map(fn (object $panel): array => [
                    'id' => $panel->id,
                    'session_id' => $panel->session_id,
                    'session_date' => (string) $panel->session_date,
                    'reporting_time' => (string) $panel->reporting_time,
                    'capacity' => (int) $panel->capacity,
                    'existing_load' => (int) ($existingLoads[$panel->id] ?? 0),
                ])->all(),
            ];
        })->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    private function runPayload(InterviewAllocationRun $run): array
    {
        return [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'status' => $run->status,
            'algorithm_version' => $run->algorithm_version,
            'candidate_count' => $run->candidate_count,
            'post' => ['name' => $run->post->name],
            'region' => ['name' => $run->region->name],
            'centres' => $run->result_snapshot['centres'] ?? [],
            'districts' => $run->result_snapshot['districts'] ?? [],
            'created_at' => $run->created_at,
            'committed_at' => $run->committed_at,
        ];
    }

    private function assertAllocator(Request $request): void
    {
        abort_unless($request->user()->hasRole('hq_recruitment_administrator', 'regional_recruitment_officer'), 403);
    }
}
