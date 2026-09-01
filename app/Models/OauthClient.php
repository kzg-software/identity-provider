<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'application_id', 'name', 'client_id', 'client_secret', 'allowed_grant_types',
    'access_token_lifetime', 'refresh_token_lifetime', 'id_token_lifetime',
    'pkce_required', 'secret_required', 'is_active',
])]
#[Hidden(['client_secret'])]
class OauthClient extends Model
{
    protected function casts(): array
    {
        return [
            'allowed_grant_types' => 'array',
            'client_secret' => 'hashed',
            'pkce_required' => 'boolean',
            'secret_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
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
