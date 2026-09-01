<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Applications\ApplicationReferenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationDraftRequest;
use App\Http\Requests\SubmitApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Jobs\DeliverNotificationJob;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\CampaignVersion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use App\Services\AuditService;
use App\Services\ScopeAuthorizer;
use App\Support\CanonicalJson;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function index(Request $request, ScopeAuthorizer $scopeAuthorizer): AnonymousResourceCollection
    {
        $user = $request->user()->loadMissing('applicant', 'scopes');
        $query = Application::query()->with(['campaign', 'post', 'documents', 'statusHistory']);
        if ($user->user_type === 'applicant') {
            $query->where('applicant_id', $user->applicant?->id);
        } elseif (! $user->hasRole(...config('erecruit.security.national_roles'))) {
            $campaignIds = $user->scopes->where('scope_type', 'campaign')->pluck('scope_id');
            $postIds = $user->scopes->where('scope_type', 'post')->pluck('scope_id');
            $panelIds = $user->scopes->where('scope_type', 'panel')->pluck('scope_id');
            $query->where(function ($scopeQuery) use ($campaignIds, $postIds, $panelIds): void {
                $scopeQuery->whereIn('recruitment_campaign_id', $campaignIds)
                    ->orWhereIn('recruitment_post_id', $postIds)
                    ->orWhereIn('id', DB::table('interview_assignments')->whereIn('panel_id', $panelIds)->select('application_id'));
            });
        }

        return ApplicationResource::collection($query->latest()->paginate(25));
    }

    public function store(Request $request, AuditService $audit): ApplicationResource
    {
        $this->authorize('create', Application::class);
        $validated = $request->validate([
            'campaign_id' => ['required', 'exists:recruitment_campaigns,id'],
            'post_id' => ['required', 'exists:recruitment_posts,id'],
        ]);
        $campaign = RecruitmentCampaign::query()->whereKey($validated['campaign_id'])->whereIn('status', ['published', 'active'])->firstOrFail();
        $post = RecruitmentPost::query()->whereKey($validated['post_id'])->whereBelongsTo($campaign, 'campaign')->where('active', true)->firstOrFail();
        $applicant = $request->user()->applicant()->firstOrFail();
        $version = CampaignVersion::query()
            ->whereBelongsTo($campaign, 'campaign')
            ->where('status', 'published')
            ->latest('version')
            ->firstOrFail();

        $application = Application::query()->firstOrCreate(
            [
                'applicant_id' => $applicant->id,
                'recruitment_campaign_id' => $campaign->id,
                'recruitment_post_id' => $post->id,
                'active' => true,
            ],
            [
                'campaign_version_id' => $version->id,
                'status' => Application::StatusDraft,
                'draft_data' => [],
                'entity_version' => 1,
            ],
        );
        if ($application->wasRecentlyCreated) {
            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'to_status' => Application::StatusDraft,
                'changed_by' => $request->user()->id,
            ]);
            $audit->record('application.draft_created', $application, actor: $request->user(), after: ['post_id' => $post->id]);
        }

        return new ApplicationResource($application->load(['campaign', 'post', 'documents', 'statusHistory']));
    }

    public function show(Application $application): ApplicationResource
    {
        $this->authorize('view', $application);

        return new ApplicationResource($application->load(['campaign', 'post', 'documents', 'statusHistory']));
    }

    public function update(StoreApplicationDraftRequest $request, Application $application, AuditService $audit): ApplicationResource|JsonResponse
    {
        $data = $request->validated();
        $updated = Application::query()
            ->whereKey($application->id)
            ->where('status', Application::StatusDraft)
            ->where('entity_version', $data['entity_version'])
            ->update([
                'draft_data' => $data['draft_data'],
                'entity_version' => DB::raw('entity_version + 1'),
                'updated_at' => now(),
            ]);
        if ($updated === 0) {
            return response()->json([
                'message' => 'The draft changed on another device. Refresh before saving again.',
                'server' => new ApplicationResource($application->fresh()->load(['campaign', 'post', 'documents', 'statusHistory'])),
            ], 409);
        }

        $fresh = $application->fresh();
        $audit->record('application.draft_saved', $fresh, actor: $request->user(), after: ['entity_version' => $fresh->entity_version]);

        return new ApplicationResource($fresh->load(['campaign', 'post', 'documents', 'statusHistory']));
    }

    public function submit(
        SubmitApplicationRequest $request,
        Application $application,
        ApplicationReferenceService $referenceService,
        CanonicalJson $canonicalJson,
        AuditService $audit,
    ): ApplicationResource|JsonResponse {
        $data = $request->validated();
        if ($application->status === Application::StatusSubmitted || $application->submitted_at !== null) {
            if ($application->submission_idempotency_key === $data['idempotency_key']) {
                return new ApplicationResource($application->load(['campaign', 'post', 'documents', 'statusHistory']));
            }

            return response()->json(['message' => 'This application has already been submitted.'], 409);
        }
        if ((int) $application->entity_version !== (int) $data['entity_version']) {
            return response()->json(['message' => 'The draft changed on another device. Refresh before submitting.'], 409);
        }

        $application->loadMissing(['applicant', 'campaign', 'post', 'documents']);
        $this->assertSubmissionComplete($application);
        DB::transaction(function () use ($application, $data, $referenceService, $canonicalJson, $request, $audit): void {
            $reference = $referenceService->allocate($application);
            $snapshot = [
                'applicant' => $application->applicant->only(['first_name', 'middle_names', 'last_name', 'date_of_birth', 'sex', 'nationality', 'primary_phone', 'email']),
                'draft_data' => $application->draft_data,
                'documents' => $application->documents->map->only(['id', 'document_type', 'version', 'sha256'])->all(),
                'campaign_version_id' => $application->campaign_version_id,
            ];
            $qrPayload = rtrim((string) config('app.url'), '/').'/official-artifacts/verify?reference='.rawurlencode($reference);
            $qrCode = new QrCode(
                data: $qrPayload,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 240,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
            );
            $qrSvg = (new SvgWriter)->write($qrCode)->getString();
            $targetStatus = $application->post->hard_copy_required ? 'awaiting_hard_copies' : 'under_verification';
            $submittedAt = now();
            $application->setAttribute('submitted_at', $submittedAt);
            $pdf = Pdf::loadView('pdf.acknowledgement', [
                'application' => $application,
                'reference' => $reference,
                'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
                'logoDataUri' => $this->logoDataUri(),
            ])->setPaper('a4');
            $path = "artefacts/{$application->id}/acknowledgement.pdf";
            Storage::disk(config('erecruit.uploads.disk'))->put($path, $pdf->output());

            $application->forceFill([
                'status' => $targetStatus,
                'submission_snapshot' => $snapshot,
                'submission_fingerprint' => $canonicalJson->hash($snapshot),
                'submission_idempotency_key' => $data['idempotency_key'],
                'qr_payload' => $qrPayload,
                'acknowledgement_path' => $path,
                'submitted_at' => $submittedAt,
                'entity_version' => $application->entity_version + 1,
            ])->save();
            ApplicationStatusHistory::query()->create([
                'application_id' => $application->id,
                'from_status' => Application::StatusDraft,
                'to_status' => $targetStatus,
                'reason' => 'Final online submission accepted.',
                'changed_by' => $request->user()->id,
            ]);
            DB::table('privacy_acceptances')->insert([
                'id' => (string) Str::ulid(),
                'application_id' => $application->id,
                'notice_version' => (string) ($application->campaign->privacy_notice['version'] ?? 'campaign-v1'),
                'notice_fingerprint' => $canonicalJson->hash($application->campaign->privacy_notice ?? []),
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $notificationId = (string) Str::ulid();
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'application_id' => $application->id,
                'event_code' => 'application.submitted',
                'channel' => 'in_portal',
                'recipient' => (string) $request->user()->id,
                'status' => 'pending',
                'idempotency_key' => hash('sha256', "application.submitted:{$application->id}"),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DeliverNotificationJob::dispatch($notificationId)->afterCommit();
            $audit->record('application.submitted', $application, actor: $request->user(), after: ['reference' => $reference, 'status' => $targetStatus]);
        }, 3);

        return new ApplicationResource($application->fresh()->load(['campaign', 'post', 'documents', 'statusHistory']));
    }

    public function acknowledgement(Application $application)
    {
        $this->authorize('view', $application);
        abort_if($application->acknowledgement_path === null, 404);

        return Storage::disk(config('erecruit.uploads.disk'))->download(
            $application->acknowledgement_path,
            "{$application->reference}-acknowledgement.pdf",
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'],
        );
    }

    private function assertSubmissionComplete(Application $application): void
    {
        $missingSections = [];
        foreach ($application->post->section_configuration as $section => $configuration) {
            $required = is_array($configuration) ? ($configuration['required'] ?? false) : (bool) $configuration;
            if ($required && blank(data_get($application->draft_data, $section))) {
                $missingSections[] = $section;
            }
        }
        $requirements = DB::table('campaign_document_requirements')
            ->where('recruitment_post_id', $application->recruitment_post_id)
            ->where('required', true)
            ->get();
        $missingDocuments = $requirements->filter(function ($requirement) use ($application): bool {
            return $application->documents
                ->where('document_type', $requirement->document_type)
                ->where('malware_status', 'clean')
                ->count() < $requirement->minimum_files;
        })->pluck('document_type')->all();

        if ($missingSections !== [] || $missingDocuments !== []) {
            throw ValidationException::withMessages([
                'application' => 'Complete all required sections and clean document uploads before submission.',
                'missing_sections' => $missingSections === [] ? 'None.' : implode(', ', $missingSections),
                'missing_documents' => $missingDocuments === [] ? 'None.' : implode(', ', $missingDocuments),
            ]);
        }
    }

    private function logoDataUri(): ?string
    {
        $path = resource_path('brand/logo.png');
        if (! extension_loaded('gd') || ! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
