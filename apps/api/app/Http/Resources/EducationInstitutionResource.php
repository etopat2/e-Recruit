<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EducationInstitutionResource extends JsonResource
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
            'name' => $this->name,
            'institution_type' => $this->institution_type,
            'district' => $this->district,
            'registration_number' => $this->registration_number,
            'registration_status' => $this->registration_status,
            'operational_status' => $this->operational_status,
            'source' => $this->source,
            'source_url' => $this->source_url,
            'last_verified_at' => $this->last_verified_at,
        ];
    }
}
