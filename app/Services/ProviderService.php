<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthRedirectUri;
use App\Models\OauthScope;
use App\Models\Provider;
use App\Models\SamlAttributeMapping;
use App\Models\SamlServiceProvider;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Kapselt das Anlegen und Aktualisieren technischer Provider (OAuth2/OIDC bzw.
 * SAML 2.0) samt Detail-Konfiguration. Controller bleiben dadurch dünn und die
 * Assistent- wie die Bearbeiten-Maske teilen sich dieselbe Logik.
 */
class ProviderService
{
    public const DEFAULT_SAML_MAPPINGS = [
        'uid' => 'username',
        'mail' => 'email',
        'displayName' => 'display_name',
        'department' => 'department',
        'groups' => 'groups',
    ];

    /**
     * Legt einen OIDC-Provider an. Gibt den Provider zurück; das einmalig
     * sichtbare Klartext-Secret steht anschließend in $this->plainSecret.
     */
    public ?string $plainSecret = null;

    public function createOidc(array $data, ?User $actor = null): Provider
    {
        $provider = Provider::create([
            'name' => $data['name'],
            'type' => Provider::TYPE_OIDC,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->plainSecret = ($data['secret_required'] ?? true) ? Str::random(48) : null;

        $client = OauthClient::create([
            'provider_id' => $provider->id,
            'name' => $data['name'],
            'client_id' => (string) Str::uuid(),
            'client_secret' => $this->plainSecret,
            'allowed_grant_types' => $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'allowed_response_types' => $data['response_types'] ?? ['code'],
            'allowed_scopes' => $this->normalizeScopes($data['scopes'] ?? ['openid', 'profile', 'email']),
            'access_token_lifetime' => $data['access_token_lifetime'] ?? 3600,
            'refresh_token_lifetime' => $data['refresh_token_lifetime'] ?? 1209600,
            'id_token_lifetime' => $data['id_token_lifetime'] ?? 3600,
            'id_token_signed_response_alg' => $data['id_token_signed_response_alg'] ?? 'RS256',
            'pkce_required' => $data['pkce_required'] ?? true,
            'secret_required' => $data['secret_required'] ?? true,
            'is_active' => true,
        ]);

        $this->syncRedirectUris($client, $data['redirect_uris'] ?? '', $data['logout_redirect_uris'] ?? '');

        AuditLog::record('oauth.provider_created', $actor, ['provider' => $provider->name]);

        return $provider->refresh();
    }

    public function updateOidc(Provider $provider, array $data, ?User $actor = null): void
    {
        $client = $provider->oauthClient;

        $provider->update([
            'name' => $data['name'] ?? $provider->name,
            'is_active' => $data['is_active'] ?? $provider->is_active,
        ]);

        $client->fill(array_filter([
            'name' => $data['name'] ?? null,
            'allowed_grant_types' => $data['grant_types'] ?? null,
            'allowed_response_types' => $data['response_types'] ?? null,
            'allowed_scopes' => isset($data['scopes']) ? $this->normalizeScopes($data['scopes']) : null,
            'access_token_lifetime' => $data['access_token_lifetime'] ?? null,
            'refresh_token_lifetime' => $data['refresh_token_lifetime'] ?? null,
            'id_token_lifetime' => $data['id_token_lifetime'] ?? null,
            'id_token_signed_response_alg' => $data['id_token_signed_response_alg'] ?? null,
        ], fn ($v) => $v !== null));

        foreach (['pkce_required', 'secret_required', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $client->{$flag} = (bool) $data[$flag];
            }
        }

        $client->save();

        if (array_key_exists('redirect_uris', $data)) {
            $this->syncRedirectUris($client, $data['redirect_uris'], $data['logout_redirect_uris'] ?? '');
        }

        AuditLog::record('oauth.provider_updated', $actor, [], $provider->application);
    }

    public function createSaml(array $data, ?User $actor = null): Provider
    {
        $provider = Provider::create([
            'name' => $data['name'],
            'type' => Provider::TYPE_SAML,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $sp = SamlServiceProvider::create([
            'provider_id' => $provider->id,
            'name' => $data['name'],
            'entity_id' => $data['entity_id'],
            'acs_url' => $data['acs_url'],
            'slo_url' => $data['slo_url'] ?? null,
            'slo_binding' => $data['slo_binding'] ?? 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            'name_id_format' => $data['name_id_format'],
            'certificate' => $data['certificate'] ?? null,
            'sign_assertions' => $data['sign_assertions'] ?? true,
            'sign_responses' => $data['sign_responses'] ?? true,
            'sign_algorithm' => $data['sign_algorithm'] ?? 'sha256',
            'encrypt_assertions' => $data['encrypt_assertions'] ?? false,
            'want_name_id_encrypted' => $data['want_name_id_encrypted'] ?? false,
            'require_signed_requests' => $data['require_signed_requests'] ?? false,
            'session_lifetime_minutes' => $data['session_lifetime_minutes'] ?? null,
            'is_active' => true,
        ]);

        $this->applyDefaultMappings($sp);

        AuditLog::record('saml.provider_created', $actor, ['entity_id' => $sp->entity_id]);

        return $provider->refresh();
    }

    public function updateSaml(Provider $provider, array $data, ?User $actor = null): void
    {
        $sp = $provider->samlServiceProvider;

        $provider->update([
            'name' => $data['name'] ?? $provider->name,
            'is_active' => $data['is_active'] ?? $provider->is_active,
        ]);

        $sp->fill(array_filter([
            'name' => $data['name'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'acs_url' => $data['acs_url'] ?? null,
            'slo_url' => $data['slo_url'] ?? null,
            'slo_binding' => $data['slo_binding'] ?? null,
            'name_id_format' => $data['name_id_format'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'sign_algorithm' => $data['sign_algorithm'] ?? null,
            'session_lifetime_minutes' => $data['session_lifetime_minutes'] ?? null,
        ], fn ($v) => $v !== null));

        foreach (['sign_assertions', 'sign_responses', 'encrypt_assertions', 'want_name_id_encrypted', 'require_signed_requests', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $sp->{$flag} = (bool) $data[$flag];
            }
        }

        $sp->save();

        AuditLog::record('saml.provider_updated', $actor, [], $provider->application);
    }

    public function regenerateSecret(Provider $provider, ?User $actor = null): string
    {
        $this->plainSecret = Str::random(48);
        $provider->oauthClient->update(['client_secret' => $this->plainSecret]);

        AuditLog::record('oauth.client_secret_regenerated', $actor, [], $provider->application);

        return $this->plainSecret;
    }

    public function syncRedirectUris(OauthClient $client, string $login, string $logout = ''): void
    {
        $client->redirectUris()->delete();

        foreach ($this->splitLines($login) as $uri) {
            OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'login']);
        }

        foreach ($this->splitLines($logout) as $uri) {
            OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'logout']);
        }
    }

    public function applyDefaultMappings(SamlServiceProvider $sp): void
    {
        foreach (self::DEFAULT_SAML_MAPPINGS as $samlAttribute => $userAttribute) {
            SamlAttributeMapping::create([
                'saml_service_provider_id' => $sp->id,
                'saml_attribute' => $samlAttribute,
                'user_attribute' => $userAttribute,
            ]);
        }
    }

    /** @return list<string> */
    private function splitLines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($value)) ?: [])));
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $valid = OauthScope::query()->pluck('key')->all();
        $selected = array_values(array_intersect(array_map('strval', $scopes), $valid));

        return array_values(array_unique([...$selected, 'openid']));
    }
}
