<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recruitment_post_id', 'campaign_version_id', 'code', 'name', 'component_type', 'maximum_mark', 'pass_mark', 'weight', 'mandatory', 'assessor_model', 'aggregation_method', 'divergence_threshold', 'blind_scoring'])]
class AssessmentDefinition extends Model
{
    use HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPost::class, 'recruitment_post_id');
    }

    protected function casts(): array
    {
        return [
            'maximum_mark' => 'decimal:2',
            'pass_mark' => 'decimal:2',
            'weight' => 'decimal:4',
            'divergence_threshold' => 'decimal:2',
            'mandatory' => 'boolean',
            'blind_scoring' => 'boolean',
        ];
    }
}
