<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
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
            'reference' => $this->reference,
            'status' => $this->status,
            'active' => $this->active,
            'campaign' => $this->whenLoaded('campaign', fn (): array => [
                'id' => $this->campaign->id,
                'code' => $this->campaign->code,
                'name' => $this->campaign->name,
            ]),
            'post' => $this->whenLoaded('post', fn (): array => [
                'id' => $this->post->id,
                'code' => $this->post->code,
                'name' => $this->post->name,
                'sections' => $this->post->section_configuration,
                'hard_copy_required' => $this->post->hard_copy_required,
            ]),
            'stages' => $this->when(
                $this->relationLoaded('post') && $this->post->relationLoaded('stages'),
                fn () => $this->post->stages->map(fn ($stage): array => [
                    'stage_code' => $stage->stage_code,
                    'name' => $stage->name,
                    'sequence' => $stage->sequence,
                    'required' => $stage->required,
                ])->values(),
            ),
            'draft_data' => $this->when(
                array_key_exists('draft_data', $this->resource->getAttributes())
                    && ($request->user()?->can('update', $this->resource) ?? false),
                $this->draft_data,
            ),
            'submitted_at' => $this->submitted_at,
            'entity_version' => $this->entity_version,
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'version' => $document->version,
                'filename' => $document->original_filename,
                'malware_status' => $document->malware_status,
                'processing_status' => $document->processing_status,
                'quality_indicators' => $document->quality_indicators,
            ])),
            'timeline' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(function ($history) use ($request): array {
                $event = [
                    'status' => $history->to_status,
                    'at' => $history->created_at,
                ];
                if ($request->user()?->user_type !== 'applicant') {
                    $event['reason'] = $history->reason;
                }

                return $event;
            })),
        ];
    }
}
