<?php

namespace App\Providers;

use App\Oidc\OAuthServerFactory;
use App\Oidc\Repositories\AccessTokenRepository;
use App\Oidc\Repositories\AuthCodeRepository;
use App\Oidc\Repositories\ClientRepository;
use App\Oidc\Repositories\RefreshTokenRepository;
use App\Oidc\Repositories\ScopeRepository;
use Illuminate\Support\ServiceProvider;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

class OidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClientRepository::class);
        $this->app->singleton(ScopeRepository::class);
        $this->app->singleton(AccessTokenRepository::class);
        $this->app->singleton(RefreshTokenRepository::class);
        $this->app->singleton(AuthCodeRepository::class);
        $this->app->singleton(OAuthServerFactory::class);

        $this->app->singleton(AuthorizationServer::class, fn ($app) => $app->make(OAuthServerFactory::class)->authorizationServer());
        $this->app->singleton(ResourceServer::class, fn ($app) => $app->make(OAuthServerFactory::class)->resourceServer());
    }
}
