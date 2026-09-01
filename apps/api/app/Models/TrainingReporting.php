<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['training_invite_id', 'status', 'recorded_at', 'recorded_by', 'notes', 'replacement_for_application_id'])]
class TrainingReporting extends Model
{
    use HasUlids;

    protected $table = 'training_reporting';

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
