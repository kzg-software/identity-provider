<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCompletion;
use App\Auth\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SecuritySettings;
use App\Webauthn\WebAuthnException;
use App\Webauthn\WebAuthnService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private LoginCompletion $completion,
        private WebAuthnService $webauthn,
    ) {}

    public function show(): View|RedirectResponse
    {
        $user = TwoFactorChallenge::user();

        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge', [
            'hasWebauthn' => $user->webauthnCredentials()->exists(),
            'hasTotp' => (bool) $user->twoFactor?->hasTotp(),
            'hasRecovery' => ! empty($user->twoFactor?->recoveryCodes()),
        ]);
    }

    public function webauthnOptions(): JsonResponse
    {
        $user = $this->requirePendingUser();

        return response()->json($this->webauthn->assertionOptions($user));
    }

    public function verifyWebauthn(Request $request): RedirectResponse
    {
        $user = $this->requirePendingUser();

        $this->hitRateLimit($request, $user);

        try {
            $result = $this->webauthn->verifyAssertion($request->all(), $user);
        } catch (WebAuthnException $e) {
            return $this->fail($request, $user, $e->getMessage(), 'webauthn');
        }

        RateLimiter::clear($this->rateKey($request, $user));
        AuditLog::record('webauthn.login', $user, ['credential_id' => $result['credential']->id]);

        return $this->completion->finalize($request, $user, TwoFactorChallenge::method().'+passkey');
    }

    public function verifyTotp(Request $request): RedirectResponse
    {
        $user = $this->requirePendingUser();
        $this->hitRateLimit($request, $user);

        $data = $request->validate(['code' => 'required|string']);
        $secret = $user->twoFactor?->totp_secret;

        $code = preg_replace('/\s+/', '', $data['code']);

        if (! $secret || ! (new Google2FA)->verifyKey($secret, (string) $code, 1)) {
            return $this->fail($request, $user, 'Der Code stimmt nicht. Bitte erneut versuchen.', 'code');
        }

        RateLimiter::clear($this->rateKey($request, $user));

        return $this->completion->finalize($request, $user, TwoFactorChallenge::method().'+totp');
    }

    public function verifyRecovery(Request $request): RedirectResponse
    {
        $user = $this->requirePendingUser();
        $this->hitRateLimit($request, $user);

        $data = $request->validate(['recovery_code' => 'required|string']);

        if (! $user->twoFactor || ! $user->twoFactor->useRecoveryCode($data['recovery_code'])) {
            return $this->fail($request, $user, 'Dieser Wiederherstellungscode ist ungültig oder bereits verbraucht.', 'recovery_code');
        }

        RateLimiter::clear($this->rateKey($request, $user));
        AuditLog::record('two_factor.recovery_used', $user, ['remaining' => count($user->twoFactor->recoveryCodes())]);

        $remaining = count($user->twoFactor->recoveryCodes());

        return $this->completion->finalize($request, $user, TwoFactorChallenge::method().'+recovery')
            ->with('status', "Wiederherstellungscode verwendet. Noch {$remaining} übrig.");
    }

    public function cancel(): RedirectResponse
    {
        TwoFactorChallenge::forget();
        $this->webauthn->forgetChallenge();

        return redirect()->route('login', ['manual' => 1]);
    }

    private function requirePendingUser(): User
    {
        $user = TwoFactorChallenge::user();

        if (! $user) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        return $user;
    }

    private function rateKey(Request $request, User $user): string
    {
        return '2fa|'.$user->getKey().'|'.$request->ip();
    }

    private function hitRateLimit(Request $request, User $user): void
    {
        $key = $this->rateKey($request, $user);

        if (RateLimiter::tooManyAttempts($key, SecuritySettings::loginMaxAttempts())) {
            TwoFactorChallenge::forget();

            AuditLog::record('login.2fa_failed', $user, ['reason' => 'rate_limited']);

            throw ValidationException::withMessages([
                'code' => 'Zu viele Fehlversuche. Bitte melde dich erneut an.',
            ])->redirectTo(route('login'));
        }

        RateLimiter::hit($key, SecuritySettings::loginLockoutSeconds());
    }

    private function fail(Request $request, User $user, string $message, string $key): RedirectResponse
    {
        AuditLog::record('login.2fa_failed', $user);

        throw ValidationException::withMessages([$key => $message]);
    }
}
