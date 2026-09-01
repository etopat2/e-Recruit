<?php

namespace App\Domain\Offline;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\AssessmentScore;
use App\Models\Document;
use App\Models\InterviewAssignment;
use App\Models\MedicalResult;
use App\Models\OfflineEvent;
use App\Models\OfflinePackage;
use App\Models\Panel;
use App\Models\SyncConflict;
use App\Models\User;
use App\Models\VerifiedValue;
use App\Services\ScopeAuthorizer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSyncService
{
    public function __construct(private ScopeAuthorizer $scopeAuthorizer) {}

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
        $activeDevice = DB::table('registered_devices')->where('id', $package->registered_device_id)->where('user_id', $user->id)->where('status', 'active')->exists();
        if (! $activeDevice) {
            throw new DomainException('The registered device is no longer active.');
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
        if ((int) ($event['payload_schema_version'] ?? 0) !== 1) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Unsupported offline payload schema version.');
        }
        $expectedEntityType = match ($actionType) {
            'ASSESSMENT_SCORE_RECORDED' => 'assessment_score',
            'ATTENDANCE_RECORDED' => 'interview_assignment',
            'HARDCOPY_RECEIPT_RECORDED', 'MEDICAL_RESULT_RECORDED' => 'application',
            'DOCUMENT_VERIFICATION_RECORDED' => 'document',
            'PANEL_CLOSED' => 'panel',
            default => 'unsupported',
        };
        if (($event['entity_type'] ?? '') !== $expectedEntityType) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Entity type does not match the permitted action.');
        }
        if ($payloadError = $this->payloadError($actionType, $event['payload'] ?? [])) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, $payloadError);
        }
        if (! $this->isAuthorised($actionType, $entityId, $user)) {
            return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Permission or scope is no longer valid for this event.');
        }

        return DB::transaction(function () use ($package, $user, $event, $eventId, $actionType, $entityId, $batchId): array {
            $state = 'accepted';
            $serverVersion = null;
            $conflictId = null;
            $fieldKey = 'score';
            $localValue = ['score' => data_get($event, 'payload.score')];
            $serverValue = null;
            if ($actionType === 'ASSESSMENT_SCORE_RECORDED') {
                $score = AssessmentScore::query()->with('definition')->lockForUpdate()->find($entityId);
                if ($score === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Assessment score record was not found.');
                }
                if (DB::table('panel_closures')->join('interview_assignments', 'interview_assignments.panel_id', '=', 'panel_closures.panel_id')
                    ->where('interview_assignments.id', $score->interview_assignment_id)->where('panel_closures.status', 'closed')->exists()) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'The panel is closed; scores are immutable.');
                }
                $attendanceStatus = DB::table('attendance_records')
                    ->where('interview_assignment_id', $score->interview_assignment_id)
                    ->value('status');
                if (! in_array($attendanceStatus, ['present', 'late'], true)) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Candidate must be checked in before an offline score can be accepted.');
                }
                if ((float) data_get($event, 'payload.score') > (float) $score->definition->maximum_mark) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Score exceeds the configured maximum mark.');
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
                        'status' => 'submitted',
                        'entity_version' => $serverVersion + 1,
                        'submitted_at' => now(),
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
            } elseif ($actionType === 'HARDCOPY_RECEIPT_RECORDED') {
                $application = Application::query()->lockForUpdate()->find($entityId);
                if ($application === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Application was not found.');
                }
                $serverVersion = (int) $application->entity_version;
                $fieldKey = 'hard_copy_receipt';
                $localValue = $event['payload'];
                $serverValue = ['status' => $application->status, 'latest_receipt_number' => DB::table('hard_copy_receipts')->where('application_id', $application->id)->latest('received_at')->value('receipt_number')];
                if ((int) ($event['base_entity_version'] ?? 0) !== $serverVersion) {
                    $state = 'conflict';
                } else {
                    $receiptId = (string) Str::ulid();
                    DB::table('hard_copy_receipts')->insert([
                        'id' => $receiptId,
                        'application_id' => $application->id,
                        'receiving_office' => data_get($event, 'payload.receiving_office'),
                        'received_by' => $user->id,
                        'received_at' => data_get($event, 'payload.received_at'),
                        'receipt_number' => 'HC/OFF/'.mb_strtoupper(substr(str_replace('-', '', $eventId), 0, 12)),
                        'status' => collect(data_get($event, 'payload.items', []))->contains(fn (array $item): bool => $item['status'] !== 'Match') ? 'query_required' : 'received',
                        'notes' => data_get($event, 'payload.notes'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    foreach (data_get($event, 'payload.items', []) as $item) {
                        DB::table('physical_document_checks')->insert([
                            'id' => (string) Str::ulid(),
                            'hard_copy_receipt_id' => $receiptId,
                            'document_type' => $item['document_type'],
                            'status' => $item['status'],
                            'notes' => $item['notes'] ?? null,
                            'discrepancy_evidence' => isset($item['discrepancy_evidence']) ? json_encode($item['discrepancy_evidence'], JSON_THROW_ON_ERROR) : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $previousStatus = $application->status;
                    $application->forceFill(['status' => 'hard_copies_received', 'entity_version' => $serverVersion + 1])->save();
                    ApplicationStatusHistory::query()->create([
                        'application_id' => $application->id,
                        'from_status' => $previousStatus,
                        'to_status' => 'hard_copies_received',
                        'changed_by' => $user->id,
                        'source' => 'offline_hard_copy_reception',
                    ]);
                    $serverVersion++;
                }
            } elseif ($actionType === 'DOCUMENT_VERIFICATION_RECORDED') {
                $document = Document::query()->lockForUpdate()->find($entityId);
                if ($document === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Document was not found.');
                }
                $serverVersion = (int) $document->version;
                $fieldKey = (string) data_get($event, 'payload.field_key');
                $localValue = $event['payload'];
                $current = VerifiedValue::query()->where('application_id', $document->application_id)->where('field_key', $fieldKey)->where('current', true)->lockForUpdate()->first();
                $serverValue = $current?->verified_value;
                $fieldChangedSinceIssue = $current !== null && $current->updated_at->greaterThan($package->issued_at);
                if ((int) ($event['base_entity_version'] ?? 0) !== $serverVersion || $fieldChangedSinceIssue) {
                    $state = 'conflict';
                } else {
                    DB::table('document_verifications')->insert([
                        'id' => (string) Str::ulid(),
                        'document_id' => $document->id,
                        'extracted_field_id' => data_get($event, 'payload.extracted_field_id'),
                        'action' => data_get($event, 'payload.action'),
                        'outcome' => data_get($event, 'payload.outcome'),
                        'reason' => data_get($event, 'payload.reason'),
                        'review_state' => data_get($event, 'payload.review_state') === null ? null : json_encode(data_get($event, 'payload.review_state'), JSON_THROW_ON_ERROR),
                        'reviewed_by' => $user->id,
                        'reviewed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if (in_array(data_get($event, 'payload.action'), ['verify', 'correct'], true)) {
                        $current?->forceFill(['current' => false])->save();
                        VerifiedValue::query()->create([
                            'application_id' => $document->application_id,
                            'supersedes_id' => $current?->id,
                            'field_key' => $fieldKey,
                            'verified_value' => ['value' => data_get($event, 'payload.verified_value')],
                            'evidence_references' => data_get($event, 'payload.evidence_references'),
                            'verification_method' => 'offline_officer_review',
                            'reason' => data_get($event, 'payload.reason'),
                            'verified_by' => $user->id,
                            'verified_at' => now(),
                            'current' => true,
                        ]);
                    }
                }
            } elseif ($actionType === 'MEDICAL_RESULT_RECORDED') {
                $application = Application::query()->lockForUpdate()->find($entityId);
                if ($application === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Application was not found.');
                }
                $scheduleId = (string) data_get($package->scope, 'medical_schedule_id');
                $result = MedicalResult::query()->where('application_id', $application->id)->where('medical_schedule_id', $scheduleId)->lockForUpdate()->first();
                $serverVersion = (int) ($result?->entity_version ?? 1);
                $fieldKey = 'medical_result';
                $localValue = ['outcome' => data_get($event, 'payload.outcome')];
                $serverValue = ['outcome' => $result?->outcome];
                if ((int) ($event['base_entity_version'] ?? 0) !== $serverVersion) {
                    $state = 'conflict';
                } else {
                    $result ??= new MedicalResult(['application_id' => $application->id, 'medical_schedule_id' => $scheduleId]);
                    $result->fill([
                        'outcome' => data_get($event, 'payload.outcome'),
                        'restricted_notes' => data_get($event, 'payload.restricted_notes'),
                        'clinical_reference' => data_get($event, 'payload.clinical_reference'),
                        'recorded_by' => $user->id,
                        'recorded_at' => now(),
                        'entity_version' => $serverVersion + 1,
                    ])->save();
                    $serverVersion++;
                }
            } elseif ($actionType === 'PANEL_CLOSED') {
                $panel = Panel::query()->lockForUpdate()->find($entityId);
                if ($panel === null) {
                    return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Panel was not found.');
                }
                $closure = DB::table('panel_closures')->where('panel_id', $panel->id)->lockForUpdate()->first();
                $serverVersion = $closure === null ? 1 : 2;
                $fieldKey = 'panel_closure';
                $localValue = ['confirmation' => true];
                $serverValue = ['status' => $closure->status ?? $panel->status];
                if ((int) ($event['base_entity_version'] ?? 0) !== $serverVersion || $closure !== null) {
                    $state = 'conflict';
                } else {
                    $scoreRows = AssessmentScore::query()->whereHas('assignment', fn ($query) => $query->where('panel_id', $panel->id))->where('status', 'submitted')->orderBy('id')->get(['id', 'score', 'status', 'entity_version']);
                    $assignmentCount = DB::table('interview_assignments')->where('panel_id', $panel->id)->count();
                    if ($assignmentCount === 0 || $scoreRows->isEmpty()) {
                        return $this->persistRejectedEvent($package, $user, $event, $batchId, 'The panel has no submitted assessment data.');
                    }
                    $hasOpenConflict = DB::table('sync_conflicts')->where('status', 'open')->whereIn('entity_id', $scoreRows->pluck('id'))->exists();
                    $hasOtherActivePack = DB::table('offline_packages')->where('id', '!=', $package->id)->where('status', 'active')->where(function ($packs) use ($panel): void {
                        $packs->whereExists(function ($records) use ($panel): void {
                            $records->selectRaw('1')->from('offline_package_records')->join('assessment_scores', 'assessment_scores.id', '=', 'offline_package_records.entity_id')->join('interview_assignments', 'interview_assignments.id', '=', 'assessment_scores.interview_assignment_id')->whereColumn('offline_package_records.offline_package_id', 'offline_packages.id')->where('interview_assignments.panel_id', $panel->id);
                        })->orWhereExists(function ($records) use ($panel): void {
                            $records->selectRaw('1')->from('offline_package_records')->join('interview_assignments', 'interview_assignments.id', '=', 'offline_package_records.entity_id')->whereColumn('offline_package_records.offline_package_id', 'offline_packages.id')->where('interview_assignments.panel_id', $panel->id);
                        });
                    })->exists();
                    if ($hasOpenConflict || $hasOtherActivePack) {
                        return $this->persistRejectedEvent($package, $user, $event, $batchId, 'Reconcile panel score packs and conflicts before closure.');
                    }
                    DB::table('panel_closures')->insert([
                        'id' => (string) Str::ulid(),
                        'panel_id' => $panel->id,
                        'closed_by' => $user->id,
                        'closed_at' => now(),
                        'score_fingerprint' => hash('sha256', json_encode($scoreRows->toArray(), JSON_THROW_ON_ERROR)),
                        'status' => 'closed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $panel->forceFill(['status' => 'closed'])->save();
                    $serverVersion = 2;
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

    /** @param array<string, mixed> $payload */
    private function payloadError(string $actionType, array $payload): ?string
    {
        if ($actionType === 'ASSESSMENT_SCORE_RECORDED' && (! is_numeric($payload['score'] ?? null) || (float) $payload['score'] < 0)) {
            return 'Score must be a non-negative number.';
        }
        if ($actionType === 'ATTENDANCE_RECORDED' && ! in_array($payload['status'] ?? null, ['present', 'absent', 'late', 'referred', 'disqualified', 'excused', 'no_show'], true)) {
            return 'Attendance status is invalid.';
        }
        if ($actionType === 'HARDCOPY_RECEIPT_RECORDED') {
            if (! is_string($payload['receiving_office'] ?? null) || trim($payload['receiving_office']) === '' || mb_strlen($payload['receiving_office']) > 255 || strtotime((string) ($payload['received_at'] ?? '')) === false) {
                return 'A valid receiving office and received time are required.';
            }
            if (! is_array($payload['items'] ?? null) || $payload['items'] === [] || count($payload['items']) > 50) {
                return 'One to fifty hard-copy check items are required.';
            }
            foreach ($payload['items'] as $item) {
                if (! is_array($item) || ! is_string($item['document_type'] ?? null) || trim($item['document_type']) === '' || ! in_array($item['status'] ?? null, ['Match', 'Different Document', 'Missing', 'Unreadable', 'Original Required at Interview'], true)) {
                    return 'A hard-copy check item is invalid.';
                }
            }
        }
        if ($actionType === 'DOCUMENT_VERIFICATION_RECORDED') {
            $actions = ['verify', 'flag_discrepancy', 'correct', 'mark_ocr_incorrect', 'request_replacement', 'mark_unreadable', 'mark_not_present'];
            $outcomes = ['VERIFIED/CONSISTENT', 'PROBABLE MATCH', 'DISCREPANCY', 'UNREADABLE/LOW CONFIDENCE', 'NOT AVAILABLE'];
            if (! is_string($payload['field_key'] ?? null) || trim($payload['field_key']) === '' || ! in_array($payload['action'] ?? null, $actions, true) || ! in_array($payload['outcome'] ?? null, $outcomes, true)) {
                return 'Verification field, action, and outcome are required.';
            }
            if (in_array($payload['action'], ['verify', 'correct'], true) && (! array_key_exists('verified_value', $payload) || ! is_array($payload['evidence_references'] ?? null) || $payload['evidence_references'] === [])) {
                return 'Verified value and evidence references are required.';
            }
            if ($payload['action'] !== 'verify' && (! is_string($payload['reason'] ?? null) || trim($payload['reason']) === '')) {
                return 'A reason is required for this verification action.';
            }
        }
        if ($actionType === 'MEDICAL_RESULT_RECORDED' && ! in_array($payload['outcome'] ?? null, ['Fit', 'Not Fit', 'Deferred', 'Further Assessment Required', 'No Show'], true)) {
            return 'Medical outcome is invalid.';
        }
        if ($actionType === 'PANEL_CLOSED' && ($payload['confirmation'] ?? null) !== true) {
            return 'Explicit panel closure confirmation is required.';
        }

        return null;
    }

    private function isAuthorised(string $actionType, string $entityId, User $user): bool
    {
        if ($actionType === 'ASSESSMENT_SCORE_RECORDED') {
            $score = AssessmentScore::query()->with('assignment.application')->find($entityId);

            return $score !== null
                && $user->hasRole('panel_member', 'panel_head')
                && (! $user->hasRole('panel_member') || (int) $score->assessor_id === (int) $user->id)
                && $this->scopeAuthorizer->canPerform($user, 'decision:score', $score->assignment->application);
        }
        if ($actionType === 'ATTENDANCE_RECORDED') {
            $assignment = InterviewAssignment::query()->with('application')->find($entityId);

            return $assignment !== null && $user->hasRole('attendance_officer', 'centre_coordinator', 'panel_head')
                && $this->scopeAuthorizer->canPerform($user, 'decision:attendance', $assignment->application);
        }
        if ($actionType === 'HARDCOPY_RECEIPT_RECORDED') {
            $application = Application::query()->find($entityId);

            return $application !== null && $user->hasRole('hard_copy_receiving_officer', 'centre_coordinator', 'regional_recruitment_officer')
                && $this->scopeAuthorizer->canViewApplication($user, $application);
        }
        if ($actionType === 'DOCUMENT_VERIFICATION_RECORDED') {
            $document = Document::query()->with('application')->find($entityId);

            return $document !== null && $user->hasRole('verification_officer', 'data_clerk')
                && $this->scopeAuthorizer->canPerform($user, 'decision:verification', $document->application);
        }
        if ($actionType === 'MEDICAL_RESULT_RECORDED') {
            $application = Application::query()->find($entityId);

            return $application !== null && $user->hasRole('medical_officer') && $this->scopeAuthorizer->canViewRestrictedMedical($user, $application);
        }
        if ($actionType === 'PANEL_CLOSED') {
            $panel = Panel::query()->with('assignments.application')->find($entityId);

            return $panel !== null && $panel->assignments->isNotEmpty() && $user->hasRole('panel_head')
                && $this->scopeAuthorizer->canPerform($user, 'decision:panel_close', $panel->assignments->first()->application);
        }

        return false;
    }
}
