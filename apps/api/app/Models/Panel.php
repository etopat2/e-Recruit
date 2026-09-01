<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['centre_session_id', 'code', 'name', 'capacity', 'status'])]
class Panel extends Model
{
    use HasUlids;

    public function assignments(): HasMany
    {
        return $this->hasMany(InterviewAssignment::class);
    }
}
