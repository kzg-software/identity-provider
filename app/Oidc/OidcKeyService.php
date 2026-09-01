<?php

namespace App\Oidc;

use App\Models\OidcKey;
use Illuminate\Support\Str;

class OidcKeyService
{
    /**
     * Return the currently active signing key, generating one if none exists.
     */
    public function activeKey(): OidcKey
    {
        return OidcKey::query()->where('is_active', true)->orderByDesc('id')->first()
            ?? $this->rotate();
    }

    /**
     * Generate a fresh RSA keypair, mark it active, and demote the previous one.
     * Old keys stay in the table (not deleted) so tokens already signed with
     * them can still be verified via JWKS until they expire.
     */
    public function rotate(): OidcKey
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('Konnte keinen RSA-Schlüssel erzeugen: '.openssl_error_string());
        }

        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);
        $publicKeyPem = $details['key'];

        OidcKey::query()->where('is_active', true)->update(['is_active' => false]);

        return OidcKey::create([
            'kid' => (string) Str::uuid(),
            'algorithm' => 'RS256',
            'public_key' => $publicKeyPem,
            'private_key_encrypted' => $privateKeyPem,
            'is_active' => true,
            'rotated_at' => now(),
        ]);
    }

    public function findByKid(string $kid): ?OidcKey
    {
        return OidcKey::query()->where('kid', $kid)->first();
    }

    /**
     * All keys whose public part should still be published for verification.
     */
    public function publishableKeys()
    {
        return OidcKey::query()->orderByDesc('is_active')->orderByDesc('created_at')->get();
    }

    public function jwks(): array
    {
        $this->activeKey();

        $keys = [];

        foreach ($this->publishableKeys() as $key) {
            $details = openssl_pkey_get_details(openssl_pkey_get_public($key->public_key));

            if ($details === false) {
                continue;
            }

            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => $key->algorithm,
                'kid' => $key->kid,
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ];
        }

        return ['keys' => $keys];
    }

    public function fingerprint(OidcKey $key): string
    {
        $resource = openssl_pkey_get_public($key->public_key);
        $details = openssl_pkey_get_details($resource);

        return strtoupper(implode(':', str_split(hash('sha256', $details['key']), 4)));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
