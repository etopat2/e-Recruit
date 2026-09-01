<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['application_id', 'initiated_by', 'document_type', 'original_filename', 'expected_bytes', 'chunk_size', 'received_chunks', 'idempotency_key', 'status', 'expires_at'])]
class UploadSession extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(Document::class);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
