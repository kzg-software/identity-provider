<?php

namespace Tests\Unit;

use App\Models\OauthClient;
use App\Oidc\Entities\ClientEntity;
use App\Oidc\Entities\ScopeEntity;
use App\Oidc\Repositories\ScopeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScopeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function client(?array $allowedScopes): OauthClient
    {
        return OauthClient::create([
            'name' => 'Demo',
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'secret',
            'allowed_grant_types' => ['authorization_code'],
            'allowed_scopes' => $allowedScopes,
            'access_token_lifetime' => 3600,
            'refresh_token_lifetime' => 1209600,
            'id_token_lifetime' => 3600,
            'is_active' => true,
        ]);
    }

    /** @param string[] $keys */
    private function finalize(OauthClient $client, array $keys): array
    {
        $scopes = array_map(fn ($k) => new ScopeEntity($k), $keys);
        $clientEntity = new ClientEntity($client->client_id, $client->name, [], true);

        return array_map(
            fn ($s) => $s->getIdentifier(),
            (new ScopeRepository)->finalizeScopes($scopes, 'authorization_code', $clientEntity)
        );
    }

    public function test_null_allow_list_keeps_all_known_scopes(): void
    {
        $client = $this->client(null);

        $this->assertEqualsCanonicalizing(
            ['openid', 'profile', 'email'],
            $this->finalize($client, ['openid', 'profile', 'email']),
        );
    }

    public function test_allow_list_drops_scopes_not_granted_to_the_client(): void
    {
        $client = $this->client(['openid', 'profile']);

        $this->assertEqualsCanonicalizing(
            ['openid', 'profile'],
            $this->finalize($client, ['openid', 'profile', 'email', 'groups']),
        );
    }

    public function test_openid_is_always_allowed(): void
    {
        $client = $this->client(['profile']);

        $this->assertEqualsCanonicalizing(
            ['openid', 'profile'],
            $this->finalize($client, ['openid', 'profile']),
        );
    }
}
