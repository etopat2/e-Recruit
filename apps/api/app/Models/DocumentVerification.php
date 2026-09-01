<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'extracted_field_id', 'action', 'outcome', 'reason', 'review_state', 'reviewed_by', 'reviewed_at'])]
class DocumentVerification extends Model
{
    use HasUlids;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return ['review_state' => 'array', 'reviewed_at' => 'datetime'];
    }
}
