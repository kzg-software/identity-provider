<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\WebauthnCredential;
use App\Webauthn\WebAuthnException;
use App\Webauthn\WebAuthnService;

/**
 * Ersetzt die echte WebAuthn-Kryptografie in Tests durch deterministische
 * Ergebnisse. Die Steuerung erfolgt über das Payload-Array:
 *   - 'fail' => true      -> wirft eine WebAuthnException
 *   - 'credential_id'     -> welches gespeicherte Credential "antwortet"
 *   - 'name'              -> Name des bei der Registrierung angelegten Passkeys
 */
class FakeWebAuthnService extends WebAuthnService
{
    public function registerOptions(User $user): array
    {
        // spiegelt das Verhalten des echten Service: erzeugt den user handle
        $user->webauthnUserHandle();

        return ['publicKey' => ['challenge' => 'test-challenge']];
    }

    public function verifyRegistration(User $user, array $payload, string $name): WebauthnCredential
    {
        if ($payload['fail'] ?? false) {
            throw new WebAuthnException('fake failure');
        }

        $user->webauthnUserHandle();

        return $user->webauthnCredentials()->create([
            'name' => $name,
            'credential_id' => $payload['credential_id'] ?? ('cred-'.bin2hex(random_bytes(8))),
            'public_key' => "-----BEGIN PUBLIC KEY-----\nFAKE\n-----END PUBLIC KEY-----",
            'sign_count' => 0,
            'discoverable' => true,
            'uv' => true,
        ]);
    }

    public function assertionOptions(?User $user): array
    {
        return ['publicKey' => ['challenge' => 'test-challenge']];
    }

    public function verifyAssertion(array $payload, ?User $expectedUser): array
    {
        if ($payload['fail'] ?? false) {
            throw new WebAuthnException('fake failure');
        }

        $credential = WebauthnCredential::where('credential_id', $payload['credential_id'] ?? '')->first();

        if (! $credential) {
            throw new WebAuthnException('unbekannter Passkey');
        }

        if ($expectedUser && $credential->user_id !== $expectedUser->getKey()) {
            throw new WebAuthnException('falsches Konto');
        }

        $credential->forceFill(['last_used_at' => now(), 'sign_count' => $credential->sign_count + 1])->save();

        return ['credential' => $credential, 'user' => $credential->user];
    }
}
