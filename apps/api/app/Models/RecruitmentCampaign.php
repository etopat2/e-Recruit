<?php

namespace App\Models;

use Database\Factories\RecruitmentCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['recruitment_template_id', 'code', 'name', 'year', 'status', 'timezone', 'opens_at', 'closes_at', 'hard_copy_deadline_at', 'age_cutoff_date', 'privacy_notice', 'appeals_enabled', 'created_by', 'published_by', 'published_at'])]
class RecruitmentCampaign extends Model
{
    /** @use HasFactory<RecruitmentCampaignFactory> */
    use HasFactory, HasUlids;

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecruitmentTemplate::class, 'recruitment_template_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(RecruitmentPost::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CampaignVersion::class);
    }

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'hard_copy_deadline_at' => 'datetime',
            'age_cutoff_date' => 'date',
            'privacy_notice' => 'array',
            'appeals_enabled' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
