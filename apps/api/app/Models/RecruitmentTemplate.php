<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'default_configuration', 'active', 'created_by'])]
class RecruitmentTemplate extends Model
{
    use HasUlids;

    public function campaigns(): HasMany
    {
        return $this->hasMany(RecruitmentCampaign::class);
    }

    protected function casts(): array
    {
        return ['default_configuration' => 'array', 'active' => 'boolean'];
    }
}
