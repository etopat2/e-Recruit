<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'year' => $this->year,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'opens_at' => $this->opens_at,
            'closes_at' => $this->closes_at,
            'hard_copy_deadline_at' => $this->hard_copy_deadline_at,
            'age_cutoff_date' => $this->age_cutoff_date,
            'privacy_notice' => $this->privacy_notice,
            'appeals_enabled' => $this->appeals_enabled,
            'posts' => $this->whenLoaded('posts', fn () => $this->posts->map(fn ($post): array => [
                'id' => $post->id,
                'code' => $post->code,
                'name' => $post->name,
                'description' => $post->description,
                'sections' => $post->section_configuration,
                'eligibility_rules' => $post->eligibility_configuration,
                'selection_rules' => $post->selection_configuration,
                'lc_source_policy' => $post->lc_source_policy,
                'hard_copy_required' => $post->hard_copy_required,
                'assessment_definitions' => $post->relationLoaded('assessmentDefinitions')
                    ? $post->assessmentDefinitions
                    : [],
            ])),
            'published_version' => $this->whenLoaded('versions', fn () => $this->versions->first()?->only(['id', 'version', 'fingerprint', 'published_at'])),
        ];
    }
}
