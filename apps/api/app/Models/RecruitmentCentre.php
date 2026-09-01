<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['prison_region_id', 'code', 'name', 'address', 'contact_phone', 'daily_capacity', 'active_from', 'active_to', 'active'])]
class RecruitmentCentre extends Model
{
    use HasUlids;

    public function region(): BelongsTo
    {
        return $this->belongsTo(PrisonRegion::class, 'prison_region_id');
    }

    protected function casts(): array
    {
        return ['active_from' => 'date', 'active_to' => 'date', 'active' => 'boolean'];
    }
}
