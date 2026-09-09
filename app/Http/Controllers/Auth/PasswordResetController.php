<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\MailSettings;
use App\Support\Notifier;
use App\Support\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    /** Unabhaengig vom Ausgang immer dieselbe Meldung, um Konten nicht zu verraten. */
    private const GENERIC = 'Falls ein Konto mit dieser Adresse existiert, ist eine E-Mail mit einem Link zum Zurücksetzen unterwegs.';

    public function showForgot(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::query()
            ->where('email', $request->string('email'))
            ->where('auth_source', 'local')
            ->first();

        if ($user && MailSettings::configured()) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', self::GENERIC);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', SecuritySettings::passwordRule()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                if (! $user->isLocal()) {
                    return;
                }

                $user->forceFill(['password' => Hash::make($password)])->save();

                AuditLog::record('password.reset_completed', $user);
                Notifier::toUser($user, 'security.change', 'Passwort zurückgesetzt', [
                    'level' => 'warning',
                    'security' => true,
                    'body' => 'Das Passwort deines Kontos wurde über „Passwort vergessen" neu gesetzt.',
                    'action_url' => route('profile.security'),
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Der Link ist ungültig oder abgelaufen. Fordere einen neuen an.']);
    }
}
