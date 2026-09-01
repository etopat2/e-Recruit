<?php

namespace App\Models;

use Database\Factories\RecruitmentPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['recruitment_campaign_id', 'code', 'name', 'description', 'reference_prefix', 'section_configuration', 'eligibility_configuration', 'selection_configuration', 'lc_source_policy', 'hard_copy_required', 'active'])]
class RecruitmentPost extends Model
{
    /** @use HasFactory<RecruitmentPostFactory> */
    use HasFactory, HasUlids;

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCampaign::class, 'recruitment_campaign_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function assessmentDefinitions(): HasMany
    {
        return $this->hasMany(AssessmentDefinition::class);
    }

    protected function casts(): array
    {
        return [
            'section_configuration' => 'array',
            'eligibility_configuration' => 'array',
            'selection_configuration' => 'array',
            'hard_copy_required' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
