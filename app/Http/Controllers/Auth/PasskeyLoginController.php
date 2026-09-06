<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCompletion;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\SecuritySettings;
use App\Webauthn\WebAuthnException;
use App\Webauthn\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Passwortlose Anmeldung mit einem Passkey (erster und einziger Faktor).
 * Die Nutzerverifikation des Authenticators (Biometrie/PIN) erfüllt die
 * Mehr-Faktor-Anforderung, daher folgt keine weitere Challenge.
 */
class PasskeyLoginController extends Controller
{
    public function __construct(
        private LoginCompletion $completion,
        private WebAuthnService $webauthn,
    ) {}

    public function options(): JsonResponse
    {
        abort_unless(SecuritySettings::passwordlessAnyEnabled(), 404);

        return response()->json($this->webauthn->assertionOptions(null));
    }

    public function verify(Request $request): RedirectResponse
    {
        abort_unless(SecuritySettings::passwordlessAnyEnabled(), 404);

        try {
            $result = $this->webauthn->verifyAssertion($request->all(), null);
        } catch (WebAuthnException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        $user = $result['user'];

        if (! $user->is_active || ! SecuritySettings::passwordlessEnabledFor($user)) {
            AuditLog::record('login.failed', $user, ['method' => 'passkey', 'reason' => 'passwordless_disabled']);

            throw ValidationException::withMessages([
                'username' => 'Die passwortlose Anmeldung ist für dieses Konto nicht verfügbar.',
            ]);
        }

        AuditLog::record('webauthn.login', $user, [
            'credential_id' => $result['credential']->id,
            'passwordless' => true,
        ]);

        return $this->completion->finalize($request, $user, 'passkey');
    }
}
