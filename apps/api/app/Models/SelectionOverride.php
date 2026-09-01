<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['selection_run_id', 'application_id', 'replaced_application_id', 'previous_outcome', 'new_outcome', 'reason_code', 'justification', 'requested_by', 'approved_by', 'status', 'approved_at'])]
class SelectionOverride extends Model
{
    use HasUlids;

    public function selectionRun(): BelongsTo
    {
        return $this->belongsTo(SelectionRun::class);
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
