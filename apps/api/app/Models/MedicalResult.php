<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'medical_schedule_id', 'outcome', 'restricted_notes', 'clinical_reference', 'recorded_by', 'recorded_at', 'entity_version'])]
#[Hidden(['restricted_notes', 'clinical_reference'])]
class MedicalResult extends Model
{
    use HasUlids;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return ['restricted_notes' => 'encrypted', 'clinical_reference' => 'encrypted', 'recorded_at' => 'datetime'];
    }
}
