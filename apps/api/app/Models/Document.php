<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['application_id', 'upload_session_id', 'replaces_document_id', 'document_type', 'version', 'original_filename', 'storage_disk', 'original_path', 'preview_path', 'mime_type', 'detected_mime_type', 'extension', 'size_bytes', 'sha256', 'malware_status', 'processing_status', 'quality_indicators', 'uploaded_by', 'uploaded_at'])]
class Document extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function extraction(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class);
    }

    public function replacedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    protected function casts(): array
    {
        return ['quality_indicators' => 'array', 'uploaded_at' => 'datetime'];
    }
}
