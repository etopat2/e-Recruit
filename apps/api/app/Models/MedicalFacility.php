<?php

namespace App\Models;

use Database\Factories\MedicalFacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'location', 'host_administrative_unit_id', 'prison_region_id', 'region_attribution', 'referral_rule', 'source_metadata', 'active'])]
class MedicalFacility extends Model
{
    /** @use HasFactory<MedicalFacilityFactory> */
    use HasFactory, HasUlids;

    public function hostAdministrativeUnit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'host_administrative_unit_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(PrisonRegion::class, 'prison_region_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source_metadata' => 'array', 'active' => 'boolean'];
    }
}
