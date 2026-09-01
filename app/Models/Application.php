<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'logo_path', 'launch_url', 'category', 'login_mode', 'preferred_provider', 'consent_required', 'consent_mode', 'is_active', 'maintenance_mode', 'maintenance_message', 'maintenance_allow'])]
class Application extends Model
{
    protected function casts(): array
    {
        return [
            'consent_required' => 'boolean',
            'is_active' => 'boolean',
            'maintenance_mode' => 'boolean',
        ];
    }

    public function isUnderMaintenanceFor(?\App\Models\User $user): bool
    {
        return \App\Support\MaintenanceGate::applicationBlockedFor($this, $user);
    }

    public function oauthClients(): HasMany
    {
        return $this->hasMany(OauthClient::class);
    }

    public function samlServiceProviders(): HasMany
    {
        return $this->hasMany(SamlServiceProvider::class);
    }

    public function accessPolicies(): HasMany
    {
        return $this->hasMany(AccessPolicy::class);
    }
}
