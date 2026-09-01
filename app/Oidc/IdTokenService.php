<?php

namespace App\Oidc;

use App\Models\OauthClient;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key as JwtKey;

class IdTokenService
{
    public function __construct(private readonly OidcKeyService $keys)
    {
    }

    public function issue(User $user, OauthClient $client, array $scopes, ?string $nonce = null, int $lifetime = 3600): string
    {
        $key = $this->keys->activeKey();
        $now = time();

        $payload = [
            'iss' => rtrim(config('app.url'), '/'),
            'sub' => (string) $user->id,
            'aud' => $client->client_id,
            'iat' => $now,
            'exp' => $now + $lifetime,
            'auth_time' => $user->last_login_at?->timestamp ?? $now,
        ];

        if ($nonce !== null) {
            $payload['nonce'] = $nonce;
        }

        foreach ($this->claimsForScopes($user, $scopes) as $claim => $value) {
            $payload[$claim] = $value;
        }

        return JWT::encode($payload, $key->private_key_encrypted, 'RS256', $key->kid);
    }

    public function claimsForScopes(User $user, array $scopes): array
    {
        $claims = [];

        if (in_array('profile', $scopes, true)) {
            $claims['name'] = $user->name ?? trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            $claims['given_name'] = $user->first_name;
            $claims['family_name'] = $user->last_name;
            $claims['preferred_username'] = $user->username;
            $claims['department'] = $user->department;
            $claims['company'] = $user->company;
        }

        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        if (in_array('groups', $scopes, true)) {
            $claims['groups'] = $user->groupNames();
            $claims['roles'] = $user->roles ?? [];
        }

        return $claims;
    }

    /**
     * Decode and verify a token issued by this service (used for logout/id_token_hint validation).
     */
    public function decode(string $jwt): ?array
    {
        try {
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0] ?? ''), true);
            $kid = $header['kid'] ?? null;
            $key = $kid ? $this->keys->findByKid($kid) : null;

            if (! $key) {
                return null;
            }

            $decoded = JWT::decode($jwt, new JwtKey($key->public_key, 'RS256'));

            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }
}
