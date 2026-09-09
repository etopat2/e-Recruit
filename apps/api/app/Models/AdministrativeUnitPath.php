<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'unit_id', 'region_id', 'subregion_id', 'district_id', 'county_id',
    'subcounty_id', 'parish_id', 'village_id', 'full_address', 'search_text',
])]
class AdministrativeUnitPath extends Model
{
    protected $primaryKey = 'unit_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AdministrativeUnit::class, 'unit_id');
    }
}
