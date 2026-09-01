<?php

namespace App\Oidc\Repositories;

use App\Models\OauthClient;
use App\Models\OauthToken;
use App\Oidc\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    /** Nonce for the auth code about to be persisted (set by the controller just before completeAuthorizationRequest). */
    private ?string $pendingNonce = null;

    public function setPendingNonce(?string $nonce): void
    {
        $this->pendingNonce = $nonce;
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $client = OauthClient::query()->where('client_id', $authCodeEntity->getClient()->getIdentifier())->first();

        OauthToken::create([
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'oauth_client_id' => $client?->id,
            'type' => 'authorization_code',
            'identifier' => $authCodeEntity->getIdentifier(),
            'scopes' => array_map(fn ($s) => $s->getIdentifier(), $authCodeEntity->getScopes()),
            'metadata' => [
                'redirect_uri' => $authCodeEntity->getRedirectUri(),
                'nonce' => $this->pendingNonce,
            ],
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ]);

        $this->pendingNonce = null;
    }

    public function revokeAuthCode(string $codeId): void
    {
        OauthToken::query()->where('type', 'authorization_code')->where('identifier', $codeId)->update(['revoked' => true]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $token = OauthToken::query()->where('type', 'authorization_code')->where('identifier', $codeId)->first();

        return ! $token || $token->revoked;
    }
}
