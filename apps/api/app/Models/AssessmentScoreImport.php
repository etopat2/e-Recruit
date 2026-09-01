<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['assessment_definition_id', 'centre_session_id', 'source_filename', 'storage_disk', 'source_path', 'source_sha256', 'status', 'total_rows', 'accepted_rows', 'rejected_rows', 'error_report_path', 'purpose', 'imported_by', 'completed_at'])]
class AssessmentScoreImport extends Model
{
    use HasUlids;

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AssessmentDefinition::class, 'assessment_definition_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AssessmentScoreImportRow::class);
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
