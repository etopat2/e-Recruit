<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Models\CampaignVersion;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentPost;
use App\Services\AuditService;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    private const STAGE_CATALOGUE = [
        'application', 'hard_copy', 'verification', 'eligibility', 'interview',
        'assessment', 'selection', 'medical', 'training',
    ];

    public function index(): AnonymousResourceCollection
    {
        $publishedVersionIds = CampaignVersion::query()->where('status', 'published')->select('id');
        $campaigns = RecruitmentCampaign::query()
            ->whereIn('status', ['published', 'active'])
            ->with([
                'posts.documentRequirements' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds),
                'posts.stages' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds)->orderBy('sequence'),
                'posts.assessmentDefinitions' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds),
            ])
            ->orderByDesc('year')
            ->orderBy('name')
            ->paginate(20);

        return CampaignResource::collection($campaigns);
    }

    public function adminIndex(): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            RecruitmentCampaign::query()
                ->with(['posts.documentRequirements', 'posts.stages' => fn ($query) => $query->orderBy('sequence'), 'posts.assessmentDefinitions', 'versions' => fn ($query) => $query->latest('version')])
                ->orderByDesc('year')
                ->orderBy('name')
                ->paginate(50),
        );
    }

    public function show(RecruitmentCampaign $campaign): CampaignResource
    {
        abort_unless(in_array($campaign->status, ['published', 'active'], true), 404);
        $publishedVersionIds = $campaign->versions()->where('status', 'published')->select('id');
        $campaign->load([
            'posts.documentRequirements' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds),
            'posts.stages' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds)->orderBy('sequence'),
            'posts.assessmentDefinitions' => fn ($query) => $query->whereIn('campaign_version_id', $publishedVersionIds),
            'versions' => fn ($query) => $query->where('status', 'published')->latest('version'),
        ]);

        return new CampaignResource($campaign);
    }

    public function store(Request $request, CanonicalJson $canonicalJson, AuditService $audit): CampaignResource
    {
        $validated = $this->validateCampaign($request);
        $campaign = DB::transaction(fn (): RecruitmentCampaign => $this->createCampaign($validated, $request->user()->id, $canonicalJson));
        $audit->record('campaign.created', $campaign, after: ['code' => $campaign->code, 'version' => 1], actor: $request->user());

        return new CampaignResource($this->loadAdminRelations($campaign));
    }

    public function update(Request $request, RecruitmentCampaign $campaign, CanonicalJson $canonicalJson, AuditService $audit): CampaignResource
    {
        abort_if($campaign->status === 'archived', 409, 'Archived campaigns cannot be edited.');
        $validated = $this->validateCampaign($request, true);
        $before = $campaign->only(['code', 'name', 'year', 'status', 'opens_at', 'closes_at']);

        $version = DB::transaction(function () use ($validated, $campaign, $request, $canonicalJson): CampaignVersion {
            $campaign->update(collect($validated)->except(['posts', 'change_reason'])->all());
            foreach ($validated['posts'] as $postData) {
                $campaign->posts()->updateOrCreate(
                    ['code' => $postData['code']],
                    $this->postAttributes($postData),
                );
            }

            $snapshot = $this->snapshot($campaign->fresh(), $validated['posts']);
            $version = CampaignVersion::query()->create([
                'recruitment_campaign_id' => $campaign->id,
                'version' => ((int) $campaign->versions()->max('version')) + 1,
                'status' => 'draft',
                'snapshot' => $snapshot,
                'fingerprint' => $canonicalJson->hash($snapshot),
                'change_reason' => (string) $request->input('change_reason', 'Campaign configuration updated.'),
                'created_by' => $request->user()->id,
            ]);
            foreach ($validated['posts'] as $postData) {
                $post = $campaign->posts()->where('code', $postData['code'])->firstOrFail();
                $this->persistVersionedPostConfiguration($post, $version, $postData);
            }

            return $version;
        });

        $audit->record(
            'campaign.version_created',
            $campaign,
            before: $before,
            after: ['version' => $version->version, 'fingerprint' => $version->fingerprint],
            actor: $request->user(),
            reason: $version->change_reason,
        );

        return new CampaignResource($this->loadAdminRelations($campaign->fresh()));
    }

    public function clone(Request $request, RecruitmentCampaign $campaign, CanonicalJson $canonicalJson, AuditService $audit): CampaignResource
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:recruitment_campaigns,code'],
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'change_reason' => ['required', 'string', 'max:1000'],
        ]);
        $sourceVersion = $campaign->versions()->where('status', 'published')->latest('version')->first()
            ?? $campaign->versions()->latest('version')->firstOrFail();
        $posts = $campaign->posts()->get()->map(function (RecruitmentPost $post) use ($sourceVersion): array {
            return [
                ...$post->only(['code', 'name', 'description', 'reference_prefix', 'section_configuration', 'eligibility_configuration', 'selection_configuration', 'lc_source_policy', 'hard_copy_required', 'active']),
                'document_requirements' => DB::table('campaign_document_requirements')->where('recruitment_post_id', $post->id)->where('campaign_version_id', $sourceVersion->id)
                    ->get()->map(fn ($row): array => collect((array) $row)->except(['id', 'recruitment_post_id', 'campaign_version_id', 'created_at', 'updated_at'])->map(function ($value, $key) {
                        return in_array($key, ['allowed_extensions', 'extraction_profile'], true) && is_string($value) ? json_decode($value, true) : $value;
                    })->all())->all(),
                'stages' => DB::table('campaign_stages')->where('recruitment_post_id', $post->id)->where('campaign_version_id', $sourceVersion->id)
                    ->orderBy('sequence')->get()->map(fn ($row): array => collect((array) $row)->except(['id', 'recruitment_post_id', 'campaign_version_id', 'created_at', 'updated_at'])->map(function ($value, $key) {
                        return $key === 'configuration' && is_string($value) ? json_decode($value, true) : $value;
                    })->all())->all(),
                'assessment_definitions' => DB::table('assessment_definitions')->where('recruitment_post_id', $post->id)->where('campaign_version_id', $sourceVersion->id)
                    ->get()->map(fn ($row): array => collect((array) $row)->except(['id', 'recruitment_post_id', 'campaign_version_id', 'created_at', 'updated_at'])->all())->all(),
            ];
        })->all();
        $cloneData = [
            ...$campaign->only(['timezone', 'hard_copy_deadline_at', 'age_cutoff_date', 'privacy_notice', 'appeals_enabled']),
            ...collect($data)->except('change_reason')->all(),
            'posts' => $posts,
            'change_reason' => $data['change_reason'],
        ];
        $clone = DB::transaction(fn (): RecruitmentCampaign => $this->createCampaign($cloneData, $request->user()->id, $canonicalJson));
        $audit->record('campaign.cloned', $clone, before: ['source_campaign_id' => $campaign->id, 'source_version_id' => $sourceVersion->id], after: ['code' => $clone->code], actor: $request->user(), reason: $data['change_reason']);

        return new CampaignResource($this->loadAdminRelations($clone));
    }

    public function publish(Request $request, RecruitmentCampaign $campaign, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['change_reason' => ['required', 'string', 'max:1000']]);
        $version = $campaign->versions()->where('status', 'draft')->latest('version')->firstOrFail();
        DB::transaction(function () use ($campaign, $version, $request, $data): void {
            $campaign->versions()->where('status', 'published')->update(['status' => 'superseded']);
            $version->forceFill(['status' => 'published', 'published_at' => now(), 'change_reason' => $data['change_reason']])->save();
            $campaign->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $request->user()->id])->save();
        });
        $audit->record('campaign.published', $campaign, after: ['version' => $version->version, 'fingerprint' => $version->fingerprint], actor: $request->user(), reason: $data['change_reason']);

        return response()->json(['message' => 'Campaign configuration published.', 'version' => $version->version]);
    }

    public function changeStatus(Request $request, RecruitmentCampaign $campaign, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,closed,archived'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $allowed = [
            'draft' => ['archived'],
            'published' => ['active', 'closed'],
            'active' => ['closed'],
            'closed' => ['archived'],
        ];
        abort_unless(in_array($data['status'], $allowed[$campaign->status] ?? [], true), 409, 'The requested campaign state transition is not allowed.');
        $before = $campaign->status;
        $campaign->forceFill(['status' => $data['status']])->save();
        $audit->record('campaign.status_changed', $campaign, before: ['status' => $before], after: ['status' => $data['status']], actor: $request->user(), reason: $data['reason']);

        return response()->json(['message' => 'Campaign status updated.', 'status' => $campaign->status]);
    }

    /** @return array<string, mixed> */
    private function validateCampaign(Request $request, bool $updating = false): array
    {
        $validated = $request->validate([
            'code' => [$updating ? 'sometimes' : 'required', 'string', 'max:50'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'year' => [$updating ? 'sometimes' : 'required', 'integer', 'between:2020,2100'],
            'timezone' => ['sometimes', 'timezone'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'hard_copy_deadline_at' => ['nullable', 'date', 'after_or_equal:closes_at'],
            'age_cutoff_date' => ['nullable', 'date'],
            'privacy_notice' => ['required', 'array'],
            'privacy_notice.version' => ['required', 'string', 'max:100'],
            'privacy_notice.summary' => ['required', 'string', 'max:5000'],
            'appeals_enabled' => ['sometimes', 'boolean'],
            'posts' => ['required', 'array', 'min:1', 'max:20'],
            'posts.*.code' => ['required', 'string', 'max:30', 'distinct'],
            'posts.*.name' => ['required', 'string', 'max:255'],
            'posts.*.description' => ['nullable', 'string', 'max:4000'],
            'posts.*.reference_prefix' => ['required', 'alpha_num', 'max:20'],
            'posts.*.section_configuration' => ['required', 'array'],
            'posts.*.eligibility_configuration' => ['required', 'array'],
            'posts.*.selection_configuration' => ['nullable', 'array'],
            'posts.*.lc_source_policy' => ['required', 'in:origin,residence,origin_or_residence,custom'],
            'posts.*.hard_copy_required' => ['required', 'boolean'],
            'posts.*.active' => ['sometimes', 'boolean'],
            'posts.*.document_requirements' => ['required', 'array', 'min:1', 'max:30'],
            'posts.*.document_requirements.*.document_type' => ['required', 'string', 'max:80', 'distinct'],
            'posts.*.document_requirements.*.label' => ['required', 'string', 'max:255'],
            'posts.*.document_requirements.*.required' => ['required', 'boolean'],
            'posts.*.document_requirements.*.minimum_files' => ['required', 'integer', 'min:0', 'max:20'],
            'posts.*.document_requirements.*.maximum_files' => ['required', 'integer', 'min:1', 'max:20'],
            'posts.*.document_requirements.*.maximum_size_kb' => ['required', 'integer', 'between:64,51200'],
            'posts.*.document_requirements.*.allowed_extensions' => ['required', 'array', 'min:1'],
            'posts.*.document_requirements.*.allowed_extensions.*' => ['required', 'string', 'in:pdf,jpg,jpeg,png'],
            'posts.*.document_requirements.*.hard_copy_required' => ['required', 'boolean'],
            'posts.*.document_requirements.*.original_required_at_interview' => ['required', 'boolean'],
            'posts.*.document_requirements.*.extraction_profile' => ['nullable', 'array'],
            'posts.*.stages' => ['required', 'array', 'min:1', 'max:20'],
            'posts.*.stages.*.stage_code' => ['required', 'string', 'in:'.implode(',', self::STAGE_CATALOGUE)],
            'posts.*.stages.*.name' => ['required', 'string', 'max:255'],
            'posts.*.stages.*.sequence' => ['required', 'integer', 'min:1', 'max:100'],
            'posts.*.stages.*.required' => ['required', 'boolean'],
            'posts.*.stages.*.configuration' => ['nullable', 'array'],
            'posts.*.assessment_definitions' => ['required', 'array', 'min:1', 'max:20'],
            'posts.*.assessment_definitions.*.code' => ['required', 'string', 'max:60'],
            'posts.*.assessment_definitions.*.name' => ['required', 'string', 'max:255'],
            'posts.*.assessment_definitions.*.component_type' => ['required', 'in:written,oral_interview,aptitude,technical,practical,presentation,physical,other'],
            'posts.*.assessment_definitions.*.maximum_mark' => ['required', 'numeric', 'gt:0'],
            'posts.*.assessment_definitions.*.pass_mark' => ['nullable', 'numeric', 'min:0'],
            'posts.*.assessment_definitions.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'posts.*.assessment_definitions.*.mandatory' => ['required', 'boolean'],
            'posts.*.assessment_definitions.*.assessor_model' => ['required', 'in:single,independent,consensus,panel_head_adjudication'],
            'posts.*.assessment_definitions.*.aggregation_method' => ['required', 'in:single,mean,average,sum,consensus,adjudicated'],
            'posts.*.assessment_definitions.*.divergence_threshold' => ['nullable', 'numeric', 'min:0'],
            'posts.*.assessment_definitions.*.blind_scoring' => ['required', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $errors = [];
        foreach ($validated['posts'] as $postIndex => $post) {
            $stageCodes = array_column($post['stages'], 'stage_code');
            $stageSequences = array_column($post['stages'], 'sequence');
            if (count($stageCodes) !== count(array_unique($stageCodes))) {
                $errors["posts.{$postIndex}.stages"][] = 'Stage codes must be unique within a post.';
            }
            if (count($stageSequences) !== count(array_unique($stageSequences))) {
                $errors["posts.{$postIndex}.stages"][] = 'Stage sequence values must be unique within a post.';
            }
            foreach ($post['document_requirements'] as $documentIndex => $requirement) {
                if ($requirement['minimum_files'] > $requirement['maximum_files']) {
                    $errors["posts.{$postIndex}.document_requirements.{$documentIndex}.minimum_files"][] = 'Minimum files cannot exceed maximum files.';
                }
            }
            $weight = array_sum(array_map(fn (array $definition): float => (float) $definition['weight'], $post['assessment_definitions']));
            if (abs($weight - 100.0) > 0.0001) {
                $errors["posts.{$postIndex}.assessment_definitions"][] = 'Assessment weights must total exactly 100 percent.';
            }
            foreach ($post['assessment_definitions'] as $definitionIndex => $definition) {
                if (isset($definition['pass_mark']) && (float) $definition['pass_mark'] > (float) $definition['maximum_mark']) {
                    $errors["posts.{$postIndex}.assessment_definitions.{$definitionIndex}.pass_mark"][] = 'Pass mark cannot exceed maximum mark.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function createCampaign(array $validated, int $actorId, CanonicalJson $canonicalJson): RecruitmentCampaign
    {
        $campaign = RecruitmentCampaign::query()->create([
            ...collect($validated)->except(['posts', 'change_reason'])->all(),
            'status' => 'draft',
            'created_by' => $actorId,
        ]);
        $posts = [];
        foreach ($validated['posts'] as $postData) {
            $posts[] = $campaign->posts()->create($this->postAttributes($postData));
        }
        $snapshot = $this->snapshot($campaign, $validated['posts']);
        $version = CampaignVersion::query()->create([
            'recruitment_campaign_id' => $campaign->id,
            'version' => 1,
            'status' => 'draft',
            'snapshot' => $snapshot,
            'fingerprint' => $canonicalJson->hash($snapshot),
            'change_reason' => (string) ($validated['change_reason'] ?? 'Initial campaign configuration.'),
            'created_by' => $actorId,
        ]);
        foreach ($posts as $index => $post) {
            $this->persistVersionedPostConfiguration($post, $version, $validated['posts'][$index]);
        }

        return $campaign;
    }

    /** @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private function postAttributes(array $postData): array
    {
        return collect($postData)->only([
            'code', 'name', 'description', 'reference_prefix', 'section_configuration',
            'eligibility_configuration', 'selection_configuration', 'lc_source_policy',
            'hard_copy_required', 'active',
        ])->all();
    }

    /** @param array<string, mixed> $postData */
    private function persistVersionedPostConfiguration(RecruitmentPost $post, CampaignVersion $version, array $postData): void
    {
        foreach ($postData['document_requirements'] as $requirement) {
            DB::table('campaign_document_requirements')->insert([
                'id' => (string) Str::ulid(),
                'recruitment_post_id' => $post->id,
                'campaign_version_id' => $version->id,
                ...collect($requirement)->only(['document_type', 'label', 'required', 'minimum_files', 'maximum_files', 'maximum_size_kb', 'hard_copy_required', 'original_required_at_interview'])->all(),
                'allowed_extensions' => json_encode($requirement['allowed_extensions'], JSON_THROW_ON_ERROR),
                'extraction_profile' => isset($requirement['extraction_profile']) ? json_encode($requirement['extraction_profile'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($postData['stages'] as $stage) {
            DB::table('campaign_stages')->insert([
                'id' => (string) Str::ulid(),
                'recruitment_post_id' => $post->id,
                'campaign_version_id' => $version->id,
                ...collect($stage)->only(['stage_code', 'name', 'sequence', 'required'])->all(),
                'configuration' => isset($stage['configuration']) ? json_encode($stage['configuration'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($postData['assessment_definitions'] as $definition) {
            $post->assessmentDefinitions()->create([
                'campaign_version_id' => $version->id,
                ...collect($definition)->only(['code', 'name', 'component_type', 'maximum_mark', 'pass_mark', 'weight', 'mandatory', 'assessor_model', 'aggregation_method', 'divergence_threshold', 'blind_scoring'])->all(),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $posts
     * @return array<string, mixed>
     */
    private function snapshot(RecruitmentCampaign $campaign, array $posts): array
    {
        return [
            'campaign' => $campaign->only(['id', 'code', 'name', 'year', 'timezone', 'opens_at', 'closes_at', 'hard_copy_deadline_at', 'age_cutoff_date', 'privacy_notice', 'appeals_enabled']),
            'posts' => $posts,
        ];
    }

    private function loadAdminRelations(RecruitmentCampaign $campaign): RecruitmentCampaign
    {
        return $campaign->load([
            'posts.documentRequirements',
            'posts.stages' => fn ($query) => $query->orderBy('sequence'),
            'posts.assessmentDefinitions',
            'versions' => fn ($query) => $query->latest('version'),
        ]);
    }
}
