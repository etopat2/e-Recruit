<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'offline_package_id', 'sync_batch_id', 'registered_device_id', 'user_id', 'entity_type', 'entity_id', 'action_type', 'payload_schema_version', 'payload', 'base_entity_version', 'local_sequence', 'local_timestamp', 'received_at', 'sync_state', 'error'])]
class OfflineEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public function package(): BelongsTo
    {
        return $this->belongsTo(OfflinePackage::class, 'offline_package_id');
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'local_timestamp' => 'datetime', 'received_at' => 'datetime'];
    }
}
