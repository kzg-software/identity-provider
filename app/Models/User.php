<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'username', 'first_name', 'last_name', 'name', 'email', 'password',
    'auth_source', 'is_admin', 'is_active',
    'directory_id', 'object_guid', 'sid', 'sam_account_name', 'upn',
    'display_name', 'phone', 'department', 'company', 'position', 'office',
    'manager', 'distinguished_name', 'domain', 'account_status',
    'extra_attributes', 'roles', 'last_synced_at', 'last_login_at', 'last_login_method',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'extra_attributes' => 'array',
            'roles' => 'array',
            'last_synced_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function directoryUser(): HasOne
    {
        return $this->hasOne(DirectoryUser::class);
    }

    public function groupNames(): array
    {
        return $this->directoryUser?->groups()->pluck('name')->all() ?? [];
    }

    public function localAccount(): HasOne
    {
        return $this->hasOne(LocalAccount::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function oauthConsents(): HasMany
    {
        return $this->hasMany(OauthConsent::class);
    }

    public function oauthTokens(): HasMany
    {
        return $this->hasMany(OauthToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isLocal(): bool
    {
        return $this->auth_source === 'local';
    }
}
