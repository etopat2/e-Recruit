<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['recruitment_campaign_id', 'recruitment_post_id', 'campaign_version_id', 'prison_region_id', 'run_number', 'status', 'algorithm_version', 'candidate_count', 'input_snapshot', 'result_snapshot', 'input_fingerprint', 'output_fingerprint', 'run_by', 'committed_by', 'committed_at'])]
class InterviewAllocationRun extends Model
{
    use HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPost::class, 'recruitment_post_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(PrisonRegion::class, 'prison_region_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(InterviewAllocationResult::class);
    }

    protected function casts(): array
    {
        return [
            'candidate_count' => 'integer',
            'input_snapshot' => 'array',
            'result_snapshot' => 'array',
            'committed_at' => 'datetime',
        ];
    }
}
