<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'active'])]
class PrisonRegion extends Model
{
    use HasUlids;

    public function centres(): HasMany
    {
        return $this->hasMany(RecruitmentCentre::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
