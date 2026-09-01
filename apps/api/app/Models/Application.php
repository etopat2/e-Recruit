<?php

namespace App\Models;

use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['applicant_id', 'recruitment_campaign_id', 'recruitment_post_id', 'campaign_version_id', 'reference', 'status', 'active', 'draft_data', 'submission_snapshot', 'submission_fingerprint', 'submission_idempotency_key', 'qr_payload', 'acknowledgement_path', 'submitted_at', 'entity_version', 'assisted_by'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, HasUlids;

    public const StatusDraft = 'draft';

    public const StatusSubmitted = 'submitted_online';

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCampaign::class, 'recruitment_campaign_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPost::class, 'recruitment_post_id');
    }

    public function campaignVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignVersion::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->oldest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function verifiedValues(): HasMany
    {
        return $this->hasMany(VerifiedValue::class);
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'draft_data' => 'array',
            'submission_snapshot' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
