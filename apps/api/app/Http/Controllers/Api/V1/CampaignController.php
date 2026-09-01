<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Models\CampaignVersion;
use App\Models\RecruitmentCampaign;
use App\Support\CanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $campaigns = RecruitmentCampaign::query()
            ->whereIn('status', ['published', 'active'])
            ->with('posts')
            ->orderByDesc('year')
            ->orderBy('name')
            ->paginate(20);

        return CampaignResource::collection($campaigns);
    }

    public function show(RecruitmentCampaign $campaign): CampaignResource
    {
        abort_unless(in_array($campaign->status, ['published', 'active'], true), 404);
        $campaign->load(['posts.assessmentDefinitions', 'versions' => fn ($query) => $query->where('status', 'published')->latest('version')]);

        return new CampaignResource($campaign);
    }

    public function store(Request $request, CanonicalJson $canonicalJson): CampaignResource
    {
        $validated = $this->validateCampaign($request);
        $campaign = DB::transaction(function () use ($validated, $request, $canonicalJson): RecruitmentCampaign {
            $campaign = RecruitmentCampaign::query()->create([
                ...collect($validated)->except(['posts', 'change_reason'])->all(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            foreach ($validated['posts'] as $post) {
                $campaign->posts()->create($post);
            }
            $snapshot = $campaign->load('posts')->toArray();
            CampaignVersion::query()->create([
                'recruitment_campaign_id' => $campaign->id,
                'version' => 1,
                'status' => 'draft',
                'snapshot' => $snapshot,
                'fingerprint' => $canonicalJson->hash($snapshot),
                'change_reason' => 'Initial campaign configuration.',
                'created_by' => $request->user()->id,
            ]);

            return $campaign;
        });

        return new CampaignResource($campaign->load('posts'));
    }

    public function update(Request $request, RecruitmentCampaign $campaign, CanonicalJson $canonicalJson): CampaignResource
    {
        $validated = $this->validateCampaign($request, true);
        DB::transaction(function () use ($validated, $campaign, $request, $canonicalJson): void {
            $campaign->update(collect($validated)->except(['posts', 'change_reason'])->all());
            foreach ($validated['posts'] as $postData) {
                $campaign->posts()->updateOrCreate(['code' => $postData['code']], $postData);
            }
            $snapshot = $campaign->fresh()->load('posts')->toArray();
            $nextVersion = ((int) $campaign->versions()->max('version')) + 1;
            CampaignVersion::query()->create([
                'recruitment_campaign_id' => $campaign->id,
                'version' => $nextVersion,
                'status' => 'draft',
                'snapshot' => $snapshot,
                'fingerprint' => $canonicalJson->hash($snapshot),
                'change_reason' => (string) $request->input('change_reason', 'Campaign configuration updated.'),
                'created_by' => $request->user()->id,
            ]);
        });

        return new CampaignResource($campaign->fresh()->load('posts'));
    }

    public function publish(Request $request, RecruitmentCampaign $campaign): JsonResponse
    {
        $request->validate(['change_reason' => ['required', 'string', 'max:1000']]);
        $version = $campaign->versions()->where('status', 'draft')->latest('version')->firstOrFail();
        DB::transaction(function () use ($campaign, $version, $request): void {
            $campaign->versions()->where('status', 'published')->update(['status' => 'superseded']);
            $version->forceFill(['status' => 'published', 'published_at' => now(), 'change_reason' => $request->string('change_reason')])->save();
            $campaign->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $request->user()->id])->save();
        });

        return response()->json(['message' => 'Campaign configuration published.', 'version' => $version->version]);
    }

    /** @return array<string, mixed> */
    private function validateCampaign(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'code' => [$updating ? 'sometimes' : 'required', 'string', 'max:50'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'year' => [$updating ? 'sometimes' : 'required', 'integer', 'between:2020,2100'],
            'timezone' => ['sometimes', 'timezone'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'hard_copy_deadline_at' => ['nullable', 'date', 'after_or_equal:closes_at'],
            'age_cutoff_date' => ['nullable', 'date'],
            'privacy_notice' => ['nullable', 'array'],
            'appeals_enabled' => ['sometimes', 'boolean'],
            'posts' => ['required', 'array', 'min:1', 'max:20'],
            'posts.*.code' => ['required', 'string', 'max:30'],
            'posts.*.name' => ['required', 'string', 'max:255'],
            'posts.*.description' => ['nullable', 'string', 'max:4000'],
            'posts.*.reference_prefix' => ['required', 'alpha_num', 'max:20'],
            'posts.*.section_configuration' => ['required', 'array'],
            'posts.*.eligibility_configuration' => ['required', 'array'],
            'posts.*.selection_configuration' => ['nullable', 'array'],
            'posts.*.lc_source_policy' => ['required', 'in:origin,residence,origin_or_residence,custom'],
            'posts.*.hard_copy_required' => ['required', 'boolean'],
            'posts.*.active' => ['sometimes', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
