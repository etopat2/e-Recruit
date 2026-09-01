<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'scope_type', 'scope_id', 'allowed_tasks', 'expires_at', 'assigned_by'])]
class UserScope extends Model
{
    use HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['allowed_tasks' => 'array', 'expires_at' => 'datetime'];
    }
}
