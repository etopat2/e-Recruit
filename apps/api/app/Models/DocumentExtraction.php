<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'profile_code', 'profile_version', 'engine', 'engine_version', 'status', 'raw_text', 'mean_confidence', 'quality_indicators'])]
class DocumentExtraction extends Model
{
    use HasUlids;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return ['quality_indicators' => 'array', 'mean_confidence' => 'decimal:4'];
    }
}
