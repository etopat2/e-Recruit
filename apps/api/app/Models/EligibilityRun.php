<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'campaign_version_id', 'status', 'input_snapshot', 'input_fingerprint', 'run_by', 'run_at'])]
class EligibilityRun extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return ['input_snapshot' => 'array', 'run_at' => 'datetime'];
    }
}
