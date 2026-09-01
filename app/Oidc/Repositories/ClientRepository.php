<?php

namespace App\Oidc\Repositories;

use App\Models\OauthClient;
use App\Oidc\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->findActive($clientIdentifier);

        if (! $client) {
            return null;
        }

        $redirectUris = $client->redirectUris()->where('type', 'login')->pluck('uri')->all();

        return new ClientEntity($client->client_id, $client->name, $redirectUris, $client->secret_required);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = $this->findActive($clientIdentifier);

        if (! $client) {
            return false;
        }

        $allowedGrants = $client->allowed_grant_types ?? [];
        if ($grantType !== null && ! empty($allowedGrants) && ! in_array($grantType, $allowedGrants, true)) {
            return false;
        }

        if (! $client->secret_required) {
            return true;
        }

        return $clientSecret !== null && password_verify($clientSecret, $client->client_secret ?? '');
    }

    private function findActive(string $clientIdentifier): ?OauthClient
    {
        return OauthClient::query()
            ->where('client_id', $clientIdentifier)
            ->where('is_active', true)
            ->first();
    }
}
