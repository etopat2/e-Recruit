<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assessment_score_import_id', 'row_number', 'application_reference', 'raw_score', 'notes', 'status', 'errors', 'assessment_score_id'])]
class AssessmentScoreImportRow extends Model
{
    use HasUlids;

    public function scoreImport(): BelongsTo
    {
        return $this->belongsTo(AssessmentScoreImport::class, 'assessment_score_import_id');
    }

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }
}
