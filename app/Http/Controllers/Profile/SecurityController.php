<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\TwoFactorCredential;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Support\Notifier;
use App\Support\SecuritySettings;
use App\Webauthn\WebAuthnException;
use App\Webauthn\WebAuthnService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class SecurityController extends Controller
{
    private const TOTP_SETUP_KEY = '2fa.totp_setup_secret';

    public function __construct(private WebAuthnService $webauthn) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        $user->load(['webauthnCredentials' => fn ($q) => $q->orderBy('created_at'), 'twoFactor']);

        $recentEvents = AuditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('event', [
                'login.success', 'login.windows_sso', 'webauthn.login',
                'two_factor.enabled', 'two_factor.disabled', 'two_factor.recovery_used',
                'two_factor.recovery_regenerated', 'webauthn.registered', 'webauthn.removed',
                'password.changed',
            ])
            ->latest('created_at')
            ->limit(8)
            ->get();

        return view('profile.security', [
            'user' => $user,
            'passkeys' => $user->webauthnCredentials,
            'totpEnabled' => (bool) $user->twoFactor?->hasTotp(),
            'recoveryCount' => count($user->twoFactor?->recoveryCodes() ?? []),
            'passwordlessEnabled' => SecuritySettings::passwordlessEnabledFor($user),
            'requiresPassword' => $user->isLocal(),
            'newRecoveryCodes' => Session::get('security.recovery_codes'),
            'recentEvents' => $recentEvents,
        ]);
    }

    // ----- Passwort --------------------------------------------------------

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isLocal(), 403, 'Das Passwort dieses Kontos wird im Verzeichnis verwaltet.');

        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', SecuritySettings::passwordRule()],
        ], [], ['current_password' => 'Aktuelles Passwort', 'password' => 'Neues Passwort']);

        $user->forceFill(['password' => Hash::make($request->string('password'))])->save();

        AuditLog::record('password.changed', $user);
        $this->notifySecurity($user, 'Passwort geändert', 'Das Passwort deines Kontos wurde geändert. Warst du das nicht, wende dich sofort an deine Administration.');

        return back()->with('status', 'Passwort wurde geändert.');
    }

    // ----- Passkeys ---------------------------------------------------------

    public function passkeyOptions(Request $request): JsonResponse
    {
        return response()->json($this->webauthn->registerOptions($request->user()));
    }

    public function storePasskey(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:50']);

        $user = $request->user();
        $firstFactor = ! $user->hasTwoFactorEnabled();

        try {
            $credential = $this->webauthn->verifyRegistration($user, $request->all(), $data['name']);
        } catch (WebAuthnException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        AuditLog::record('webauthn.registered', $user, ['credential_id' => $credential->id, 'name' => $credential->name]);
        $this->notifySecurity($user, 'Passkey hinzugefügt', 'Der Passkey „'.$credential->name.'" wurde deinem Konto hinzugefügt. Warst du das nicht, entferne ihn und ändere dein Passwort.');

        $codes = $this->ensureRecoveryCodes($user);

        $response = redirect()->route('profile.security')->with('status', 'Passkey wurde hinzugefügt.');

        return ($firstFactor && $codes)
            ? $response->with('security.recovery_codes', $codes)
            : $response;
    }

    public function destroyPasskey(Request $request, WebauthnCredential $credential): RedirectResponse
    {
        abort_unless($credential->user_id === $request->user()->id, 403);

        $this->confirmIdentity($request);

        $credential->delete();

        AuditLog::record('webauthn.removed', $request->user(), ['credential_id' => $credential->id, 'name' => $credential->name]);
        $this->notifySecurity($request->user(), 'Passkey entfernt', 'Der Passkey „'.$credential->name.'" wurde aus deinem Konto entfernt.');

        $this->pruneRecoveryCodes($request->user());

        return back()->with('status', 'Passkey wurde entfernt.');
    }

    // ----- Authenticator-App (TOTP) ---------------------------------------

    public function totpSetup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->twoFactor?->hasTotp()) {
            return redirect()->route('profile.security');
        }

        $google2fa = new Google2FA;
        $secret = Session::get(self::TOTP_SETUP_KEY) ?: $google2fa->generateSecretKey();
        Session::put(self::TOTP_SETUP_KEY, $secret);

        $issuer = SystemSetting::get('system_name') ?: config('app.name', 'Auth');
        $account = $user->email ?: $user->username;
        $otpauthUrl = $google2fa->getQRCodeUrl($issuer, $account, $secret);

        return view('profile.totp-setup', [
            'secret' => $secret,
            'qrSvg' => $this->qrSvg($otpauthUrl),
        ]);
    }

    public function storeTotp(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string']);
        $user = $request->user();

        $secret = Session::get(self::TOTP_SETUP_KEY);
        $code = preg_replace('/\s+/', '', $data['code']);

        if (! $secret || ! (new Google2FA)->verifyKey($secret, (string) $code, 1)) {
            return back()->withErrors(['code' => 'Der Code stimmt nicht. Bitte erneut versuchen.']);
        }

        $firstFactor = ! $user->hasTwoFactorEnabled();

        $this->twoFactorFor($user)->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        Session::forget(self::TOTP_SETUP_KEY);

        AuditLog::record('two_factor.enabled', $user, ['method' => 'totp']);
        $this->notifySecurity($user, 'Authenticator-App aktiviert', 'Für dein Konto wurde eine Authenticator-App als zweiter Faktor eingerichtet.');

        $codes = $this->ensureRecoveryCodes($user);

        $response = redirect()->route('profile.security')->with('status', 'Authenticator-App wurde aktiviert.');

        return ($firstFactor && $codes)
            ? $response->with('security.recovery_codes', $codes)
            : $response;
    }

    public function destroyTotp(Request $request): RedirectResponse
    {
        $this->confirmIdentity($request);

        $user = $request->user();

        if ($user->twoFactor) {
            $user->twoFactor->forceFill([
                'totp_secret' => null,
                'totp_confirmed_at' => null,
            ])->save();
        }

        AuditLog::record('two_factor.disabled', $user, ['method' => 'totp']);
        $this->notifySecurity($user, 'Authenticator-App deaktiviert', 'Die Authenticator-App wurde als zweiter Faktor entfernt.');

        $this->pruneRecoveryCodes($user);

        return back()->with('status', 'Authenticator-App wurde deaktiviert.');
    }

    // ----- Wiederherstellungscodes --------------------------------------

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $this->confirmIdentity($request);

        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 403);

        $codes = TwoFactorCredential::newRecoveryCodes();
        $this->twoFactorFor($user)->forceFill(['recovery_codes' => $codes])->save();

        AuditLog::record('two_factor.recovery_regenerated', $user);
        $this->notifySecurity($user, 'Wiederherstellungscodes neu erzeugt', 'Es wurden neue Wiederherstellungscodes für dein Konto erzeugt. Die alten sind ungültig.');

        return redirect()->route('profile.security')
            ->with('status', 'Neue Wiederherstellungscodes wurden erzeugt.')
            ->with('security.recovery_codes', $codes);
    }

    // ----- Helfer ---------------------------------------------------------

    /**
     * Legt Wiederherstellungscodes an, falls das Konto noch keine hat.
     *
     * @return array<int, string>|null die neuen Codes, wenn welche erzeugt wurden
     */
    private function ensureRecoveryCodes(User $user): ?array
    {
        $twoFactor = $this->twoFactorFor($user);

        if (! empty($twoFactor->recoveryCodes())) {
            return null;
        }

        $codes = TwoFactorCredential::newRecoveryCodes();
        $twoFactor->forceFill(['recovery_codes' => $codes])->save();

        return $codes;
    }

    private function twoFactorFor(User $user): TwoFactorCredential
    {
        $twoFactor = $user->twoFactor()->firstOrCreate(['user_id' => $user->getKey()]);
        $user->setRelation('twoFactor', $twoFactor);

        return $twoFactor;
    }

    /**
     * Entfernt die Wiederherstellungscodes, sobald kein zweiter Faktor mehr
     * eingerichtet ist.
     */
    private function pruneRecoveryCodes(User $user): void
    {
        $user->refresh();

        if (! $user->hasTwoFactorEnabled() && $user->twoFactor) {
            $user->twoFactor->forceFill(['recovery_codes' => null])->save();
        }
    }

    private function notifySecurity(User $user, string $title, string $body): void
    {
        Notifier::toUser($user, 'security.change', $title, [
            'level' => 'warning',
            'body' => $body,
            'action_url' => route('profile.security'),
        ]);
    }

    private function confirmIdentity(Request $request): void
    {
        if (! $request->user()->isLocal()) {
            return;
        }

        $request->validate([
            'current_password' => ['required', 'current_password:web'],
        ], [], ['current_password' => 'Passwort']);
    }

    private function qrSvg(string $data): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($data);
    }
}
