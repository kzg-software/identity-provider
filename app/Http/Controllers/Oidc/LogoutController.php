<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\UserSession;
use App\Oidc\IdTokenService;
use App\Services\SessionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class LogoutController extends Controller
{
    public function __construct(private readonly IdTokenService $idTokens)
    {
    }

    /**
     * RP-initiated Logout (end_session_endpoint).
     *
     * GET|POST /oauth/logout
     *
     * Bekannte Parameter: id_token_hint, post_logout_redirect_uri, client_id, state.
     * Die abmeldende Anwendung schickt den Benutzer hierher; wir beenden die
     * lokale Sitzung und leiten anschliessend - sofern die Ziel-URL beim
     * Client hinterlegt ist - dorthin zurueck.
     */
    public function __invoke(Request $request, SessionTracker $tracker): RedirectResponse
    {
        $postLogoutRedirectUri = $request->input('post_logout_redirect_uri');
        $state = $request->input('state');

        $client = $this->resolveClient($request);

        if (Auth::guard('web')->check()) {
            $user = $request->user();

            AuditLog::record('oauth.logout', $user, $client ? ['client' => $client->name] : []);

            $currentSession = UserSession::where('session_id', $request->session()->getId())->first();
            if ($currentSession) {
                $tracker->revoke($currentSession);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Cookie::queue('auth_manual', '1', 60 * 8);
        }

        if ($postLogoutRedirectUri && $client && $this->redirectUriAllowed($client, $postLogoutRedirectUri)) {
            $target = $postLogoutRedirectUri;

            if ($state !== null && $state !== '') {
                $target .= (str_contains($target, '?') ? '&' : '?').'state='.rawurlencode($state);
            }

            return redirect()->away($target);
        }

        return redirect()->route('login', ['manual' => 1]);
    }

    private function resolveClient(Request $request): ?OauthClient
    {
        if ($hint = $request->input('id_token_hint')) {
            $claims = $this->idTokens->decode($hint);
            $aud = $claims['aud'] ?? null;
            $aud = is_array($aud) ? ($aud[0] ?? null) : $aud;

            if ($aud && $client = OauthClient::query()->where('client_id', $aud)->first()) {
                return $client;
            }
        }

        if ($clientId = $request->input('client_id')) {
            return OauthClient::query()->where('client_id', $clientId)->first();
        }

        return null;
    }

    private function redirectUriAllowed(OauthClient $client, string $uri): bool
    {
        return $client->redirectUris()
            ->where('type', 'logout')
            ->where('uri', $uri)
            ->exists();
    }
}
