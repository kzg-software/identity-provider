<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCompletion;
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

    public function __construct(private LoginCompletion $completion) {}

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

        // Nur die Zugangsdaten prüfen, NICHT anmelden – die eigentliche
        // Anmeldung übernimmt LoginCompletion (ggf. erst nach der
        // Zwei-Faktor-Challenge).
        $localOk = $user && $user->is_active && Auth::guard('web')->validate([
            'id' => $user->id,
            'password' => $credentials['password'],
        ]);

        if ($localOk) {
            RateLimiter::clear($throttleKey);

            return $this->completion->handle($request, $user, 'local');
        }

        // Kein passendes lokales Konto — dieselben Zugangsdaten gegen Active
        // Directory versuchen, bevor endgültig fehlgeschlagen wird.
        $directoryResult = $directoryAuth->attempt($credentials['username'], $credentials['password']);

        if ($directoryResult['ok']) {
            RateLimiter::clear($throttleKey);

            return $this->completion->handle($request, $directoryResult['user'], 'active_directory');
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

        return $this->completion->handle($request, $result['user'], 'active_directory');
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
