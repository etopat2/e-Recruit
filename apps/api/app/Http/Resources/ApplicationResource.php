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
            'draft_data' => $this->when($request->user()?->can('update', $this->resource) ?? false, $this->draft_data),
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
            'timeline' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($history): array => [
                'status' => $history->to_status,
                'reason' => $history->reason,
                'at' => $history->created_at,
            ])),
        ];
    }
}
