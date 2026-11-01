<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthConsent;
use App\Models\OauthScope;
use App\Oidc\Entities\UserEntity;
use App\Oidc\NonceContext;
use App\Oidc\Psr7Bridge;
use App\Oidc\Repositories\AuthCodeRepository;
use App\Services\SingleLogoutService;
use App\Workflow\NodeResult;
use App\Workflow\WorkflowContext;
use App\Workflow\WorkflowResolver;
use App\Workflow\WorkflowRunner;
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
        private readonly WorkflowRunner $runner,
    ) {}

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

        $client = OauthClient::query()->where('client_id', $authRequest->getClient()->getIdentifier())->with('provider.application')->first();
        $application = $client?->application;

        if (! $application || ! $application->is_active) {
            abort(403, 'Diese Anwendung ist nicht aktiv.');
        }

        if (! Auth::check()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login');
        }

        $user = Auth::user();

        $requestedScopes = array_map(fn ($s) => $s->getIdentifier(), $authRequest->getScopes());

        // Only scopes the client is actually allowed to receive are shown / consented to.
        if (is_array($client->allowed_scopes) && $client->allowed_scopes !== []) {
            $allowed = [...$client->allowed_scopes, 'openid'];
            $requestedScopes = array_values(array_intersect($requestedScopes, $allowed));
        }

        $nonce = $request->query('nonce');

        $authRequest->setUser(new UserEntity((string) $user->id));

        // Wartung, Netzwerk/Uhrzeit, Richtlinien, Einwilligung usw. entscheidet
        // der Authorize-Workflow (global oder der Anwendung).
        $context = WorkflowContext::forAuthorize($user, $application, 'oidc', (string) session('auth.method', $user->last_login_method), [
            'client_id' => $client->id,
            'scopes' => $requestedScopes,
            'audit_prefix' => 'oauth.authorize',
            'audit_meta' => ['application' => $application->name],
        ]);

        $result = $this->runner->start(WorkflowResolver::for('authorize', $application), $context);

        return $this->conclude($request, $result, $authRequest, $nonce, $client, $user, $requestedScopes, $application);
    }

    private function conclude(Request $request, NodeResult $result, AuthorizationRequest $authRequest, ?string $nonce, OauthClient $client, $user, array $scopes, Application $application): RedirectResponse|View
    {
        if ($result->isAllow()) {
            $authRequest->setAuthorizationApproved(true);

            return $this->complete($authRequest, $nonce, $client, $user, $scopes, $application);
        }

        if ($result->isDeny()) {
            abort($result->status, (string) $result->message);
        }

        // Interaktion: Anfrage parken, nach der Interaktion geht es weiter.
        $request->session()->put('oidc.pending_auth_request', serialize($authRequest));
        $request->session()->put('oidc.pending_nonce', $nonce);

        if ($result->interaction === 'oidc_consent') {
            return view('oidc.consent', [
                'application' => $application,
                'client' => $client,
                'scopes' => OauthScope::query()->whereIn('key', $scopes)->get(),
            ]);
        }

        return redirect()->route('workflow.continue');
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

        $client = OauthClient::query()->where('client_id', $authRequest->getClient()->getIdentifier())->with('provider.application')->first();
        $user = Auth::user();
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $authRequest->getScopes());

        if ($request->input('decision') !== 'allow') {
            $this->runner->forget('authorize');
            AuditLog::record('oauth.consent.denied', $user, ['scopes' => $scopes], $client->application);
            $authRequest->setAuthorizationApproved(false);

            return $this->complete($authRequest, $nonce, $client, $user, $scopes, $client->application);
        }

        if ($request->boolean('remember')) {
            OauthConsent::updateOrCreate(
                ['user_id' => $user->id, 'oauth_client_id' => $client->id],
                ['scopes' => $scopes, 'granted_at' => now(), 'revoked_at' => null]
            );
        }
        AuditLog::record('oauth.consent.granted', $user, ['scopes' => $scopes, 'remembered' => $request->boolean('remember')], $client->application);

        // Der Workflow läuft hinter dem Einwilligungs-Baustein weiter.
        $result = $this->runner->resume('authorize', fn (WorkflowContext $context) => $context->setFlag('consent_given'));

        if (! $result) {
            abort(400, 'Die Autorisierungsanfrage ist abgelaufen. Bitte erneut starten.');
        }

        return $this->conclude($request, $result, $authRequest, $nonce, $client, $user, $scopes, $client->application);
    }

    /**
     * GET /oauth/authorize/resume – Rücksprung nach einer Workflow-Interaktion
     * (z. B. Hinweis oder Nutzungsbedingungen) mit der geparkten Anfrage.
     */
    public function resume(Request $request): RedirectResponse|View
    {
        $authRequest = $request->session()->get('oidc.pending_auth_request')
            ? unserialize($request->session()->get('oidc.pending_auth_request'))
            : null;
        $nonce = $request->session()->get('oidc.pending_nonce');
        $request->session()->forget(['oidc.pending_auth_request', 'oidc.pending_nonce']);

        $result = $authRequest instanceof AuthorizationRequest ? $this->runner->resume('authorize') : null;

        if (! $result) {
            abort(400, 'Die Autorisierungsanfrage ist abgelaufen. Bitte erneut starten.');
        }

        $client = OauthClient::query()->where('client_id', $authRequest->getClient()->getIdentifier())->with('provider.application')->firstOrFail();
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $authRequest->getScopes());

        return $this->conclude($request, $result, $authRequest, $nonce, $client, Auth::user(), $scopes, $client->application);
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
            app(SingleLogoutService::class)->recordOidc($user, session()->getId(), $client);
        }

        $laravelResponse = Psr7Bridge::toLaravelResponse($psrResponse);

        return redirect($laravelResponse->headers->get('Location'));
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
