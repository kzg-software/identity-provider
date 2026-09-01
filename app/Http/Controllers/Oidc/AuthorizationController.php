<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthConsent;
use App\Oidc\Entities\UserEntity;
use App\Oidc\NonceContext;
use App\Oidc\Psr7Bridge;
use App\Oidc\Repositories\AuthCodeRepository;
use App\Services\AccessPolicyEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

class AuthorizationController extends Controller
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly AuthCodeRepository $authCodeRepository,
    ) {
    }

    /**
     * GET /oauth/authorize
     */
    public function authorize(Request $request): RedirectResponse|View
    {
        $requestedClient = OauthClient::query()->where('client_id', $request->query('client_id'))->first();

        if ($requestedClient?->pkce_required && ! $request->filled('code_challenge')) {
            abort(400, 'Diese Anwendung erfordert PKCE (code_challenge fehlt).');
        }

        $psrRequest = Psr7Bridge::toPsr7Request($request);

        try {
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $exception) {
            return $this->renderOAuthError($exception);
        }

        $client = OauthClient::query()->where('client_id', $authRequest->getClient()->getIdentifier())->with('application')->first();
        $application = $client?->application;

        if (! $application || ! $application->is_active) {
            abort(403, 'Diese Anwendung ist nicht aktiv.');
        }

        if (! Auth::check()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login');
        }

        $user = Auth::user();

        if (\App\Support\MaintenanceGate::applicationBlockedFor($application, $user)) {
            AuditLog::record('oauth.authorize.maintenance', $user, ['application' => $application->name], $application);
            abort(503, \App\Support\MaintenanceGate::applicationMessage($application));
        }

        if (! $this->userMayAccess($application, $user)) {
            AuditLog::record('oauth.authorize.denied', $user, ['application' => $application->name], $application);
            abort(403, 'Sie sind nicht berechtigt, diese Anwendung zu nutzen.');
        }

        $requestedScopes = array_map(fn ($s) => $s->getIdentifier(), $authRequest->getScopes());
        $nonce = $request->query('nonce');

        $authRequest->setUser(new UserEntity((string) $user->id));

        if ($this->consentSatisfied($application, $client, $user, $requestedScopes)) {
            $authRequest->setAuthorizationApproved(true);

            return $this->complete($authRequest, $nonce, $client, $user, $requestedScopes, $application);
        }

        $request->session()->put('oidc.pending_auth_request', serialize($authRequest));
        $request->session()->put('oidc.pending_nonce', $nonce);

        return view('oidc.consent', [
            'application' => $application,
            'client' => $client,
            'scopes' => \App\Models\OauthScope::query()->whereIn('key', $requestedScopes)->get(),
        ]);
    }

    /**
     * POST /oauth/authorize/decision
     */
    public function decision(Request $request): RedirectResponse
    {
        $request->validate(['decision' => 'required|in:allow,deny']);

        /** @var AuthorizationRequest|null $authRequest */
        $authRequest = $request->session()->get('oidc.pending_auth_request')
            ? unserialize($request->session()->get('oidc.pending_auth_request'))
            : null;

        $nonce = $request->session()->get('oidc.pending_nonce');
        $request->session()->forget(['oidc.pending_auth_request', 'oidc.pending_nonce']);

        if (! $authRequest instanceof AuthorizationRequest) {
            abort(400, 'Die Autorisierungsanfrage ist abgelaufen. Bitte erneut starten.');
        }

        $client = OauthClient::query()->where('client_id', $authRequest->getClient()->getIdentifier())->with('application')->first();
        $user = Auth::user();
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $authRequest->getScopes());

        $approved = $request->input('decision') === 'allow';
        $authRequest->setAuthorizationApproved($approved);

        if ($approved) {
            if ($request->boolean('remember')) {
                OauthConsent::updateOrCreate(
                    ['user_id' => $user->id, 'oauth_client_id' => $client->id],
                    ['scopes' => $scopes, 'granted_at' => now(), 'revoked_at' => null]
                );
            }
            AuditLog::record('oauth.consent.granted', $user, ['scopes' => $scopes, 'remembered' => $request->boolean('remember')], $client->application);
        } else {
            AuditLog::record('oauth.consent.denied', $user, ['scopes' => $scopes], $client->application);
        }

        return $this->complete($authRequest, $nonce, $client, $user, $scopes, $client->application);
    }

    private function complete(AuthorizationRequest $authRequest, ?string $nonce, OauthClient $client, $user, array $scopes, Application $application): RedirectResponse
    {
        NonceContext::set($nonce);
        $this->authCodeRepository->setPendingNonce($nonce);

        try {
            $psrResponse = $this->server->completeAuthorizationRequest($authRequest, Psr7Bridge::toPsr7Response());
        } catch (OAuthServerException $exception) {
            return $this->renderOAuthError($exception);
        }

        if ($authRequest->isAuthorizationApproved()) {
            AuditLog::record('oauth.authorize.success', $user, ['scopes' => $scopes], $application);
        }

        $laravelResponse = Psr7Bridge::toLaravelResponse($psrResponse);

        return redirect($laravelResponse->headers->get('Location'));
    }

    private function consentSatisfied(Application $application, OauthClient $client, $user, array $requestedScopes): bool
    {
        if (! $application->consent_required || $application->consent_mode === 'skip') {
            return true;
        }

        $consent = OauthConsent::query()
            ->where('user_id', $user->id)
            ->where('oauth_client_id', $client->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $consent) {
            return false;
        }

        if ($application->consent_mode === 'always') {
            return false;
        }

        if ($application->consent_mode === 'on_scope_change') {
            return empty(array_diff($requestedScopes, $consent->scopes ?? []));
        }

        // consent_mode === 'first_time' (default): once granted, never ask again.
        return true;
    }

    private function userMayAccess(Application $application, $user): bool
    {
        return AccessPolicyEvaluator::mayAccess($application, $user);
    }

    private function renderOAuthError(OAuthServerException $exception): RedirectResponse
    {
        $laravelResponse = Psr7Bridge::toLaravelResponse($exception->generateHttpResponse(Psr7Bridge::toPsr7Response()));

        if ($location = $laravelResponse->headers->get('Location')) {
            return redirect($location);
        }

        abort($exception->getHttpStatusCode(), $exception->getMessage());
    }
}
