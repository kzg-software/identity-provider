<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Technische Authentifizierungs-/SSO-Konfiguration – getrennt von der portalseitigen
 * {@see Application}. Ein Provider ist entweder vom Typ `oidc` (Detail in
 * {@see OauthClient}) oder `saml` (Detail in {@see SamlServiceProvider}).
 */
#[Fillable(['name', 'type', 'is_active'])]
class Provider extends Model
{
    public const TYPE_OIDC = 'oidc';

    public const TYPE_SAML = 'saml';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function oauthClient(): HasOne
    {
        return $this->hasOne(OauthClient::class);
    }

    public function samlServiceProvider(): HasOne
    {
        return $this->hasOne(SamlServiceProvider::class);
    }

    /** Anwendungen, die diesen Provider nutzen (aktuell 1:1, technisch 1:n möglich). */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    /** Die typ-spezifische Detail-Konfiguration. */
    public function detail(): OauthClient|SamlServiceProvider|null
    {
        return $this->isOidc() ? $this->oauthClient : $this->samlServiceProvider;
    }

    public function isOidc(): bool
    {
        return $this->type === self::TYPE_OIDC;
    }

    public function isSaml(): bool
    {
        return $this->type === self::TYPE_SAML;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_OIDC => 'OAuth2 / OIDC',
            self::TYPE_SAML => 'SAML 2.0',
            default => $this->type,
        };
    }

    /** Sind die technischen Pflichtfelder gesetzt, sodass Logins funktionieren? */
    public function isConfigured(): bool
    {
        $detail = $this->detail();

        if (! $detail) {
            return false;
        }

        if ($this->isOidc()) {
            return filled($detail->client_id) && $detail->redirectUris()->exists();
        }

        return filled($detail->entity_id) && filled($detail->acs_url);
    }
}
