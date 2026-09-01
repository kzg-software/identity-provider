<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OauthToken;
use App\Oidc\NonceContext;
use App\Oidc\OAuthServerFactory;
use App\Oidc\Psr7Bridge;
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
