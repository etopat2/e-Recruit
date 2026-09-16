<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use App\Services\ScopeAuthorizer;
use App\Support\Nin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OperationsLookupController extends Controller
{
    private const Roles = [
        'verification_officer', 'centre_coordinator', 'regional_recruitment_officer',
        'hq_recruitment_administrator', 'attendance_officer', 'panel_head', 'medical_officer',
        'prisons_council_secretariat', 'training_school_officer', 'written_examination_officer',
    ];

    public function index(Request $request, ScopeAuthorizer $scopeAuthorizer): JsonResponse
    {
        abort_unless($request->user()->hasRole(...self::Roles), 403);
        $user = $request->user();
        $posts = DB::table('recruitment_posts')
            ->join('recruitment_campaigns', 'recruitment_campaigns.id', '=', 'recruitment_posts.recruitment_campaign_id')
            ->where('recruitment_posts.active', true)
            ->whereIn('recruitment_campaigns.status', ['published', 'active'])
            ->orderByDesc('recruitment_campaigns.year')
            ->orderBy('recruitment_posts.name')
            ->get(['recruitment_posts.id', 'recruitment_posts.name', 'recruitment_posts.code', 'recruitment_campaigns.name as campaign_name'])
            ->map(fn (object $post): array => [
                'id' => $post->id,
                'label' => "{$post->campaign_name} — {$post->name}",
                'description' => $post->code,
            ]);
        $regions = DB::table('prison_regions')->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $region): array => ['id' => $region->id, 'label' => $region->name]);
        $assignments = DB::table('interview_assignments')
            ->join('applications', 'applications.id', '=', 'interview_assignments.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'interview_assignments.centre_session_id')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->join('panels', 'panels.id', '=', 'interview_assignments.panel_id')
            ->whereIn('centre_sessions.status', ['scheduled', 'open'])
            ->orderBy('centre_sessions.session_date')->orderBy('centre_sessions.reporting_time')->orderBy('applications.reference')
            ->limit(500)
            ->get([
                'interview_assignments.id', 'applications.id as application_id', 'applications.reference',
                'applicants.first_name', 'applicants.middle_names', 'applicants.last_name',
                'recruitment_centres.name as centre_name', 'panels.name as panel_name',
                'centre_sessions.session_date', 'centre_sessions.reporting_time',
            ]);
        $assignments = $this->visibleRows($assignments, $user, $scopeAuthorizer)->map(fn (object $assignment): array => [
            'id' => $assignment->id,
            'application_id' => $assignment->application_id,
            'label' => $this->candidateLabel($assignment),
            'description' => "{$assignment->centre_name} • {$assignment->panel_name} • {$assignment->session_date} {$assignment->reporting_time}",
        ]);

        $panels = DB::table('panels')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'panels.centre_session_id')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->join('interview_assignments', 'interview_assignments.panel_id', '=', 'panels.id')
            ->where('panels.status', 'open')
            ->whereIn('centre_sessions.status', ['scheduled', 'open'])
            ->orderBy('centre_sessions.session_date')->orderBy('recruitment_centres.name')->orderBy('panels.code')
            ->get([
                'panels.id', 'panels.name', 'panels.code', 'interview_assignments.application_id',
                'recruitment_centres.name as centre_name', 'centre_sessions.session_date',
            ])->unique('id');
        $panels = $this->visibleRows($panels, $user, $scopeAuthorizer)->map(function (object $panel): array {
            $head = DB::table('panel_members')->join('users', 'users.id', '=', 'panel_members.user_id')
                ->where('panel_members.panel_id', $panel->id)
                ->whereIn('panel_members.panel_role', ['head', 'panel_head'])
                ->orderByDesc('panel_members.effective_from')->value('users.name');

            return [
                'id' => $panel->id,
                'label' => "{$panel->centre_name} — {$panel->name}",
                'description' => "{$panel->session_date} • Head: ".($head ?: 'Not assigned'),
            ];
        });

        $medicalFacilities = DB::table('medical_facilities')
            ->leftJoin('prison_regions', 'prison_regions.id', '=', 'medical_facilities.prison_region_id')
            ->where('medical_facilities.active', true)
            ->orderBy('medical_facilities.name')
            ->get(['medical_facilities.id', 'medical_facilities.name', 'medical_facilities.location', 'medical_facilities.region_attribution', 'prison_regions.name as region_name'])
            ->map(fn (object $facility): array => [
                'id' => $facility->id,
                'label' => $facility->name,
                'description' => $facility->location.' · '.($facility->region_name ?: $facility->region_attribution ?: 'Region attribution pending'),
            ]);

        $medicalSchedules = DB::table('medical_schedules')
            ->join('recruitment_posts', 'recruitment_posts.id', '=', 'medical_schedules.recruitment_post_id')
            ->orderByDesc('medical_schedules.scheduled_date')
            ->limit(200)
            ->get(['medical_schedules.id', 'medical_schedules.recruitment_post_id', 'medical_schedules.facility', 'medical_schedules.scheduled_date', 'medical_schedules.reporting_time', 'recruitment_posts.name as post_name'])
            ->map(fn (object $schedule): array => [
                'id' => $schedule->id,
                'post_id' => $schedule->recruitment_post_id,
                'label' => "{$schedule->facility} — {$schedule->scheduled_date} {$schedule->reporting_time}",
                'description' => $schedule->post_name,
            ]);

        $selectionOutcomes = DB::table('selection_outcomes')
            ->join('selection_runs', 'selection_runs.id', '=', 'selection_outcomes.selection_run_id')
            ->join('applications', 'applications.id', '=', 'selection_outcomes.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->where('selection_runs.status', 'certified')->where('selection_outcomes.outcome', 'selected')
            ->orderBy('applications.reference')->limit(500)
            ->get(['selection_outcomes.id', 'selection_outcomes.application_id', 'applications.reference', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name']);
        $selectionOutcomes = $this->visibleRows($selectionOutcomes, $user, $scopeAuthorizer)->map(fn (object $outcome): array => [
            'id' => $outcome->id,
            'application_id' => $outcome->application_id,
            'label' => $this->candidateLabel($outcome),
            'description' => 'Certified selected outcome',
        ]);

        $medicalResults = DB::table('medical_results')
            ->join('applications', 'applications.id', '=', 'medical_results.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->where('medical_results.outcome', 'Fit')->orderByDesc('medical_results.recorded_at')->limit(500)
            ->get(['medical_results.id', 'medical_results.application_id', 'applications.reference', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'medical_results.recorded_at']);
        $medicalResults = $this->visibleRows($medicalResults, $user, $scopeAuthorizer)->map(fn (object $result): array => [
            'id' => $result->id,
            'application_id' => $result->application_id,
            'label' => $this->candidateLabel($result),
            'description' => "Fit result • {$result->recorded_at}",
        ]);

        $finalSelections = DB::table('final_selections')
            ->join('applications', 'applications.id', '=', 'final_selections.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->where('final_selections.status', 'approved')->orderBy('applications.reference')->limit(500)
            ->get(['final_selections.id', 'final_selections.application_id', 'applications.reference', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name']);
        $finalSelections = $this->visibleRows($finalSelections, $user, $scopeAuthorizer)->map(fn (object $selection): array => [
            'id' => $selection->id,
            'application_id' => $selection->application_id,
            'label' => $this->candidateLabel($selection),
            'description' => 'Approved final selection',
        ]);

        $trainingInvites = DB::table('training_invites')
            ->join('final_selections', 'final_selections.id', '=', 'training_invites.final_selection_id')
            ->join('applications', 'applications.id', '=', 'final_selections.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->orderByDesc('training_invites.reporting_date')->limit(500)
            ->get(['training_invites.id', 'applications.id as application_id', 'applications.reference', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'training_invites.location', 'training_invites.reporting_date', 'training_invites.reporting_time']);
        $trainingInvites = $this->visibleRows($trainingInvites, $user, $scopeAuthorizer)->map(fn (object $invite): array => [
            'id' => $invite->id,
            'application_id' => $invite->application_id,
            'label' => $this->candidateLabel($invite),
            'description' => "{$invite->location} • {$invite->reporting_date} {$invite->reporting_time}",
        ]);

        $selectionRuns = DB::table('selection_runs')
            ->join('recruitment_posts', 'recruitment_posts.id', '=', 'selection_runs.recruitment_post_id')
            ->where('selection_runs.status', 'certified')->orderByDesc('selection_runs.certified_at')->limit(100)
            ->get(['selection_runs.id', 'selection_runs.run_number', 'selection_runs.certified_at', 'recruitment_posts.name as post_name'])
            ->map(fn (object $run): array => [
                'id' => $run->id,
                'label' => "{$run->post_name} — certified run {$run->run_number}",
                'description' => (string) $run->certified_at,
            ]);

        $recommendations = DB::table('reserve_replacement_recommendations')
            ->join('applications as replaced', 'replaced.id', '=', 'reserve_replacement_recommendations.replaced_application_id')
            ->join('applicants as replaced_applicant', 'replaced_applicant.id', '=', 'replaced.applicant_id')
            ->join('applications as reserve', 'reserve.id', '=', 'reserve_replacement_recommendations.reserve_application_id')
            ->join('applicants as reserve_applicant', 'reserve_applicant.id', '=', 'reserve.applicant_id')
            ->where('reserve_replacement_recommendations.status', 'pending_approval')
            ->orderBy('reserve_replacement_recommendations.created_at')->limit(200)
            ->get([
                'reserve_replacement_recommendations.id', 'replaced.id as application_id', 'replaced.reference as replaced_reference',
                'replaced_applicant.first_name as replaced_first_name', 'replaced_applicant.middle_names as replaced_middle_names', 'replaced_applicant.last_name as replaced_last_name',
                'reserve.reference as reserve_reference', 'reserve_applicant.first_name as reserve_first_name', 'reserve_applicant.middle_names as reserve_middle_names', 'reserve_applicant.last_name as reserve_last_name',
            ]);
        $recommendations = $this->visibleRows($recommendations, $user, $scopeAuthorizer)->map(fn (object $recommendation): array => [
            'id' => $recommendation->id,
            'label' => $this->personName($recommendation, 'replaced_').' ('.$recommendation->replaced_reference.')',
            'description' => 'Proposed replacement: '.$this->personName($recommendation, 'reserve_').' ('.$recommendation->reserve_reference.')',
        ]);

        $centreSessions = DB::table('centre_sessions')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->join('recruitment_posts', 'recruitment_posts.id', '=', 'centre_sessions.recruitment_post_id')
            ->whereIn('centre_sessions.status', ['scheduled', 'open'])
            ->orderBy('centre_sessions.session_date')->limit(300)
            ->get(['centre_sessions.id', 'centre_sessions.recruitment_post_id', 'recruitment_centres.name as centre_name', 'recruitment_posts.name as post_name', 'centre_sessions.session_date', 'centre_sessions.reporting_time'])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'post_id' => $session->recruitment_post_id,
                'label' => "{$session->centre_name} — {$session->session_date} {$session->reporting_time}",
                'description' => $session->post_name,
            ]);

        return response()->json(['data' => [
            'posts' => $posts->values(),
            'regions' => $regions->values(),
            'hard_copy' => [
                'can_receive' => $user->hasRole('verification_officer'),
                'receiving_point' => config('erecruit.hard_copy.receiving_point'),
                'transmission_notice' => 'Units and regions are transmission channels only; final receipt is recorded at headquarters.',
            ],
            'centre_sessions' => $centreSessions,
            'interview_assignments' => $assignments,
            'panels' => $panels,
            'medical_facilities' => $medicalFacilities,
            'medical_schedules' => $medicalSchedules,
            'selection_outcomes' => $selectionOutcomes,
            'medical_results' => $medicalResults,
            'final_selections' => $finalSelections,
            'training_invites' => $trainingInvites,
            'selection_runs' => $selectionRuns,
            'replacement_recommendations' => $recommendations,
        ]]);
    }

    public function applications(Request $request, ScopeAuthorizer $scopeAuthorizer): JsonResponse
    {
        abort_unless($request->user()->hasRole(...self::Roles), 403);
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'context' => ['nullable', Rule::in(['general', 'hard_copy', 'medical', 'replacement'])],
        ]);
        if (($data['context'] ?? 'general') === 'hard_copy') {
            abort_unless($request->user()->hasRole('verification_officer'), 403, 'Only an authorised verification officer may search the receipt register.');
        }
        $search = trim($data['search']);
        $like = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search)).'%';
        $ninHash = null;
        try {
            $ninHash = Nin::fingerprint($search);
        } catch (InvalidArgumentException) {
            // Names and application references remain valid partial-search inputs.
        }
        $query = Application::query()
            ->select('applications.*')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->with('applicant:id,first_name,middle_names,last_name')
            ->whereNotNull('applications.reference')
            ->where(function ($candidate) use ($like, $ninHash): void {
                $candidate->whereRaw('LOWER(applications.reference) LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('LOWER(applicants.first_name) LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('LOWER(applicants.middle_names) LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('LOWER(applicants.last_name) LIKE ? ESCAPE \'\\\'', [$like]);
                if ($ninHash !== null) {
                    $candidate->orWhere('applicants.nin_hash', $ninHash);
                }
            });
        match ($data['context'] ?? 'general') {
            'hard_copy' => $query->whereIn('applications.status', ['awaiting_hard_copies', 'hard_copies_received', 'under_verification']),
            'medical' => $query->whereExists(function ($selection): void {
                $selection->selectRaw('1')->from('selection_outcomes')
                    ->join('selection_runs', 'selection_runs.id', '=', 'selection_outcomes.selection_run_id')
                    ->whereColumn('selection_outcomes.application_id', 'applications.id')
                    ->where('selection_outcomes.outcome', 'selected')->where('selection_runs.status', 'certified');
            }),
            'replacement' => $query->whereExists(function ($selection): void {
                $selection->selectRaw('1')->from('final_selections')
                    ->whereColumn('final_selections.application_id', 'applications.id')
                    ->where('final_selections.status', 'approved');
            }),
            default => $query,
        };
        $applications = $query->orderBy('applications.reference')->limit(100)->get()
            ->filter(fn (Application $application): bool => $scopeAuthorizer->canViewApplication($request->user(), $application))
            ->take(7)
            ->map(function (Application $application): array {
                $requirements = DB::table('campaign_document_requirements')
                    ->where('recruitment_post_id', $application->recruitment_post_id)
                    ->where('campaign_version_id', $application->campaign_version_id)
                    ->where(function ($query): void {
                        $query->where('hard_copy_required', true)->orWhere('original_required_at_interview', true);
                    })
                    ->orderBy('label')->get(['document_type', 'label']);
                $checklist = collect(config('erecruit.hard_copy.default_checklist'))
                    ->merge($requirements->map(fn (object $requirement): array => [
                        'document_type' => $requirement->document_type,
                        'label' => $requirement->label,
                    ]))
                    ->when(filled(data_get($application->draft_data, 'skills')), fn (Collection $items) => $items->push([
                        'document_type' => 'skill_certificate',
                        'label' => 'Skill certificate(s)',
                    ]))
                    ->unique('document_type')->values();

                return [
                    'id' => $application->id,
                    'label' => $this->candidateName($application).' — '.$application->reference,
                    'description' => 'Status: '.str_replace('_', ' ', $application->status),
                    'reference' => $application->reference,
                    'applicant_name' => $this->candidateName($application),
                    'post_id' => $application->recruitment_post_id,
                    'document_requirements' => $checklist,
                ];
            })->values();

        return response()->json(['data' => $applications]);
    }

    private function visibleRows(Collection $rows, User $user, ScopeAuthorizer $scopeAuthorizer): Collection
    {
        $applications = Application::query()->whereIn('id', $rows->pluck('application_id')->unique())->get()->keyBy('id');

        return $rows->filter(function (object $row) use ($applications, $user, $scopeAuthorizer): bool {
            $application = $applications->get($row->application_id);

            return $application !== null && $scopeAuthorizer->canViewApplication($user, $application);
        })->values();
    }

    private function candidateLabel(object $row): string
    {
        return $this->personName($row).' — '.$row->reference;
    }

    private function personName(object $row, string $prefix = ''): string
    {
        return collect([
            $row->{$prefix.'first_name'},
            $row->{$prefix.'middle_names'},
            $row->{$prefix.'last_name'},
        ])->filter()->implode(' ');
    }

    private function candidateName(Application $application): string
    {
        return collect([
            $application->applicant->first_name,
            $application->applicant->middle_names,
            $application->applicant->last_name,
        ])->filter()->implode(' ');
    }
}
