<?php

namespace App\Models;

use App\Support\MaintenanceGate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['provider_id', 'name', 'slug', 'description', 'logo_path', 'visibility', 'launch_url', 'category', 'login_mode', 'preferred_provider', 'consent_required', 'consent_mode', 'is_active', 'maintenance_mode', 'maintenance_message', 'maintenance_allow'])]
class Application extends Model
{
    public const VISIBILITY_PORTAL = 'portal';

    public const VISIBILITY_HIDDEN = 'hidden';

    protected function casts(): array
    {
        return [
            'consent_required' => 'boolean',
            'is_active' => 'boolean',
            'maintenance_mode' => 'boolean',
        ];
    }

    public function isUnderMaintenanceFor(?User $user): bool
    {
        return MaintenanceGate::applicationBlockedFor($this, $user);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Convenience: der OAuth-Client des zugeordneten Providers (falls OIDC). */
    public function oauthClient(): ?OauthClient
    {
        return $this->provider?->oauthClient;
    }

    /** Convenience: der SAML Service Provider des zugeordneten Providers (falls SAML). */
    public function samlServiceProvider(): ?SamlServiceProvider
    {
        return $this->provider?->samlServiceProvider;
    }

    public function isVisibleInPortal(): bool
    {
        return ($this->visibility ?? self::VISIBILITY_PORTAL) === self::VISIBILITY_PORTAL;
    }

    public function accessPolicies(): HasMany
    {
        return $this->hasMany(AccessPolicy::class);
    }
}
