<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source', 'source_id', 'name', 'normalized_name', 'institution_type', 'district',
    'registration_number', 'registration_status', 'operational_status', 'qualification_levels',
    'source_url', 'source_payload', 'last_verified_at', 'active',
])]
class EducationInstitution extends Model
{
    /** @use HasFactory<\Database\Factories\EducationInstitutionFactory> */
    use HasFactory;

    use HasUlids;

    protected function casts(): array
    {
        return [
            'qualification_levels' => 'array',
            'source_payload' => 'array',
            'last_verified_at' => 'immutable_datetime',
            'active' => 'boolean',
        ];
    }
}
