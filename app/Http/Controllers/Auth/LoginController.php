<?php

namespace App\Http\Controllers\Auth;

use App\Directory\DirectoryAuthService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionTracker;
use App\Support\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Einheitlicher Login-Endpoint: Benutzername/Passwort werden zuerst gegen ein
     * lokales Konto geprüft; passt das nicht (oder es gibt keins), werden
     * dieselben Zugangsdaten gegen Active Directory versucht. Für den
     * Benutzer ist das ein einziges "Anmelden" — welcher Weg greift, ist
     * ihm egal.
     */
    public function login(Request $request, DirectoryAuthService $directoryAuth): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = strtolower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, SecuritySettings::loginMaxAttempts())) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => "Zu viele Anmeldeversuche. Bitte in {$seconds} Sekunden erneut versuchen.",
            ]);
        }

        $user = User::query()
            ->where('username', $credentials['username'])
            ->orWhere('email', $credentials['username'])
            ->where('auth_source', 'local')
            ->first();

        $localOk = $user && $user->is_active && Auth::guard('web')->attempt([
            'id' => $user->id,
            'password' => $credentials['password'],
        ], false);

        if ($localOk) {
            RateLimiter::clear($throttleKey);

            return $this->completeLogin($request, $user, 'local');
        }

        // Kein passendes lokales Konto — dieselben Zugangsdaten gegen Active
        // Directory versuchen, bevor endgültig fehlgeschlagen wird.
        $directoryResult = $directoryAuth->attempt($credentials['username'], $credentials['password']);

        if ($directoryResult['ok']) {
            RateLimiter::clear($throttleKey);

            return $this->completeLogin($request, $directoryResult['user'], 'active_directory');
        }

        RateLimiter::hit($throttleKey, SecuritySettings::loginLockoutSeconds());

        AuditLog::record('login.failed', $user ?? ($directoryResult['user'] ?? null), ['username' => $credentials['username']]);

        throw ValidationException::withMessages([
            'username' => 'Benutzername oder Passwort ist ungültig.',
        ]);
    }

    public function loginDirectory(Request $request, DirectoryAuthService $service): RedirectResponse
    {
        $credentials = $request->validate([
            'ad_username' => 'required|string',
            'ad_password' => 'required|string',
        ]);

        $throttleKey = 'ad|'.strtolower($credentials['ad_username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, SecuritySettings::loginMaxAttempts())) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'ad_username' => "Zu viele Anmeldeversuche. Bitte in {$seconds} Sekunden erneut versuchen.",
            ]);
        }

        $result = $service->attempt($credentials['ad_username'], $credentials['ad_password']);

        if (! $result['ok']) {
            RateLimiter::hit($throttleKey, SecuritySettings::loginLockoutSeconds());

            AuditLog::record('login.failed', $result['user'] ?? null, [
                'username' => $credentials['ad_username'],
                'method' => 'active_directory',
            ]);

            throw ValidationException::withMessages([
                'ad_username' => $result['message'] ?? 'Anmeldedaten sind ungültig.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        return $this->completeLogin($request, $result['user'], 'active_directory');
    }

    public function completeLogin(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();

        Auth::guard('web')->login($user);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_method' => $method,
        ])->save();

        AuditLog::record('login.success', $user, ['method' => $method]);

        app(SessionTracker::class)->record($user, $request, $method);

        Cookie::queue(Cookie::forget('auth_manual'));

        if ($request->session()->has('saml.pending')) {
            return redirect()->route('saml.sso.resume');
        }

        // Administratoren landen in der Systemverwaltung, alle anderen im Portal.
        $home = $user->is_admin ? route('admin.dashboard') : route('dashboard');

        return redirect()->intended($home);
    }

    public function logout(Request $request, SessionTracker $tracker): RedirectResponse
    {
        AuditLog::record('logout', $request->user());

        // Must happen BEFORE invalidate() rotates/destroys the session - the
        // `user_sessions` tracking row otherwise never gets marked revoked,
        // and would keep showing as "active" under Meine Sitzungen forever.
        $currentSession = UserSession::where('session_id', $request->session()->getId())->first();
        if ($currentSession) {
            $tracker->revoke($currentSession);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Prevents /auth/negotiate from silently logging the user straight
        // back in on the next page load after an explicit logout.
        Cookie::queue('auth_manual', '1', 60 * 8);

        return redirect()->route('login', ['manual' => 1]);
    }
}
