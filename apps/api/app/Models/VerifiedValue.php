<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'supersedes_id', 'field_key', 'verified_value', 'evidence_references', 'verification_method', 'reason', 'verified_by', 'verified_at', 'current'])]
class VerifiedValue extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'verified_value' => 'array',
            'evidence_references' => 'array',
            'verified_at' => 'datetime',
            'current' => 'boolean',
        ];
    }
}
