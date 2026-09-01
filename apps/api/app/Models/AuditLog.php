<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['actor_id', 'action', 'entity_type', 'entity_id', 'before_values', 'after_values', 'reason', 'approval_reference', 'correlation_id', 'session_id', 'device_id', 'ip_address', 'user_agent', 'previous_hash', 'entry_hash', 'occurred_at'])]
class AuditLog extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit records are append-only.'));
    }

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'occurred_at' => 'datetime'];
    }
}
