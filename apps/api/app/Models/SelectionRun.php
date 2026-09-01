<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ranking_run_id', 'recruitment_post_id', 'campaign_version_id', 'run_number', 'mode', 'status', 'parameters', 'offline_readiness', 'input_fingerprint', 'output_fingerprint', 'run_by', 'certified_by', 'certified_at', 'exception_reason'])]
class SelectionRun extends Model
{
    use HasUlids;

    public function outcomes(): HasMany
    {
        return $this->hasMany(SelectionOutcome::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(SelectionOverride::class);
    }

    protected function casts(): array
    {
        return ['parameters' => 'array', 'offline_readiness' => 'array', 'certified_at' => 'datetime'];
    }
}
