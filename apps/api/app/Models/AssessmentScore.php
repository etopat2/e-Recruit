<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['interview_assignment_id', 'assessment_definition_id', 'assessor_id', 'score', 'notes', 'status', 'entity_version', 'submitted_at'])]
class AssessmentScore extends Model
{
    use HasUlids;

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AssessmentDefinition::class, 'assessment_definition_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(InterviewAssignment::class, 'interview_assignment_id');
    }

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'submitted_at' => 'datetime'];
    }
}
