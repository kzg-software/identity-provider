<?php

namespace App\Webauthn;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Support\Notifier;
use Illuminate\Support\Facades\Session;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException as LibWebAuthnException;

/**
 * Kapselt die lbuchs/webauthn-Bibliothek: baut die Optionen für
 * navigator.credentials.create/get und prüft die Antworten des Browsers.
 *
 * Alle binären Werte werden gegenüber dem Browser als base64url kodiert
 * (siehe WebAuthn-Konstruktor, letztes Argument).
 */
class WebAuthnService
{
    private const CHALLENGE_KEY = 'webauthn.challenge';

    protected function lib(): WebAuthn
    {
        $rpName = config('webauthn.rp_name')
            ?: (SystemSetting::get('system_name') ?: config('app.name', 'Auth'));

        $rpId = config('webauthn.rp_id') ?: 'localhost';

        return new WebAuthn($rpName, $rpId, (array) config('webauthn.formats', ['none']), true);
    }

    protected function timeout(): int
    {
        return max(20, (int) config('webauthn.timeout', 60));
    }

    /**
     * Optionen für die Registrierung eines neuen Passkeys.
     *
     * @return array<string, mixed>
     */
    public function registerOptions(User $user): array
    {
        $wa = $this->lib();

        $exclude = $user->webauthnCredentials()
            ->pluck('credential_id')
            ->map(fn (string $id) => ByteBuffer::fromBase64Url($id))
            ->all();

        $args = $wa->getCreateArgs(
            $user->webauthnUserHandle(),
            $user->username ?: ($user->email ?: 'user-'.$user->id),
            $user->display_name ?: ($user->name ?: ($user->username ?: 'Benutzer')),
            $this->timeout(),
            'preferred',
            'preferred',
            null,
            $exclude,
        );

        $array = json_decode(json_encode($args), true);
        Session::put(self::CHALLENGE_KEY, $array['publicKey']['challenge']);

        return $array;
    }

    /**
     * Prüft die Antwort von navigator.credentials.create und legt den
     * Passkey an.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyRegistration(User $user, array $payload, string $name): WebauthnCredential
    {
        $challenge = Session::pull(self::CHALLENGE_KEY);

        if (! $challenge) {
            throw new WebAuthnException('Die Sitzung ist abgelaufen. Bitte erneut versuchen.');
        }

        // Sicherstellen, dass die stabile WebAuthn-Kennung existiert (wird
        // normalerweise schon in registerOptions() angelegt).
        $user->webauthnUserHandle();

        $response = $payload['response'] ?? [];

        try {
            $data = $this->lib()->processCreate(
                $this->decode($response['clientDataJSON'] ?? ''),
                $this->decode($response['attestationObject'] ?? ''),
                ByteBuffer::fromBase64Url($challenge),
                false,
                true,
            );
        } catch (LibWebAuthnException $e) {
            throw new WebAuthnException('Der Passkey konnte nicht registriert werden.', 0, $e);
        }

        $credentialId = $this->encode($this->binary($data->credentialId));

        if (WebauthnCredential::where('credential_id', $credentialId)->exists()) {
            throw new WebAuthnException('Dieser Passkey ist bereits hinterlegt.');
        }

        $aaguid = $this->binary($data->AAGUID ?? null);

        return $user->webauthnCredentials()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'public_key' => $data->credentialPublicKey,
            'aaguid' => ($aaguid !== '' && trim($aaguid, "\0") !== '') ? bin2hex($aaguid) : null,
            'attestation_format' => $data->attestationFormat ?? null,
            'transports' => $this->transports($response['transports'] ?? null),
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'discoverable' => (bool) ($payload['clientExtensionResults']['credProps']['rk'] ?? false),
            'uv' => (bool) ($data->userVerified ?? false),
        ]);
    }

    /**
     * Optionen für eine Anmeldung. Ohne $user (passwortlos) wird kein
     * Credential vorgegeben und Nutzerverifikation verlangt.
     *
     * @return array<string, mixed>
     */
    public function assertionOptions(?User $user): array
    {
        $wa = $this->lib();

        $ids = [];

        if ($user) {
            $ids = $user->webauthnCredentials()
                ->pluck('credential_id')
                ->map(fn (string $id) => ByteBuffer::fromBase64Url($id)->getBinaryString())
                ->all();
        }

        $args = $wa->getGetArgs(
            $ids,
            $this->timeout(),
            true,
            true,
            true,
            true,
            true,
            $user ? 'preferred' : 'required',
        );

        $array = json_decode(json_encode($args), true);
        Session::put(self::CHALLENGE_KEY, $array['publicKey']['challenge']);

        return $array;
    }

