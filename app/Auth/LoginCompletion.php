<?php

namespace App\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Schließt eine Anmeldung ab. Zwischen bestandener Passwortprüfung und dem
 * eigentlichen Login liegt ggf. die Zwei-Faktor-Challenge.
 */
class LoginCompletion
{
    public function __construct(
        private SessionTracker $tracker,
        private TrustedDevices $trustedDevices,
    ) {}

    /**
     * Einstieg nach erfolgreicher Passwort-/Verzeichnisprüfung. Hat das Konto
     * einen zweiten Faktor, wird zur Challenge umgeleitet; sonst wird direkt
     * angemeldet.
     */
    public function handle(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();

        if ($user->hasTwoFactorEnabled() && ! $this->trustedDevices->isTrusted($user, $request)) {
            TwoFactorChallenge::start($user, $method);

            AuditLog::record('login.2fa_required', $user, ['method' => $method]);

            return redirect()->route('two-factor.challenge');
        }

        if ($user->hasTwoFactorEnabled()) {
            AuditLog::record('login.2fa_skipped_trusted_device', $user, ['method' => $method]);
        }

        return $this->finalize($request, $user, $method);
    }

    /**
     * Meldet den Nutzer endgültig an. Wird direkt (kein zweiter Faktor), nach
     * bestandener Challenge oder bei passwortloser Passkey-Anmeldung aufgerufen.
     */
    public function finalize(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();

        Auth::guard('web')->login($user);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_method' => $method,
        ])->save();

        AuditLog::record('login.success', $user, ['method' => $method]);

        $this->tracker->record($user, $request, $method);

        Cookie::queue(Cookie::forget('auth_manual'));

        TwoFactorChallenge::forget();

        if ($request->session()->has('saml.pending')) {
            return redirect()->route('saml.sso.resume');
        }

        // Administratoren landen in der Systemverwaltung, alle anderen im Portal.
        $home = $user->is_admin ? route('admin.dashboard') : route('dashboard');

        return redirect()->intended($home);
    }
}
