<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['selection_run_id', 'application_id', 'bucket_key', 'outcome', 'position', 'score', 'skill_reservation_applied', 'manual_adjustment', 'decision_trace'])]
class SelectionOutcome extends Model
{
    use HasUlids;

    public function selectionRun(): BelongsTo
    {
        return $this->belongsTo(SelectionRun::class);
    }

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'skill_reservation_applied' => 'boolean',
            'manual_adjustment' => 'boolean',
            'decision_trace' => 'array',
        ];
    }
}
