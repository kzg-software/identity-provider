<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthToken;
use App\Models\User;
use App\Oidc\NonceContext;
use App\Oidc\OAuthServerFactory;
use App\Oidc\Psr7Bridge;
use App\Services\AccessPolicyEvaluator;
use App\Support\MaintenanceGate;
use Defuse\Crypto\Crypto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;

class TokenController extends Controller
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly OAuthServerFactory $factory,
    ) {
    }

    /**
     * POST /oauth/token
     */
    public function issue(Request $request): Response
    {
        NonceContext::clear();

        if ($request->input('grant_type') === 'authorization_code' && $request->filled('code')) {
            NonceContext::set($this->nonceForAuthorizationCode($request->input('code')));
        }

        if ($request->input('grant_type') === 'refresh_token' && $request->filled('refresh_token')
            && $denied = $this->refreshDenied((string) $request->input('refresh_token'))) {
            return $denied;
        }

        try {
            $psrResponse = $this->server->respondToAccessTokenRequest(Psr7Bridge::toPsr7Request($request), Psr7Bridge::toPsr7Response());
        } catch (OAuthServerException $exception) {
            AuditLog::record('oauth.token.failed', null, ['error' => $exception->getErrorType()]);

            return Psr7Bridge::toLaravelResponse($exception->generateHttpResponse(Psr7Bridge::toPsr7Response()));
        } finally {
            NonceContext::clear();
        }

        AuditLog::record('oauth.token.issued', $request->user());

        return Psr7Bridge::toLaravelResponse($psrResponse);
    }

    /**
     * POST /oauth/revoke - RFC 7009
     */
    public function revoke(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $token = OauthToken::query()
            ->whereIn('type', ['access_token', 'refresh_token'])
            ->where('identifier', $request->input('token'))
            ->first();

        if ($token) {
            $token->update(['revoked' => true]);
            AuditLog::record('oauth.token.revoked', null, ['type' => $token->type]);
        }

        return response()->json([], 200);
    }

    /**
     * Ein Refresh-Token darf nur erneuert werden, solange das Konto weiterhin
     * Zugriff hätte: Konto und Anwendung aktiv, keine Wartung, Richtlinien
     * erfüllt. Netz und Uhrzeit werden hier bewusst nicht geprüft – die
     * Anfrage kommt vom Server der Anwendung, nicht vom Browser des Nutzers.
     * Bei Ablehnung wird das Refresh-Token widerrufen.
     */
    private function refreshDenied(string $refreshToken): ?Response
    {
        try {
            $payload = json_decode(Crypto::decryptWithPassword($refreshToken, $this->factory->encryptionKey()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null; // ungültiges Token: Fehler liefert der OAuth-Server selbst
        }

        $stored = OauthToken::query()->where('type', 'refresh_token')->where('identifier', $payload['refresh_token_id'] ?? null)->first();

        if (! $stored || ! $stored->user_id) {
            return null;
        }

        $user = User::query()->find($stored->user_id);
        $application = OauthClient::query()->with('provider.application')->find($stored->oauth_client_id)?->application;

        $reason = match (true) {
            ! $user || ! $user->is_active => 'user_inactive',
            $application && ! $application->is_active => 'application_inactive',
            $application && MaintenanceGate::applicationBlockedFor($application, $user) => 'maintenance',
            ! AccessPolicyEvaluator::passesPolicies($application?->id, $user) => 'policy',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        $stored->update(['revoked' => true]);
        AuditLog::record('oauth.token.refresh_denied', $user, ['reason' => $reason], $application);

        return response(json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Der Zugriff auf diese Anwendung ist nicht mehr erlaubt.',
        ]), 400, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    private function nonceForAuthorizationCode(string $code): ?string
    {
        try {
            $payload = json_decode(Crypto::decryptWithPassword($code, $this->factory->encryptionKey()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $authCodeId = $payload['auth_code_id'] ?? null;

        if (! $authCodeId) {
            return null;
        }

        $stored = OauthToken::query()->where('type', 'authorization_code')->where('identifier', $authCodeId)->first();

        return $stored?->metadata['nonce'] ?? null;
    }
}
