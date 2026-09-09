<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['parent_id', 'code', 'source_id', 'source', 'name', 'level', 'unit_type', 'effective_from', 'effective_to', 'active'])]
class AdministrativeUnit extends Model
{
    use HasUlids;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function path(): HasOne
    {
        return $this->hasOne(AdministrativeUnitPath::class, 'unit_id');
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'active' => 'boolean'];
    }
}
