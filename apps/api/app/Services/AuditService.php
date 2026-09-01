<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditService
{
    public function __construct(private CanonicalJson $canonicalJson) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        Model|string $entity,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?string $reason = null,
        ?string $approvalReference = null,
    ): AuditLog {
        return DB::transaction(function () use ($action, $entity, $entityId, $before, $after, $actor, $reason, $approvalReference): AuditLog {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('select pg_advisory_xact_lock(726554231)');
            }
            $previousHash = AuditLog::query()->latest('occurred_at')->latest('id')->lockForUpdate()->value('entry_hash');
            // Eloquent's default database date format stores whole seconds. Hash
            // exactly that precision so verification is stable after persistence.
            $occurredAt = now()->startOfSecond();
            $entityType = $entity instanceof Model ? $entity::class : $entity;
            $resolvedEntityId = $entity instanceof Model ? (string) $entity->getKey() : $entityId;
            $correlationId = (string) (Context::get('correlation_id') ?: Str::uuid());
            $payload = [
                'actor_id' => $actor?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $resolvedEntityId,
                'before_values' => $this->redact($before),
                'after_values' => $this->redact($after),
                'reason' => $reason,
                'approval_reference' => $approvalReference,
                'correlation_id' => $correlationId,
                'previous_hash' => $previousHash,
                'occurred_at' => $occurredAt->toISOString(),
            ];

            return AuditLog::query()->create([
                ...$payload,
                'session_id' => request()->hasSession() ? request()->session()->getId() : null,
                'device_id' => request()->header('X-Device-ID'),
                'ip_address' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
                'entry_hash' => hash('sha256', ($previousHash ?? '').$this->canonicalJson->encode($payload)),
                'occurred_at' => $occurredAt,
            ]);
        }, 3);
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|token|secret|otp|nin|restricted_notes|raw_text/i', $key)) {
            return '[REDACTED]';
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $itemValue) {
            $redacted[$itemKey] = $this->redact($itemValue, (string) $itemKey);
        }

        return $redacted;
    }
}