    /**
     * Prüft die Antwort von navigator.credentials.get.
     *
     * @param  array<string, mixed>  $payload
     * @return array{credential: WebauthnCredential, user: User}
     */
    public function verifyAssertion(array $payload, ?User $expectedUser): array
    {
        $challenge = Session::pull(self::CHALLENGE_KEY);

        if (! $challenge) {
            throw new WebAuthnException('Die Sitzung ist abgelaufen. Bitte erneut versuchen.');
        }

        $rawId = $this->encode($this->decode($payload['rawId'] ?? $payload['id'] ?? ''));

        $credential = WebauthnCredential::where('credential_id', $rawId)->first();

        if (! $credential) {
            throw new WebAuthnException('Dieser Passkey ist nicht bekannt.');
        }

        /** @var User $user */
        $user = $credential->user;

        if ($expectedUser && $user->getKey() !== $expectedUser->getKey()) {
            throw new WebAuthnException('Der Passkey gehört zu einem anderen Konto.');
        }

        $response = $payload['response'] ?? [];
        $userHandle = $response['userHandle'] ?? null;

        // Der user handle wird dem Authenticator als roher Byte-String (= der
        // gespeicherte base64url-String) übergeben und kommt genauso zurück.
        if (! $expectedUser && $userHandle && $user->webauthn_user_handle) {
            $handle = $this->decode($userHandle);

            if (! hash_equals($user->webauthn_user_handle, $handle)) {
                throw new WebAuthnException('Passkey und Konto passen nicht zusammen.');
            }
        }

        $wa = $this->lib();

        try {
            $wa->processGet(
                $this->decode($response['clientDataJSON'] ?? ''),
                $this->decode($response['authenticatorData'] ?? ''),
                $this->decode($response['signature'] ?? ''),
                $credential->public_key,
                ByteBuffer::fromBase64Url($challenge),
                $credential->sign_count ?: null,
                $expectedUser === null,
                true,
            );
        } catch (LibWebAuthnException $e) {
            if ($e->getCode() === LibWebAuthnException::SIGNATURE_COUNTER) {
                AuditLog::record('webauthn.clone_detected', $user, ['credential_id' => $credential->id]);
                Notifier::toUser($user, 'security.passkey_clone', 'Möglicherweise geklonter Passkey erkannt', [
                    'level' => 'critical',
                    'body' => 'Der Signaturzähler eines Passkeys war unerwartet niedrig. Das kann auf einen kopierten Sicherheitsschlüssel hindeuten. Entferne den betroffenen Passkey und prüfe dein Konto.',
                    'action_url' => route('profile.security'),
                    'dedupe_key' => 'security.passkey_clone:'.$credential->id,
                ]);
            }

            throw new WebAuthnException('Der Passkey konnte nicht überprüft werden.', 0, $e);
        }

        $newCount = $wa->getSignatureCounter();

        $credential->forceFill([
            'sign_count' => max((int) $credential->sign_count, (int) $newCount),
            'last_used_at' => now(),
        ])->save();

        return ['credential' => $credential, 'user' => $user];
    }

    public function forgetChallenge(): void
    {
        Session::forget(self::CHALLENGE_KEY);
    }

    private function decode(string $value): string
    {
        return ByteBuffer::fromBase64Url($value)->getBinaryString();
    }

    private function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function binary(mixed $value): string
    {
        if ($value instanceof ByteBuffer) {
            return $value->getBinaryString();
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<int, string>|null
     */
    private function transports(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $value)));
    }
}
