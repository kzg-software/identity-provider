<?php

namespace App\Oidc\Repositories;

use App\Models\OauthClient;
use App\Models\OauthScope;
use App\Oidc\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (! OauthScope::query()->where('key', $identifier)->exists()) {
            return null;
        }

        return new ScopeEntity($identifier);
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        $client = OauthClient::query()->where('client_id', $clientEntity->getIdentifier())->first();

        if (! $client) {
            return [];
        }

        // Restrict to scopes explicitly defined in the system; unknown scopes are dropped.
        $validKeys = OauthScope::query()->pluck('key')->all();

        return array_values(array_filter($scopes, fn (ScopeEntityInterface $s) => in_array($s->getIdentifier(), $validKeys, true)));
    }
}
