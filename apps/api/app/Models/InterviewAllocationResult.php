<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['interview_allocation_run_id', 'district_id', 'recruitment_centre_id', 'candidate_count', 'centre_load_after_assignment'])]
class InterviewAllocationResult extends Model
{
    use HasUlids;

    public function run(): BelongsTo
    {
        return $this->belongsTo(InterviewAllocationRun::class, 'interview_allocation_run_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'district_id');
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCentre::class, 'recruitment_centre_id');
    }
}
