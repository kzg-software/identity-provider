<?php

namespace App\Oidc;

use App\Oidc\Repositories\AccessTokenRepository;
use App\Oidc\Repositories\AuthCodeRepository;
use App\Oidc\Repositories\ClientRepository;
use App\Oidc\Repositories\RefreshTokenRepository;
use App\Oidc\Repositories\ScopeRepository;
use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;

class OAuthServerFactory
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly AccessTokenRepository $accessTokens,
        private readonly ScopeRepository $scopes,
        private readonly AuthCodeRepository $authCodes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly OidcKeyService $keys,
    ) {
    }

    /** Symmetric password used by league to encrypt authorization codes / refresh tokens at rest in transit. */
    public function encryptionKey(): string
    {
        return hash('sha256', config('app.key').'|oauth-encryption');
    }

    public function authorizationServer(): AuthorizationServer
    {
        $key = $this->keys->activeKey();
        $privateKey = new CryptKey($key->private_key_encrypted, null, false);

        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            $privateKey,
            $this->encryptionKey(),
            new OidcTokenResponse()
        );

        $server->setDefaultScope('openid');

        $authCodeGrant = new AuthCodeGrant($this->authCodes, $this->refreshTokens, new DateInterval('PT10M'));
        $authCodeGrant->setRefreshTokenTTL(new DateInterval('P14D'));
        $server->enableGrantType($authCodeGrant, new DateInterval('PT1H'));

        $refreshGrant = new RefreshTokenGrant($this->refreshTokens);
        $refreshGrant->setRefreshTokenTTL(new DateInterval('P14D'));
        $server->enableGrantType($refreshGrant, new DateInterval('PT1H'));

        $server->enableGrantType(new ClientCredentialsGrant(), new DateInterval('PT1H'));

        return $server;
    }

    public function resourceServer(): ResourceServer
    {
        $key = $this->keys->activeKey();
        $publicKey = new CryptKey($key->public_key, null, false);

        return new ResourceServer($this->accessTokens, $publicKey);
    }
}
