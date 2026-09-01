<?php

namespace App\Domain\Offline;

use App\Models\AssessmentScore;
use App\Models\OfflineEvent;
use App\Models\OfflinePackage;
use App\Models\SyncConflict;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSyncService
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{acknowledgements: list<array<string, mixed>>, conflict_count: int}
     */
    public function push(OfflinePackage $package, User $user, array $events, array $clientState = []): array
    {
        if ((int) $package->user_id !== (int) $user->id) {
            throw new DomainException('The offline pack does not belong to this user.');
        }
        if (! $package->isUsable()) {
            throw new DomainException('The offline pack is expired, revoked, or inactive.');
        }

        $batchId = (string) Str::ulid();
        DB::table('sync_batches')->insert([
            'id' => $batchId,
            'offline_package_id' => $package->id,
            'registered_device_id' => $package->registered_device_id,
            'user_id' => $user->id,
            'status' => 'processing',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $acknowledgements = [];
        foreach ($events as $event) {
            $acknowledgements[] = $this->processEvent($package, $user, $event, $batchId);
        }

        $acceptedCount = count(array_filter($acknowledgements, fn (array $item): bool => $item['state'] === 'accepted'));
        $rejectedCount = count(array_filter($acknowledgements, fn (array $item): bool => $item['state'] === 'rejected'));
        $conflictCount = count(array_filter($acknowledgements, fn (array $item): bool => $item['state'] === 'conflict'));
        $outstanding = isset($clientState['client_pending_count'])
            ? max((int) $clientState['client_pending_count'], 0)
            : max($package->outstanding_events - count($events), 0);
        $reconciled = (bool) ($clientState['complete'] ?? false)
            && $outstanding === 0 && $conflictCount === 0 && $rejectedCount === 0;

        $package->forceFill([
            'last_sync_at' => now(),
            'outstanding_events' => $outstanding,
            'status' => $reconciled ? 'reconciled' : 'active',
        ])->save();
        DB::table('registered_devices')->where('id', $package->registered_device_id)->update(['last_seen_at' => now(), 'last_sync_at' => now(), 'updated_at' => now()]);
        DB::table('sync_batches')->where('id', $batchId)->update([
            'status' => 'completed',
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'conflict_count' => $conflictCount,
            'server_cursor' => (string) ($clientState['last_local_sequence'] ?? ''),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'batch_id' => $batchId,
            'acknowledgements' => $acknowledgements,
            'conflict_count' => $conflictCount,
            'package_status' => $package->status,
            'outstanding_events' => $package->outstanding_events,
        ];
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function processEvent(OfflinePackage $package, User $user, array $event, string $batchId): array
    {
        $eventId = (string) ($event['id'] ?? '');
        if (! Str::isUuid($eventId)) {
            return ['event_id' => $eventId, 'state' => 'rejected', 'error' => 'Event id must be a UUID.'];
        }

        $existing = OfflineEvent::query()->find($eventId);
        if ($existing !== null) {
            return [
                'event_id' => $eventId,
                'state' => $existing->sync_state,
                'duplicate' => true,
            ];
        }

        $actionType = (string) ($event['action_type'] ?? '');
        if (! in_array($actionType, $package->permitted_actions, true)) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Action is not permitted by this pack.');
        }

        $entityId = (string) ($event['entity_id'] ?? '');
        $manifestEntityIds = $package->manifest['entity_ids'] ?? [];
        if ($manifestEntityIds !== [] && ! in_array($entityId, $manifestEntityIds, true)) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Entity is outside this pack scope.');
        }
        $expectedEntityType = $actionType === 'ASSESSMENT_SCORE_RECORDED' ? 'assessment_score' : 'interview_assignment';
        if (($event['entity_type'] ?? '') !== $expectedEntityType) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Entity type does not match the permitted action.');
        }
        if ($actionType === 'ATTENDANCE_RECORDED' && ! in_array(data_get($event, 'payload.status'), ['present', 'absent', 'late', 'excused', 'no_show'], true)) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Attendance status is invalid.');
        }

        return DB::transaction(function () use ($package, $user, $event, $eventId, $actionType, $entityId, $batchId): array {
            $state = 'accepted';
            $serverVersion = null;
            $conflictId = null;
            $fieldKey = 'score';
            $localValue = ['score' => data_get($event, 'payload.score')];
            $serverValue = null;
            if ($actionType === 'ASSESSMENT_SCORE_RECORDED') {
                $score = AssessmentScore::query()->lockForUpdate()->find($entityId);
                if ($score === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Assessment score record was not found.');
                }
                $serverVersion = (int) $score->entity_version;
                $serverValue = ['score' => $score->score];
                $baseVersion = (int) ($event['base_entity_version'] ?? 0);
                if ($baseVersion !== $serverVersion) {
                    $state = 'conflict';
                } else {
                    $score->forceFill([
                        'score' => (float) data_get($event, 'payload.score'),
                        'notes' => data_get($event, 'payload.notes'),
                        'entity_version' => $serverVersion + 1,
                    ])->save();
                    $serverVersion++;
                }
            } elseif ($actionType === 'ATTENDANCE_RECORDED') {
                $assignment = DB::table('interview_assignments')->where('id', $entityId)->lockForUpdate()->first();
                if ($assignment === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Interview assignment was not found.');
                }
                $attendance = DB::table('attendance_records')->where('interview_assignment_id', $entityId)->lockForUpdate()->first();
                $serverVersion = (int) ($attendance->entity_version ?? 1);
                $baseVersion = (int) ($event['base_entity_version'] ?? 0);
                $fieldKey = 'status';
                $localValue = ['status' => data_get($event, 'payload.status')];
                $serverValue = ['status' => $attendance->status ?? null];
                if ($baseVersion !== $serverVersion) {
                    $state = 'conflict';
                } elseif ($attendance === null) {
                    DB::table('attendance_records')->insert([
                        'id' => (string) Str::ulid(),
                        'interview_assignment_id' => $entityId,
                        'status' => data_get($event, 'payload.status'),
                        'recorded_at' => now(),
                        'recorded_by' => $user->id,
                        'exception_reason' => data_get($event, 'payload.notes'),
                        'entity_version' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $serverVersion = 2;
                } else {
                    DB::table('attendance_records')->where('id', $attendance->id)->update([
                        'status' => data_get($event, 'payload.status'),
                        'recorded_at' => now(),
                        'recorded_by' => $user->id,
                        'exception_reason' => data_get($event, 'payload.notes'),
                        'entity_version' => $serverVersion + 1,
                        'updated_at' => now(),
                    ]);
                    $serverVersion++;
                }
            }

            $record = OfflineEvent::query()->create([
                'id' => $eventId,
                'offline_package_id' => $package->id,
                'sync_batch_id' => $batchId,
                'registered_device_id' => $package->registered_device_id,
                'user_id' => $user->id,
                'entity_type' => (string) ($event['entity_type'] ?? ''),
                'entity_id' => $entityId,
                'action_type' => $actionType,
                'payload_schema_version' => (int) ($event['payload_schema_version'] ?? 1),
                'payload' => $event['payload'] ?? [],
                'base_entity_version' => (int) ($event['base_entity_version'] ?? 0),
                'local_sequence' => (int) ($event['local_sequence'] ?? 0),
                'local_timestamp' => $event['local_timestamp'] ?? now(),
                'received_at' => now(),
                'sync_state' => $state,
            ]);

            if ($state === 'conflict') {
                $conflict = SyncConflict::query()->create([
                    'offline_event_id' => $record->id,
                    'entity_type' => $record->entity_type,
                    'entity_id' => $entityId,
                    'field_key' => $fieldKey,
                    'local_value' => $localValue,
                    'server_value' => $serverValue,
                    'local_base_version' => (int) $record->base_entity_version,
                    'server_version' => (int) $serverVersion,
                    'status' => 'open',
                ]);
                $conflictId = $conflict->id;
            }

            return [
                'event_id' => $record->id,
                'state' => $state,
                'duplicate' => false,
                'server_version' => $serverVersion,
                'conflict_id' => $conflictId,
            ];
        }, 3);
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function persistRejectedEvent(OfflinePackage $package, User $user, array $event, string $batchId, string $error): array
    {
        $eventId = (string) ($event['id'] ?? Str::uuid());
        OfflineEvent::query()->create([
            'id' => $eventId,
            'offline_package_id' => $package->id,
            'sync_batch_id' => $batchId,
            'registered_device_id' => $package->registered_device_id,
            'user_id' => $user->id,
            'entity_type' => (string) ($event['entity_type'] ?? 'unknown'),
            'entity_id' => (string) ($event['entity_id'] ?? 'unknown'),
            'action_type' => (string) ($event['action_type'] ?? 'unknown'),
            'payload_schema_version' => (int) ($event['payload_schema_version'] ?? 1),
            'payload' => $event['payload'] ?? [],
            'base_entity_version' => (int) ($event['base_entity_version'] ?? 0),
            'local_sequence' => (int) ($event['local_sequence'] ?? 0),
            'local_timestamp' => $event['local_timestamp'] ?? now(),
            'received_at' => now(),
            'sync_state' => 'rejected',
            'error' => $error,
        ]);

        return ['event_id' => $eventId, 'state' => 'rejected', 'error' => $error, 'duplicate' => false];
    }
}
