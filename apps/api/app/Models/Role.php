<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'is_decision_role'])]
class Role extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['assigned_by', 'expires_at'])->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_decision_role' => 'boolean'];
    }
}
