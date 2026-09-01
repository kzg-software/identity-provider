<?php

namespace App\Http\Middleware;

use App\Oidc\Psr7Bridge;
use Closure;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response;

class ValidateOAuthAccessToken
{
    public function __construct(private readonly ResourceServer $resourceServer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $psrRequest = $this->resourceServer->validateAuthenticatedRequest(Psr7Bridge::toPsr7Request($request));
        } catch (OAuthServerException $exception) {
            return Psr7Bridge::toLaravelResponse($exception->generateHttpResponse(Psr7Bridge::toPsr7Response()));
        }

        $request->attributes->set('oauth_user_id', $psrRequest->getAttribute('oauth_user_id'));
        $request->attributes->set('oauth_client_id', $psrRequest->getAttribute('oauth_client_id'));
        $request->attributes->set('oauth_scopes', $psrRequest->getAttribute('oauth_scopes'));

        return $next($request);
    }
}
