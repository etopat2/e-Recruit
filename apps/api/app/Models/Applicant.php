<?php

namespace App\Models;

use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'nin_encrypted', 'nin_hash', 'first_name', 'middle_names', 'last_name', 'date_of_birth', 'sex', 'nationality', 'primary_phone', 'alternative_phone', 'email', 'preferred_language'])]
class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory, HasUlids;

    protected $hidden = ['nin_encrypted', 'nin_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    protected function casts(): array
    {
        return [
            'nin_encrypted' => 'encrypted',
            'date_of_birth' => 'date',
        ];
    }
}
