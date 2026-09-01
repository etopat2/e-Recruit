<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'nin_hash', 'password', 'user_type', 'status', 'is_privileged', 'locale'])]
#[Hidden(['password', 'remember_token', 'nin_hash', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function applicant(): HasOne
    {
        return $this->hasOne(Applicant::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(UserScope::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot(['assigned_by', 'expires_at'])->withTimestamps();
    }

    public function hasRole(string ...$roles): bool
    {
        if (in_array($this->user_type, $roles, true)) {
            return true;
        }

        return $this->roles()->whereIn('code', $roles)->where(function ($query) {
            $query->whereNull('role_user.expires_at')->orWhere('role_user.expires_at', '>', now());
        })->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_privileged' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
