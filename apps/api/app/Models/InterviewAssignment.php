<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'centre_session_id', 'panel_id', 'assignment_order', 'algorithm_version', 'input_fingerprint', 'manual_adjustment', 'adjustment_reason', 'assigned_by'])]
class InterviewAssignment extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    protected function casts(): array
    {
        return ['manual_adjustment' => 'boolean'];
    }
}
