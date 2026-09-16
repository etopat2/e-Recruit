<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\RecruitmentPost;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RecruitmentListDocumentService
{
    public function __construct(private readonly PdfBrandingService $branding) {}

    /** @return array{path: string, sha256: string, row_count: int, title: string} */
    public function generate(object $export): array
    {
        $scope = is_string($export->scope) ? json_decode($export->scope, true, flags: JSON_THROW_ON_ERROR) : (array) $export->scope;
        $post = RecruitmentPost::query()->with('campaign')->findOrFail($scope['recruitment_post_id']);
        $type = (string) $export->export_type;
        $rows = match ($type) {
            'interview_shortlist' => $this->interviewRows($post->id),
            'medical_examination_shortlist' => $this->medicalRows($post->id),
            'final_successful_candidates' => $this->finalRows($post->id),
            default => throw new RuntimeException("Unsupported recruitment document type [{$type}]."),
        };
        $title = match ($type) {
            'interview_shortlist' => 'Shortlisted Candidates for Interviews',
            'medical_examination_shortlist' => 'List of Selected Candidates for Medical Examination',
            'final_successful_candidates' => 'List of Successful Candidates',
        };
        $branding = $this->branding->assets(requireTahoma: true);
        $facilities = DB::table('medical_facilities')->where('active', true)->orderBy('code')->get(['code', 'name', 'location']);
        $schedules = DB::table('medical_schedules')->where('recruitment_post_id', $post->id)->orderBy('scheduled_date')->orderBy('reporting_time')->get();
        $training = DB::table('training_invites')
            ->join('final_selections', 'final_selections.id', '=', 'training_invites.final_selection_id')
            ->join('applications', 'applications.id', '=', 'final_selections.application_id')
            ->where('applications.recruitment_post_id', $post->id)
            ->orderBy('training_invites.reporting_date')
            ->first(['training_invites.reporting_date', 'training_invites.reporting_time', 'training_invites.location', 'training_invites.instructions']);
        $bytes = Pdf::loadView('pdf.recruitment-list', [
            ...$branding,
            'type' => $type,
            'title' => $title,
            'campaign' => $post->campaign,
            'post' => $post,
            'rows' => $rows,
            'facilities' => $facilities,
            'schedules' => $schedules,
            'training' => $training,
            'generatedAt' => now('Africa/Kampala'),
            'documentId' => $export->id,
        ])->setPaper('a4')->setOption('isRemoteEnabled', false)->output();
        $path = "official-lists/{$post->campaign->code}/{$post->code}/{$type}-{$export->id}.pdf";
        Storage::disk(config('erecruit.uploads.disk'))->put($path, $bytes);

        return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'row_count' => $rows->count(), 'title' => $title];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function interviewRows(string $postId): Collection
    {
        $rows = DB::table('interview_assignments as assignments')
            ->join('applications', 'applications.id', '=', 'assignments.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->join('centre_sessions as sessions', 'sessions.id', '=', 'assignments.centre_session_id')
            ->join('recruitment_centres as centres', 'centres.id', '=', 'sessions.recruitment_centre_id')
            ->leftJoin('applicant_addresses as origin', function (JoinClause $join): void {
                $join->on('origin.application_id', '=', 'applications.id')->where('origin.address_type', 'origin');
            })
            ->leftJoin('administrative_units as districts', 'districts.id', '=', 'origin.district_id')
            ->where('applications.recruitment_post_id', $postId)
            ->orderBy('districts.name')->orderBy('applicants.first_name')->orderBy('applicants.last_name')
            ->get([
                'applications.id as application_id', 'applications.reference', 'applications.draft_data',
                'applicants.id as applicant_id', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'applicants.sex', 'applicants.date_of_birth',
                'districts.name as district', 'centres.name as recruitment_centre', 'sessions.session_date',
            ]);

        return $this->candidateRows($rows, includeFullNin: true);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function medicalRows(string $postId): Collection
    {
        $runId = DB::table('selection_runs')->where('recruitment_post_id', $postId)->where('status', 'certified')->latest('certified_at')->value('id');
        if ($runId === null) {
            return collect();
        }
        $rows = DB::table('selection_outcomes as outcomes')
            ->join('applications', 'applications.id', '=', 'outcomes.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->leftJoin('applicant_addresses as origin', function (JoinClause $join): void {
                $join->on('origin.application_id', '=', 'applications.id')->where('origin.address_type', 'origin');
            })
            ->leftJoin('administrative_units as districts', 'districts.id', '=', 'origin.district_id')
            ->where('outcomes.selection_run_id', $runId)->where('outcomes.outcome', 'selected')
            ->orderBy('districts.name')->orderBy('outcomes.position')
            ->get([
                'applications.id as application_id', 'applications.reference', 'applications.draft_data',
                'applicants.id as applicant_id', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'applicants.sex', 'applicants.date_of_birth',
                'districts.name as district', 'outcomes.position',
            ]);

        return $this->candidateRows($rows);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function finalRows(string $postId): Collection
    {
        $rows = DB::table('final_selections as final')
            ->join('applications', 'applications.id', '=', 'final.application_id')
            ->join('applicants', 'applicants.id', '=', 'applications.applicant_id')
            ->leftJoin('applicant_addresses as origin', function (JoinClause $join): void {
                $join->on('origin.application_id', '=', 'applications.id')->where('origin.address_type', 'origin');
            })
            ->leftJoin('administrative_units as districts', 'districts.id', '=', 'origin.district_id')
            ->where('applications.recruitment_post_id', $postId)->where('final.status', 'approved')
            ->orderBy('districts.name')->orderBy('applicants.first_name')->orderBy('applicants.last_name')
            ->get([
                'applications.id as application_id', 'applications.reference', 'applications.draft_data',
                'applicants.id as applicant_id', 'applicants.first_name', 'applicants.middle_names', 'applicants.last_name', 'applicants.sex', 'applicants.date_of_birth',
                'districts.name as district',
            ]);

        return $this->candidateRows($rows);
    }

    /** @param Collection<int, object> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function candidateRows(Collection $rows, bool $includeFullNin = false): Collection
    {
        $applicants = Applicant::query()->whereIn('id', $rows->pluck('applicant_id'))->get()->keyBy('id');

        return $rows->values()->map(function (object $row, int $index) use ($applicants, $includeFullNin): array {
            $applicant = $applicants->get($row->applicant_id);
            $nin = (string) ($applicant?->nin_encrypted ?? '');
            $draft = is_string($row->draft_data) ? json_decode($row->draft_data, true) : (array) $row->draft_data;
            $district = $row->district ?? data_get($draft, 'origin.district') ?? data_get($draft, 'address.district') ?? 'NOT RECORDED';
            $asAt = isset($row->session_date) && $row->session_date !== null ? CarbonImmutable::parse($row->session_date) : now();

            return [
                'number' => $index + 1,
                'first_name' => mb_strtoupper((string) $row->first_name),
                'middle_names' => mb_strtoupper((string) ($row->middle_names ?? '')),
                'last_name' => mb_strtoupper((string) $row->last_name),
                'full_name' => mb_strtoupper(trim(collect([$row->first_name, $row->middle_names, $row->last_name])->filter()->implode(' '))),
                'nin' => $includeFullNin ? $nin : ($nin === '' ? '' : mb_substr($nin, -5)),
                'sex' => mb_strtoupper((string) $row->sex),
                'age' => $applicant?->date_of_birth?->diffInYears($asAt),
                'district' => mb_strtoupper((string) $district),
                'recruitment_centre' => isset($row->recruitment_centre) ? mb_strtoupper((string) $row->recruitment_centre) : null,
                'reference' => $row->reference,
            ];
        });
    }
}
