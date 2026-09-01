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
    'extra_attributes', 'roles', 'manual_roles', 'last_synced_at', 'last_login_at', 'last_login_method',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // is_admin ist immer aus den Rollen abgeleitet (Gruppen-Mapping über
        // "roles" plus manuelle Zuweisung über "manual_roles").
        static::saving(function (User $user) {
            $user->is_admin = $user->hasRole('admin');
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'extra_attributes' => 'array',
            'roles' => 'array',
            'manual_roles' => 'array',
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

    /**
     * Zugewiesene Rollen aus Gruppen-Mapping und manueller Vergabe.
     *
     * @return array<int, string>
     */
    public function effectiveRoles(): array
    {
        return array_values(array_unique(array_merge(
            array_map('strval', (array) ($this->roles ?? [])),
            array_map('strval', (array) ($this->manual_roles ?? [])),
        )));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->effectiveRoles(), true);
    }

    /** Kommt die Administrator-Rolle aus einem Gruppen-Mapping (nicht manuell)? */
    public function adminFromGroupMapping(): bool
    {
        return in_array('admin', array_map('strval', (array) ($this->roles ?? [])), true);
    }

    public function grantManualRole(string $role): void
    {
        $roles = array_map('strval', (array) ($this->manual_roles ?? []));
        $roles[] = $role;
        $this->manual_roles = array_values(array_unique($roles));
    }

    public function revokeManualRole(string $role): void
    {
        $this->manual_roles = array_values(array_diff(
            array_map('strval', (array) ($this->manual_roles ?? [])),
            [$role],
        ));
    }
}
