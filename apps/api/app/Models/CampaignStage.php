<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recruitment_post_id', 'campaign_version_id', 'stage_code', 'name', 'sequence', 'required', 'configuration'])]
class CampaignStage extends Model
{
    use HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPost::class, 'recruitment_post_id');
    }

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'configuration' => 'array',
        ];
    }
}
