<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['registered_device_id', 'user_id', 'pack_type', 'version', 'scope', 'permitted_actions', 'manifest', 'manifest_fingerprint', 'status', 'issued_at', 'expires_at', 'last_sync_at', 'revoked_at', 'outstanding_events'])]
class OfflinePackage extends Model
{
    use HasUlids;

    public function events(): HasMany
    {
        return $this->hasMany(OfflineEvent::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture() && $this->revoked_at === null;
    }

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'permitted_actions' => 'array',
            'manifest' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
