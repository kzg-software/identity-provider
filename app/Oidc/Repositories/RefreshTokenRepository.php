<?php

namespace App\Oidc\Repositories;

use App\Models\OauthToken;
use App\Oidc\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = OauthToken::query()->where('type', 'access_token')
            ->where('identifier', $refreshTokenEntity->getAccessToken()->getIdentifier())
            ->first();

        OauthToken::create([
            'user_id' => $accessToken?->user_id,
            'oauth_client_id' => $accessToken?->oauth_client_id,
            'type' => 'refresh_token',
            'identifier' => $refreshTokenEntity->getIdentifier(),
            'scopes' => $accessToken?->scopes,
            'metadata' => ['access_token_identifier' => $refreshTokenEntity->getAccessToken()->getIdentifier()],
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        OauthToken::query()->where('type', 'refresh_token')->where('identifier', $tokenId)->update(['revoked' => true]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $token = OauthToken::query()->where('type', 'refresh_token')->where('identifier', $tokenId)->first();

        return ! $token || $token->revoked;
    }
}
