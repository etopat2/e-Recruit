<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'endpoint_hash', 'endpoint_encrypted', 'public_key_encrypted', 'auth_token_encrypted', 'content_encoding', 'user_agent', 'last_used_at', 'revoked_at'])]
class PushSubscription extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'endpoint_encrypted' => 'encrypted',
            'public_key_encrypted' => 'encrypted',
            'auth_token_encrypted' => 'encrypted',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
