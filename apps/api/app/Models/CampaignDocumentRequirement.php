<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recruitment_post_id', 'campaign_version_id', 'document_type', 'label', 'required', 'minimum_files', 'maximum_files', 'maximum_size_kb', 'allowed_extensions', 'hard_copy_required', 'original_required_at_interview', 'extraction_profile'])]
class CampaignDocumentRequirement extends Model
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
            'allowed_extensions' => 'array',
            'hard_copy_required' => 'boolean',
            'original_required_at_interview' => 'boolean',
            'extraction_profile' => 'array',
        ];
    }
}
