<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\EvidenceComparisonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVerificationRequest;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentVerification;
use App\Models\VerifiedValue;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function show(Application $application): JsonResponse
    {
        $this->authorize('view', $application);
        $application->load(['applicant', 'documents.extraction']);
        $documentIds = $application->documents->pluck('id');
        $fields = DB::table('extracted_fields')
            ->join('document_extractions', 'document_extractions.id', '=', 'extracted_fields.document_extraction_id')
            ->whereIn('document_extractions.document_id', $documentIds)
            ->select('extracted_fields.*', 'document_extractions.document_id')
            ->orderBy('field_key')
            ->get();

        return response()->json([
            'application' => [
                'id' => $application->id,
                'reference' => $application->reference,
                'entered_data' => $application->draft_data,
            ],
            'documents' => $application->documents->map(fn (Document $document): array => [
                'id' => $document->id,
                'type' => $document->document_type,
                'version' => $document->version,
                'preview_url' => route('api.documents.download', $document),
                'quality' => $document->quality_indicators,
                'fields' => $fields->where('document_id', $document->id)->values(),
            ]),
            'comparisons' => DB::table('document_comparisons')->where('application_id', $application->id)->get(),
            'verified_values' => VerifiedValue::query()->whereBelongsTo($application)->where('current', true)->get(),
            'evidence_matrix' => $fields->groupBy('field_key')->map(fn ($values) => $values->map(fn ($field): array => [
                'source_id' => $field->document_id,
                'value' => $field->raw_value,
                'confidence' => $field->confidence,
                'page' => $field->page_number,
                'bounding_polygon' => $field->bounding_polygon === null ? null : json_decode($field->bounding_polygon, true),
            ])->values()),
        ]);
    }

    public function compare(Application $application, EvidenceComparisonService $comparisonService): JsonResponse
    {
        $this->authorize('view', $application);
        $application->load('documents');
        $documentIds = $application->documents->pluck('id');
        $fields = DB::table('extracted_fields')
            ->join('document_extractions', 'document_extractions.id', '=', 'extracted_fields.document_extraction_id')
            ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
            ->whereIn('documents.id', $documentIds)
            ->select('extracted_fields.*', 'documents.document_type', 'documents.id as document_id')
            ->get();
        $entered = [
            'name' => data_get($application->draft_data, 'personal.full_name'),
            'nin' => data_get($application->draft_data, 'personal.nin'),
            'dob' => data_get($application->draft_data, 'personal.date_of_birth'),
        ];
        $results = [];
        foreach (['name', 'nin', 'dob', 'grade', 'index_number'] as $fieldKey) {
            $sources = ['entered_data' => $entered[$fieldKey] ?? null];
            foreach ($fields->where('field_key', $fieldKey) as $field) {
                $sources["{$field->document_type}:{$field->document_id}"] = [
                    'value' => $field->raw_value,
                    'confidence' => $field->confidence,
                ];
            }
            $result = $comparisonService->compare($fieldKey, $sources);
            $results[$fieldKey] = $result;
            foreach ($result['comparisons'] as $comparison) {
                DB::table('document_comparisons')->insert([
                    'id' => (string) Str::ulid(),
                    'application_id' => $application->id,
                    'field_key' => $fieldKey,
                    'left_source_type' => $comparison['left_source'],
                    'left_value' => data_get($result, "sources.{$comparison['left_source']}.value"),
                    'right_source_type' => $comparison['right_source'],
                    'right_value' => data_get($result, "sources.{$comparison['right_source']}.value"),
                    'outcome' => $comparison['outcome'],
                    'similarity' => $comparison['similarity'],
                    'algorithm_version' => '1.0.0',
                    'explanation' => $comparison['explanation'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['matrix' => $results]);
    }

    public function store(StoreVerificationRequest $request, Document $document, AuditService $audit): JsonResponse
    {
        $this->authorize('view', $document);
        $data = $request->validated();
        $verification = DB::transaction(function () use ($data, $document, $request): DocumentVerification {
            $verification = DocumentVerification::query()->create([
                'document_id' => $document->id,
                'action' => $data['action'],
                'outcome' => $data['outcome'],
                'reason' => $data['reason'] ?? null,
                'review_state' => $data['review_state'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            if (in_array($data['action'], ['verify', 'correct'], true)) {
                $current = VerifiedValue::query()
                    ->where('application_id', $document->application_id)
                    ->where('field_key', $data['field_key'])
                    ->where('current', true)
                    ->lockForUpdate()
                    ->first();
                $current?->forceFill(['current' => false])->save();
                VerifiedValue::query()->create([
                    'application_id' => $document->application_id,
                    'supersedes_id' => $current?->id,
                    'field_key' => $data['field_key'],
                    'verified_value' => ['value' => $data['verified_value']],
                    'evidence_references' => $data['evidence_references'],
                    'verification_method' => 'officer_review',
                    'reason' => $data['reason'] ?? null,
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                    'current' => true,
                ]);
            }

            return $verification;
        }, 3);
        $audit->record('verification.recorded', $document, actor: $request->user(), after: $data, reason: $data['reason'] ?? null);

        return response()->json(['verification' => $verification], 201);
    }
}
