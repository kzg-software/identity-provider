<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider_id', 'name', 'client_id', 'client_secret',
    'allowed_grant_types', 'allowed_response_types', 'allowed_scopes',
    'access_token_lifetime', 'refresh_token_lifetime', 'id_token_lifetime',
    'id_token_signed_response_alg', 'pkce_required', 'secret_required', 'is_active',
])]
#[Hidden(['client_secret'])]
class OauthClient extends Model
{
    protected function casts(): array
    {
        return [
            'allowed_grant_types' => 'array',
            'allowed_response_types' => 'array',
            'allowed_scopes' => 'array',
            'client_secret' => 'hashed',
            'pkce_required' => 'boolean',
            'secret_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Rückwärtskompatibel: die (erste) Anwendung, die diesen Client über den Provider nutzt. */
    protected function application(): Attribute
    {
        return Attribute::get(fn () => $this->provider?->application);
    }

    public function redirectUris(): HasMany
    {
        return $this->hasMany(OauthRedirectUri::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(OauthToken::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(OauthConsent::class);
    }
}
