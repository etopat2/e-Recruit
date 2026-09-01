<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AssessmentDefinition;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreImport;
use App\Models\AssessmentScoreImportRow;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WrittenScoreImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('written_examination_officer', 'hq_recruitment_administrator'), 403);

        return response()->json(['data' => AssessmentScoreImport::query()
            ->with('definition:id,code,name,recruitment_post_id')
            ->when(! $request->user()->hasRole('hq_recruitment_administrator'), fn ($query) => $query->where('imported_by', $request->user()->id))
            ->latest()->paginate(50)]);
    }

    public function template(Request $request): StreamedResponse|BinaryFileResponse
    {
        abort_unless($request->user()->hasRole('written_examination_officer', 'hq_recruitment_administrator'), 403);
        $format = $request->validate(['format' => ['nullable', 'in:csv,xlsx']])['format'] ?? 'csv';
        $headers = ['application_reference', 'score', 'notes'];
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers): void {
                $stream = fopen('php://output', 'wb');
                fputcsv($stream, $headers, escape: '\\');
                fclose($stream);
            }, 'written-score-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($headers);
        $path = tempnam(sys_get_temp_dir(), 'ups-score-template-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, 'written-score-import-template.xlsx')->deleteFileAfterSend(true);
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('written_examination_officer', 'hq_recruitment_administrator'), 403);
        $data = $request->validate([
            'assessment_definition_id' => ['required', 'exists:assessment_definitions,id'],
            'centre_session_id' => ['nullable', 'exists:centre_sessions,id'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $definition = AssessmentDefinition::query()->findOrFail($data['assessment_definition_id']);
        abort_unless($definition->component_type === 'written', 422, 'Only a written assessment definition accepts bulk score imports.');
        $id = strtolower((string) Str::ulid());
        $extension = strtolower($data['file']->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $diskName = config('erecruit.uploads.disk');
        $sourcePath = "assessment-imports/{$id}/source.{$extension}";
        $sourceStream = fopen($data['file']->getRealPath(), 'rb');
        try {
            if ($sourceStream === false || ! Storage::disk($diskName)->put($sourcePath, $sourceStream)) {
                throw new \RuntimeException('The protected import store is unavailable.');
            }
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'The protected import store is unavailable.']);
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
        }

        try {
            $scoreImport = AssessmentScoreImport::query()->create([
                'id' => $id,
                'assessment_definition_id' => $definition->id,
                'centre_session_id' => $data['centre_session_id'] ?? null,
                'source_filename' => Str::limit(basename($data['file']->getClientOriginalName()), 255, ''),
                'storage_disk' => $diskName,
                'source_path' => $sourcePath,
                'source_sha256' => hash_file('sha256', $data['file']->getRealPath()),
                'status' => 'validating',
                'purpose' => $data['purpose'],
                'imported_by' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($diskName)->delete($sourcePath);
            throw $exception;
        }

        $rows = $this->readRows($data['file']->getRealPath(), $extension);
        [$validatedRows, $rowReports] = $this->validateRows($rows, $definition, $data['centre_session_id'] ?? null, $request);
        $rejected = count(array_filter($rowReports, fn (array $row): bool => $row['errors'] !== []));
        $errorReportPath = null;
        if ($rejected > 0 || $rows === []) {
            if ($rows === []) {
                $rowReports[] = ['row_number' => 1, 'application_reference' => null, 'raw_score' => null, 'notes' => null, 'errors' => ['file' => ['The import contains no data rows.']]];
                $rejected = 1;
            }
            $errorReportPath = "assessment-imports/{$id}/validation-errors.json";
            Storage::disk($diskName)->put($errorReportPath, json_encode(['import_id' => $id, 'errors' => $rowReports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        DB::transaction(function () use ($scoreImport, $validatedRows, $rowReports, $rejected, $errorReportPath, $request): void {
            $scoreIdsByRow = [];
            if ($rejected === 0) {
                foreach ($validatedRows as $row) {
                    $score = AssessmentScore::query()->create([
                        'interview_assignment_id' => $row['assignment_id'],
                        'assessment_definition_id' => $scoreImport->assessment_definition_id,
                        'assessor_id' => $request->user()->id,
                        'score' => $row['score'],
                        'notes' => $row['notes'],
                        'status' => 'submitted',
                        'entity_version' => 1,
                        'submitted_at' => now(),
                    ]);
                    $scoreIdsByRow[$row['row_number']] = $score->id;
                }
            }
            foreach ($rowReports as $row) {
                AssessmentScoreImportRow::query()->create([
                    'assessment_score_import_id' => $scoreImport->id,
                    'row_number' => $row['row_number'],
                    'application_reference' => $row['application_reference'],
                    'raw_score' => $row['raw_score'],
                    'notes' => $row['notes'],
                    'status' => $row['errors'] === [] && $rejected === 0 ? 'imported' : ($row['errors'] === [] ? 'not_applied' : 'rejected'),
                    'errors' => $row['errors'] ?: null,
                    'assessment_score_id' => $scoreIdsByRow[$row['row_number']] ?? null,
                ]);
            }
            $scoreImport->forceFill([
                'status' => $rejected === 0 ? 'imported' : 'rejected',
                'total_rows' => count($rowReports),
                'accepted_rows' => $rejected === 0 ? count($rowReports) : 0,
                'rejected_rows' => $rejected,
                'error_report_path' => $errorReportPath,
                'completed_at' => now(),
            ])->save();
        }, 3);
        $audit->record(
            $rejected === 0 ? 'assessment.written_scores_imported' : 'assessment.written_score_import_rejected',
            $scoreImport,
            after: $scoreImport->fresh()->only(['assessment_definition_id', 'centre_session_id', 'source_sha256', 'status', 'total_rows', 'accepted_rows', 'rejected_rows']),
            actor: $request->user(),
            reason: $data['purpose'],
        );

        return response()->json([
            'import' => $scoreImport->fresh()->load('rows'),
            'validation_errors' => array_values(array_filter($rowReports, fn (array $row): bool => $row['errors'] !== [])),
        ], $rejected === 0 ? 201 : 422);
    }

    public function errorReport(Request $request, AssessmentScoreImport $scoreImport): StreamedResponse
    {
        abort_unless($request->user()->id === $scoreImport->imported_by || $request->user()->hasRole('hq_recruitment_administrator', 'auditor'), 403);
        abort_if($scoreImport->error_report_path === null, 404);

        return Storage::disk($scoreImport->storage_disk)->download(
            $scoreImport->error_report_path,
            "written-score-import-{$scoreImport->id}-errors.json",
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function readRows(string $path, string $extension): array
    {
        $reader = IOFactory::createReader($extension === 'xlsx' ? 'Xlsx' : 'Csv');
        $values = $reader->load($path)->getActiveSheet()->toArray(null, true, true, false);
        $headings = array_map(fn ($heading): string => Str::snake(trim((string) $heading, "\xEF\xBB\xBF \t\r\n")), array_shift($values) ?? []);
        $rows = [];
        foreach ($values as $index => $row) {
            if (collect($row)->every(fn ($value): bool => trim((string) $value) === '')) {
                continue;
            }
            $row = array_pad($row, count($headings), null);
            $rows[] = [...array_combine($headings, array_slice($row, 0, count($headings))), 'row_number' => $index + 2];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function validateRows(array $rows, AssessmentDefinition $definition, ?string $centreSessionId, Request $request): array
    {
        $validated = [];
        $reports = [];
        $references = array_map(fn (array $row): string => trim((string) ($row['application_reference'] ?? '')), $rows);
        foreach ($rows as $row) {
            $reference = trim((string) ($row['application_reference'] ?? ''));
            $rawScore = trim((string) ($row['score'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));
            $errors = [];
            if ($reference === '') {
                $errors['application_reference'][] = 'Application reference is required.';
            }
            if (count(array_keys($references, $reference, true)) > 1) {
                $errors['application_reference'][] = 'The file contains a duplicate application reference.';
            }
            if ($rawScore === '' || ! is_numeric($rawScore)) {
                $errors['score'][] = 'Score must be numeric.';
            } elseif ((float) $rawScore < 0 || (float) $rawScore > (float) $definition->maximum_mark) {
                $errors['score'][] = "Score must be between 0 and {$definition->maximum_mark}.";
            }

            $application = $reference === '' ? null : Application::query()->where('reference', $reference)->first();
            if ($reference !== '' && $application === null) {
                $errors['application_reference'][] = 'Application reference was not found.';
            }
            $assignment = $application?->interviewAssignment;
            if ($application && $application->recruitment_post_id !== $definition->recruitment_post_id) {
                $errors['application_reference'][] = 'Application belongs to a different recruitment post.';
            }
            if ($application && ! Gate::forUser($request->user())->allows('view', $application)) {
                $errors['application_reference'][] = 'Application is outside the importer authorised scope.';
            }
            if ($application && $assignment === null) {
                $errors['application_reference'][] = 'Application has no assessment assignment.';
            }
            if ($assignment && $centreSessionId !== null && $assignment->centre_session_id !== $centreSessionId) {
                $errors['application_reference'][] = 'Application is assigned to a different centre session.';
            }
            if ($assignment && DB::table('panel_closures')->where('panel_id', $assignment->panel_id)->where('status', 'closed')->exists()) {
                $errors['application_reference'][] = 'The assigned panel is closed; use the correction workflow.';
            }
            if ($assignment && AssessmentScore::query()->where('interview_assignment_id', $assignment->id)->where('assessment_definition_id', $definition->id)->where('status', 'submitted')->exists()) {
                $errors['application_reference'][] = 'A submitted score already exists; use the correction workflow.';
            }
            if ($assignment && ! DB::table('attendance_records')->where('interview_assignment_id', $assignment->id)->whereIn('status', ['present', 'late'])->exists()) {
                $errors['application_reference'][] = 'Candidate is not checked in as present or late.';
            }

            $reports[] = [
                'row_number' => (int) $row['row_number'],
                'application_reference' => $reference ?: null,
                'raw_score' => $rawScore ?: null,
                'notes' => $notes ?: null,
                'errors' => $errors,
            ];
            if ($errors === [] && $assignment) {
                $validated[] = [
                    'row_number' => (int) $row['row_number'],
                    'assignment_id' => $assignment->id,
                    'score' => (float) $rawScore,
                    'notes' => $notes ?: null,
                ];
            }
        }

        return [$validated, $reports];
    }
}
