<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'document_type' => $this->document_type,
            'version' => $this->version,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->detected_mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'malware_status' => $this->malware_status,
            'processing_status' => $this->processing_status,
            'quality_indicators' => $this->quality_indicators,
            'uploaded_at' => $this->uploaded_at,
        ];
    }
}
