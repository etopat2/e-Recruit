<?php

namespace App\Models;

use Database\Factories\EmailOtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'method', 'purpose', 'code_hash', 'binding_hash', 'expires_at', 'attempt_count', 'consumed_at', 'last_sent_at'])]
#[Hidden(['code_hash', 'binding_hash'])]
class EmailOtpChallenge extends Model
{
    /** @use HasFactory<EmailOtpChallengeFactory> */
    use HasFactory, HasUlids;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempt_count' => 'integer',
            'consumed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }
}
