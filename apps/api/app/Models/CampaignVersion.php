<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recruitment_campaign_id', 'version', 'status', 'snapshot', 'fingerprint', 'change_reason', 'created_by', 'published_at'])]
class CampaignVersion extends Model
{
    use HasUlids;

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCampaign::class, 'recruitment_campaign_id');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }
}
