<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'headquarters', 'source_metadata', 'active'])]
class PrisonRegion extends Model
{
    use HasUlids;

    public function centres(): HasMany
    {
        return $this->hasMany(RecruitmentCentre::class);
    }

    public function medicalFacilities(): HasMany
    {
        return $this->hasMany(MedicalFacility::class);
    }

    public function jurisdictions(): BelongsToMany
    {
        return $this->belongsToMany(AdministrativeUnit::class, 'prison_region_jurisdictions')
            ->withPivot(['jurisdiction_type', 'source_metadata'])
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return ['source_metadata' => 'array', 'active' => 'boolean'];
    }
}
