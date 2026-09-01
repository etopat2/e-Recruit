<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offline_event_id', 'entity_type', 'entity_id', 'field_key', 'local_value', 'server_value', 'local_base_version', 'server_version', 'status', 'resolution', 'resolved_value', 'resolution_reason', 'resolved_by', 'resolved_at'])]
class SyncConflict extends Model
{
    use HasUlids;

    public function event(): BelongsTo
    {
        return $this->belongsTo(OfflineEvent::class, 'offline_event_id');
    }

    protected function casts(): array
    {
        return [
            'local_value' => 'array',
            'server_value' => 'array',
            'resolved_value' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
