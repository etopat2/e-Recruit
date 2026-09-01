<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Offline\OfflineSyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncEventsRequest;
use App\Models\AssessmentScore;
use App\Models\InterviewAssignment;
use App\Models\OfflinePackage;
use App\Models\SyncConflict;
use App\Services\AuditService;
use App\Services\ScopeAuthorizer;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSyncController extends Controller
{
    public function registerDevice(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'public_identifier' => ['required', 'uuid'],
            'label' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:80'],
            'public_key' => ['nullable', 'string', 'max:2048'],
        ]);
        $device = DB::table('registered_devices')->where('public_identifier', $data['public_identifier'])->first();
        abort_if($device !== null && (int) $device->user_id !== (int) $request->user()->id, 409, 'This device is already registered to another user.');
        $id = $device?->id ?? (string) Str::ulid();
        DB::table('registered_devices')->updateOrInsert(['public_identifier' => $data['public_identifier']], [
            'id' => $id,
            'user_id' => $request->user()->id,
            'label' => $data['label'],
            'platform' => $data['platform'] ?? null,
            'public_key' => $data['public_key'] ?? null,
            'status' => 'active',
            'enrolled_at' => $device?->enrolled_at ?? now(),
            'last_seen_at' => now(),
            'created_at' => $device?->created_at ?? now(),
            'updated_at' => now(),
        ]);
        $audit->record('offline.device_registered', 'registered_device', $id, actor: $request->user(), after: ['label' => $data['label']]);

        return response()->json(['device' => DB::table('registered_devices')->where('id', $id)->first()], $device === null ? 201 : 200);
    }

    public function issue(Request $request, CanonicalJson $canonicalJson, ScopeAuthorizer $scopeAuthorizer, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_member', 'panel_head', 'attendance_officer', 'centre_coordinator'), 403);
        $data = $request->validate([
            'registered_device_id' => ['required', 'exists:registered_devices,id'],
            'pack_type' => ['required', 'in:interview,attendance,score_capture'],
            'scope' => ['required', 'array'],
            'permitted_actions' => ['required', 'array', 'min:1', 'max:20'],
            'permitted_actions.*' => ['string', 'in:ASSESSMENT_SCORE_RECORDED,ATTENDANCE_RECORDED'],
            'entity_ids' => ['required', 'array', 'max:'.config('erecruit.offline.maximum_records')],
            'entity_ids.*' => ['string', 'max:64'],
            'expiry_hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);
        $device = DB::table('registered_devices')->where('id', $data['registered_device_id'])
            ->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_if($device === null, 403, 'The active device must belong to the current user.');
        $entityType = $data['pack_type'] === 'score_capture' ? 'assessment_score' : 'interview_assignment';
        $expectedAction = $entityType === 'assessment_score' ? 'ASSESSMENT_SCORE_RECORDED' : 'ATTENDANCE_RECORDED';
        abort_if(collect($data['permitted_actions'])->contains(fn (string $action): bool => $action !== $expectedAction), 422, 'The requested action does not match the pack type.');

        $serverRecords = [];
        foreach ($data['entity_ids'] as $entityId) {
            if ($entityType === 'assessment_score') {
                $score = AssessmentScore::query()->with(['assignment.application', 'definition'])->find($entityId);
                abort_if($score === null, 422, 'A requested assessment score record was not found.');
                abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:score', $score->assignment->application), 403);
                abort_if($request->user()->hasRole('panel_member') && (int) $score->assessor_id !== (int) $request->user()->id, 403, 'Panel members may download only their own score records.');
                $serverRecords[] = [
                    'entity_type' => $entityType,
                    'entity_id' => $score->id,
                    'server_version' => $score->entity_version,
                    'payload' => [
                        'application_reference' => $score->assignment->application->reference,
                        'assignment_id' => $score->interview_assignment_id,
                        'assessment_code' => $score->definition->code,
                        'assessment_name' => $score->definition->name,
                        'maximum_mark' => $score->definition->maximum_mark,
                        'score' => $score->score,
                        'status' => $score->status,
                    ],
                ];
            } else {
                $assignment = InterviewAssignment::query()->with('application')->find($entityId);
                abort_if($assignment === null, 422, 'A requested interview assignment was not found.');
                abort_unless($scopeAuthorizer->canPerform($request->user(), 'decision:attendance', $assignment->application), 403);
                $attendance = DB::table('attendance_records')->where('interview_assignment_id', $assignment->id)->first();
                $serverRecords[] = [
                    'entity_type' => $entityType,
                    'entity_id' => $assignment->id,
                    'server_version' => (int) ($attendance->entity_version ?? 1),
                    'payload' => [
                        'application_reference' => $assignment->application->reference,
                        'assignment_order' => $assignment->assignment_order,
                        'panel_id' => $assignment->panel_id,
                        'attendance_status' => $attendance->status ?? null,
                    ],
                ];
            }
        }
        $manifest = [
            'schema_version' => 1,
            'entity_type' => $entityType,
            'entity_ids' => array_values($data['entity_ids']),
            'issued_to' => $request->user()->id,
        ];
        $package = OfflinePackage::query()->create([
            'registered_device_id' => $device->id,
            'user_id' => $request->user()->id,
            'pack_type' => $data['pack_type'],
            'version' => 1,
            'scope' => $data['scope'],
            'permitted_actions' => array_values(array_unique($data['permitted_actions'])),
            'manifest' => $manifest,
            'manifest_fingerprint' => $canonicalJson->hash($manifest),
            'status' => 'active',
            'issued_at' => now(),
            'expires_at' => now()->addHours($data['expiry_hours'] ?? config('erecruit.offline.default_expiry_hours')),
            'outstanding_events' => 0,
        ]);
        foreach ($serverRecords as $serverRecord) {
            DB::table('offline_package_records')->insert([
                'id' => (string) Str::ulid(),
                'offline_package_id' => $package->id,
                'entity_type' => $serverRecord['entity_type'],
                'entity_id' => $serverRecord['entity_id'],
                'server_version' => $serverRecord['server_version'],
                'payload_fingerprint' => $canonicalJson->hash($serverRecord['payload']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $audit->record('offline.package_issued', $package, actor: $request->user(), after: ['manifest_fingerprint' => $package->manifest_fingerprint]);

        return response()->json(['package' => $package, 'server_records' => $serverRecords, 'server_time' => now()->toISOString()], 201);
    }

    public function show(Request $request, OfflinePackage $offlinePackage): JsonResponse
    {
        abort_unless((int) $offlinePackage->user_id === (int) $request->user()->id, 403);

        return response()->json([
            'package' => $offlinePackage,
            'server_records' => DB::table('offline_package_records')->where('offline_package_id', $offlinePackage->id)->get(),
            'conflicts' => SyncConflict::query()->whereHas('event', fn ($query) => $query->where('offline_package_id', $offlinePackage->id))->get(),
            'server_time' => now()->toISOString(),
        ]);
    }

    public function sync(SyncEventsRequest $request, OfflinePackage $offlinePackage, OfflineSyncService $syncService, AuditService $audit): JsonResponse
    {
        $result = $syncService->push($offlinePackage, $request->user(), $request->validated('events'), $request->safe()->only([
            'client_pending_count',
            'last_local_sequence',
            'complete',
        ]));
        $audit->record('offline.events_synced', $offlinePackage, actor: $request->user(), after: [
            'event_count' => count($request->validated('events')),
            'conflict_count' => $result['conflict_count'],
        ]);

        return response()->json($result);
    }

    public function resolveConflict(Request $request, SyncConflict $conflict, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole('panel_head', 'centre_coordinator', 'hq_recruitment_administrator'), 403);
        abort_unless($conflict->status === 'open', 409, 'This conflict has already been resolved.');
        $data = $request->validate([
            'resolution' => ['required', 'in:keep_server,accept_local,manual_value'],
            'resolved_value' => ['nullable', 'array', 'required_if:resolution,manual_value'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $resolvedValue = match ($data['resolution']) {
            'keep_server' => $conflict->server_value,
            'accept_local' => $conflict->local_value,
            default => $data['resolved_value'],
        };
        DB::transaction(function () use ($conflict, $data, $resolvedValue, $request): void {
            if ($conflict->entity_type === 'assessment_score') {
                DB::table('assessment_scores')->where('id', $conflict->entity_id)->update([
                    'score' => data_get($resolvedValue, 'score'),
                    'entity_version' => DB::raw('entity_version + 1'),
                    'updated_at' => now(),
                ]);
            } elseif ($conflict->entity_type === 'interview_assignment') {
                $existingAttendance = DB::table('attendance_records')->where('interview_assignment_id', $conflict->entity_id)->first();
                DB::table('attendance_records')->updateOrInsert(['interview_assignment_id' => $conflict->entity_id], [
                    'id' => $existingAttendance->id ?? (string) Str::ulid(),
                    'status' => data_get($resolvedValue, 'status'),
                    'recorded_at' => now(),
                    'recorded_by' => $request->user()->id,
                    'entity_version' => ((int) ($existingAttendance->entity_version ?? 1)) + 1,
                    'created_at' => $existingAttendance->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
            $conflict->forceFill([
                'status' => 'resolved',
                'resolution' => $data['resolution'],
                'resolved_value' => $resolvedValue,
                'resolution_reason' => $data['reason'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ])->save();
        }, 3);
        $audit->record('offline.conflict_resolved', $conflict, actor: $request->user(), after: $data, reason: $data['reason']);

        return response()->json(['conflict' => $conflict->fresh()]);
    }
}
