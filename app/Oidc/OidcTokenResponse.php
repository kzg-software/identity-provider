<?php

namespace App\Oidc;

use App\Models\OauthClient;
use App\Models\User;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/**
 * Extends the plain OAuth2 bearer response with an `id_token` claim whenever
 * the granted scopes include "openid" and the token belongs to a real user
 * (i.e. not a client_credentials machine-to-machine token).
 */
class OidcTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $accessToken->getScopes());

        if (! in_array('openid', $scopes, true) || $accessToken->getUserIdentifier() === null) {
            return [];
        }

        $user = User::find($accessToken->getUserIdentifier());
        $client = OauthClient::query()->where('client_id', $accessToken->getClient()->getIdentifier())->first();

        if (! $user || ! $client) {
            return [];
        }

        $idTokenService = app(IdTokenService::class);

        $idToken = $idTokenService->issue(
            $user,
            $client,
            $scopes,
            NonceContext::get(),
            $client->id_token_lifetime ?? 3600
        );

        NonceContext::clear();

        return ['id_token' => $idToken];
    }
}
