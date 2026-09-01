<?php

namespace App\Http\Controllers\Auth;

use App\Auth\NtlmHandshake;
use App\Directory\DirectoryConnectionResolver;
use App\Directory\DirectoryResolver;
use App\Directory\DirectorySyncService;
use App\Directory\GroupMembershipFilter;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\SessionTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * In-app NTLM "negotiate" endpoint — an alternative to the webserver-level
 * Kerberos/SPNEGO SSO in WindowsSsoAuthenticate for setups where there is no
 * IIS/Apache in front of Laravel doing the Kerberos handshake (e.g. running
 * directly behind `php artisan serve` / Laravel Herd during development).
 *
 * SECURITY NOTE: this endpoint parses the Windows username out of an NTLM
 * Type 3 message but does NOT cryptographically validate the NTLM
 * challenge/response against the Domain Controller (see NtlmHandshake
 * doc-block). It is a "trust the Windows username the browser negotiated"
 * shortcut — deliberately accepted for this deployment. There is
 * deliberately NO source-IP allowlist here (the network this runs on does
 * not use a range that is reliably distinguishable from "external" by CIDR
 * alone) — rate limiting below is the only abuse guard.
 */
class NegotiateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (Auth::check()) {
            return response()->json(['success' => true, 'redirect' => $this->intendedUrl($request)]);
        }

        if (! SystemSetting::windowsSsoEnabled()) {
            return response()->json(['error' => 'disabled'], 403);
        }

        if ($request->cookie('auth_manual') === '1') {
            return response()->json(['error' => 'manual_logout'], 401);
        }

        $ip = $request->ip();
        $throttleKey = 'ntlm-negotiate|'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 20)) {
            return response()->json(['error' => 'too_many_attempts'], 429);
        }

        $authHeader = (string) $request->header('Authorization', '');

        if ($authHeader === '') {
            return response('', 401, ['WWW-Authenticate' => 'NTLM']);
        }

        if (! NtlmHandshake::isNtlmOrNegotiateToken($authHeader)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json(['error' => 'not_supported'], 401);
        }

        $token = NtlmHandshake::extractToken($authHeader);

        if ($token === null) {
            RateLimiter::hit($throttleKey, 60);

            return response('', 401, ['WWW-Authenticate' => 'NTLM']);
        }

        $messageType = NtlmHandshake::getMessageType($token);

        if ($messageType === 1) {
            $challenge = NtlmHandshake::generateChallenge();
            Cache::put("ntlm_challenge:{$ip}", base64_encode($challenge), 120);

            return response('', 401, [
                'WWW-Authenticate' => 'NTLM '.NtlmHandshake::buildType2Challenge($challenge),
            ]);
        }

        if ($messageType === 3) {
            Cache::forget("ntlm_challenge:{$ip}");

            $parsed = NtlmHandshake::parseType3($token);

            if (! $parsed || $parsed['username'] === '') {
                RateLimiter::hit($throttleKey, 60);

                return response()->json(['error' => 'parse_failed'], 401);
            }

            return $this->autoLogin($request, $parsed['username'], $parsed['domain'], $throttleKey);
        }

        RateLimiter::hit($throttleKey, 60);

        return response()->json(['error' => 'not_supported'], 401);
    }

    private function autoLogin(Request $request, string $username, string $domain, string $throttleKey): Response
    {
        $username = trim($username);
        $domain = trim($domain) ?: null;

        try {
            $directory = DirectoryResolver::resolveSingle($domain);

            if (! $directory || ! $directory->is_active) {
                RateLimiter::hit($throttleKey, 60);

                return response()->json(['error' => 'user_not_found'], 401);
            }

            DirectoryConnectionResolver::connect($directory);
            $connectionName = DirectoryConnectionResolver::connectionName($directory);

            $ldapUser = LdapUser::on($connectionName)
                ->in($directory->userSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
                ->where('samaccountname', '=', $username)
                ->first();

            if (! $ldapUser) {
                RateLimiter::hit($throttleKey, 60);

                return response()->json(['error' => 'user_not_found'], 401);
            }

            if (! GroupMembershipFilter::allows($directory, $connectionName, $ldapUser)) {
                RateLimiter::hit($throttleKey, 60);

                return response()->json(['error' => 'user_not_found'], 401);
            }

            $user = (new DirectorySyncService)->syncSingleUser($directory, $connectionName, $ldapUser);

            if (! $user) {
                RateLimiter::hit($throttleKey, 60);

                return response()->json(['error' => 'user_not_found'], 401);
            }

            if (! $user->is_active) {
                return response()->json(['error' => 'account_disabled'], 403);
            }

            RateLimiter::clear($throttleKey);

            $request->session()->regenerate();
            Auth::guard('web')->login($user);

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_method' => 'windows_sso_ntlm',
            ])->save();

            AuditLog::record('login.windows_sso', $user, [
                'method' => 'windows_sso_ntlm',
                'username' => $username,
            ]);

            app(SessionTracker::class)->record($user, $request, 'windows_sso_ntlm');

            cookie()->queue(cookie()->forget('auth_manual'));

            return response()->json(['success' => true, 'redirect' => $this->intendedUrl($request)]);
        } catch (Throwable $e) {
            Log::warning('NTLM-Auto-Login fehlgeschlagen', ['username' => $username, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'directory_unavailable'], 401);
        }
    }

    /**
     * Mirrors what redirect()->intended() does for a normal form POST: if the
     * guest was redirected to /login because they hit an auth-gated URL
     * (e.g. /oauth/authorize while logged out), that URL was stashed in
     * `url.intended` and MUST be honoured here too - otherwise a silent
     * NTLM auto-login strands the user on the dashboard instead of
     * completing the OAuth/SAML flow that sent them to the login page.
     */
    private function intendedUrl(Request $request): string
    {
        return $request->session()->pull('url.intended', route('dashboard'));
    }
}
