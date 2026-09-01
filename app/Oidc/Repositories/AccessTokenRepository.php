<?php

namespace App\Oidc\Repositories;

use App\Models\OauthClient;
use App\Models\OauthToken;
use App\Oidc\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);

        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $client = OauthClient::query()->where('client_id', $accessTokenEntity->getClient()->getIdentifier())->first();

        OauthToken::create([
            'user_id' => $accessTokenEntity->getUserIdentifier(),
            'oauth_client_id' => $client?->id,
            'type' => 'access_token',
            'identifier' => $accessTokenEntity->getIdentifier(),
            'scopes' => array_map(fn ($s) => $s->getIdentifier(), $accessTokenEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        OauthToken::query()->where('type', 'access_token')->where('identifier', $tokenId)->update(['revoked' => true]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $token = OauthToken::query()->where('type', 'access_token')->where('identifier', $tokenId)->first();

        return ! $token || $token->revoked;
    }
}
