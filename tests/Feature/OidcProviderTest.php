<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\OauthClient;
use App\Models\OauthRedirectUri;
use App\Models\SystemSetting;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key as JwtKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OidcProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createApp(array $overrides = []): array
    {
        $application = Application::create(array_merge([
            'name' => 'Demo App',
            'slug' => 'demo-app-'.Str::random(6),
            'consent_required' => false,
            'consent_mode' => 'skip',
            'login_mode' => 'user_choice',
            'is_active' => true,
        ], $overrides));

        $client = OauthClient::create([
            'application_id' => $application->id,
            'name' => 'Demo App',
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'demo-secret',
            'allowed_grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'access_token_lifetime' => 3600,
            'refresh_token_lifetime' => 1209600,
            'id_token_lifetime' => 3600,
            'pkce_required' => true,
            'secret_required' => true,
            'is_active' => true,
        ]);

        OauthRedirectUri::create([
            'oauth_client_id' => $client->id,
            'uri' => 'https://client.example.test/callback',
            'type' => 'login',
        ]);

        return [$application, $client];
    }

    private function pkcePair(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function loginUser(): User
    {
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'username' => 'jdoe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'name' => 'John Doe',
            'email' => 'jdoe@example.test',
            'auth_source' => 'local',
            'is_admin' => false,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);

        $this->post(route('login.attempt'), ['username' => 'jdoe', 'password' => 'Password123!'])
            ->assertRedirect(route('dashboard'));

        return $user;
    }

    public function test_discovery_document_is_published(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->getJson('/.well-known/openid-configuration')->assertOk();
        $response->assertJsonStructure([
            'issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint',
            'jwks_uri', 'scopes_supported', 'response_types_supported', 'grant_types_supported', 'claims_supported',
        ]);
    }

    public function test_jwks_exposes_only_public_key_material(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->getJson('/.well-known/jwks.json')->assertOk();
        $response->assertJsonStructure(['keys' => [['kty', 'use', 'alg', 'kid', 'n', 'e']]]);
        $response->assertDontSee('BEGIN PRIVATE KEY');
    }

    public function test_authorization_code_flow_with_pkce_issues_valid_tokens(): void
    {
        [$application, $client] = $this->createApp();
        $user = $this->loginUser();
        [$verifier, $challenge] = $this->pkcePair();

        $authorize = $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'xyz123',
            'nonce' => 'nonce-abc',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $authorize->assertRedirect();
        $location = $authorize->headers->get('Location');
        $this->assertStringStartsWith('https://client.example.test/callback', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz123', $query['state']);
        $this->assertArrayHasKey('code', $query);

        $token = $this->postJson(route('oauth.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'client_secret' => 'demo-secret',
            'redirect_uri' => 'https://client.example.test/callback',
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ])->assertOk();

        $token->assertJsonStructure(['access_token', 'refresh_token', 'id_token', 'token_type', 'expires_in']);

        $idToken = $token->json('id_token');
        $claims = $this->verifyIdToken($idToken);

        $this->assertSame((string) $user->id, $claims->sub);
        $this->assertSame($client->client_id, $claims->aud);
        $this->assertSame('nonce-abc', $claims->nonce);
        $this->assertSame('jdoe@example.test', $claims->email);
        $this->assertSame('John Doe', $claims->name);

        // UserInfo endpoint with the access token
        $userInfo = $this->withHeader('Authorization', 'Bearer '.$token->json('access_token'))
            ->getJson(route('oauth.userinfo'))
            ->assertOk();
        $userInfo->assertJson(['sub' => (string) $user->id, 'email' => 'jdoe@example.test']);

        // Refresh token grant
        $refreshed = $this->postJson(route('oauth.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'client_secret' => 'demo-secret',
            'refresh_token' => $token->json('refresh_token'),
        ])->assertOk();
        $refreshed->assertJsonStructure(['access_token', 'refresh_token']);
        $this->assertNotSame($token->json('access_token'), $refreshed->json('access_token'));

        // Revoke the original access token, then it must be rejected
        $this->postJson(route('oauth.revoke'), ['token' => $token->json('access_token')])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token->json('access_token'))
            ->getJson(route('oauth.userinfo'))
            ->assertStatus(401);
    }

    public function test_authorize_without_login_redirects_to_login_first(): void
    {
        [$application, $client] = $this->createApp();
        SystemSetting::set('installed', '1');
        [, $challenge] = $this->pkcePair();

        $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertRedirect(route('login'));
    }

    public function test_pkce_is_enforced_when_required(): void
    {
        [$application, $client] = $this->createApp();
        $this->loginUser();

        $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
        ]))->assertStatus(400);
    }

    public function test_invalid_redirect_uri_is_rejected(): void
    {
        [$application, $client] = $this->createApp();
        $this->loginUser();
        [, $challenge] = $this->pkcePair();

        $response = $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://evil.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));

        $response->assertStatus(401);
    }

    public function test_consent_screen_is_shown_and_can_be_denied(): void
    {
        [$application, $client] = $this->createApp(['consent_required' => true, 'consent_mode' => 'always']);
        $this->loginUser();
        [$verifier, $challenge] = $this->pkcePair();

        $authorize = $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => 'abc',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();

        $authorize->assertViewIs('oidc.consent');

        $decision = $this->post(route('oauth.authorize.decision'), ['decision' => 'deny']);
        $decision->assertRedirect();
        $this->assertStringContainsString('error=access_denied', $decision->headers->get('Location'));
    }

    public function test_client_credentials_grant_issues_access_token_without_id_token(): void
    {
        [$application, $client] = $this->createApp();
        SystemSetting::set('installed', '1');

        $token = $this->postJson(route('oauth.token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $client->client_id,
            'client_secret' => 'demo-secret',
            'scope' => 'profile',
        ])->assertOk();

        $token->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
        $this->assertArrayNotHasKey('id_token', $token->json());
    }

    public function test_access_policy_deny_blocks_authorization(): void
    {
        [$application, $client] = $this->createApp();
        $user = $this->loginUser();

        $directory = Directory::create(['name' => 'Corp', 'type' => 'active_directory', 'is_active' => true]);
        $group = DirectoryGroup::create([
            'directory_id' => $directory->id,
            'name' => 'Vertrieb',
            'object_guid' => (string) Str::uuid(),
            'distinguished_name' => 'CN=Vertrieb,DC=corp,DC=test',
        ]);
        $directoryUser = DirectoryUser::create([
            'directory_id' => $directory->id,
            'user_id' => $user->id,
            'sam_account_name' => 'jdoe',
            'object_guid' => (string) Str::uuid(),
            'distinguished_name' => 'CN=jdoe,DC=corp,DC=test',
        ]);
        $directoryUser->groups()->attach($group->id, ['is_nested' => false, 'synced_at' => now()]);

        AccessPolicy::create([
            'application_id' => $application->id,
            'effect' => 'deny',
            'subject_type' => 'group',
            'subject_value' => 'Vertrieb',
            'priority' => 10,
        ]);

        [, $challenge] = $this->pkcePair();

        $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertStatus(403);
    }

    private function verifyIdToken(string $jwt): object
    {
        $jwks = $this->getJson('/.well-known/jwks.json')->json('keys');
        $key = $jwks[0];

        $publicKeyPem = $this->jwkToPem($key['n'], $key['e']);

        return JWT::decode($jwt, new JwtKey($publicKeyPem, 'RS256'));
    }

    private function jwkToPem(string $n, string $e): string
    {
        // Build the DER-encoded RSA public key from the JWK modulus/exponent, then wrap as PEM.
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);

        $modulusEncoded = $this->derInteger($modulus);
        $exponentEncoded = $this->derInteger($exponent);

        $rsaPublicKeySeq = $this->derSequence($modulusEncoded.$exponentEncoded);

        $algorithmSeq = $this->derSequence(
            hex2bin('06092a864886f70d0101010500') // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
        );

        $bitString = "\x00".$rsaPublicKeySeq;
        $bitStringEncoded = "\x03".$this->derLength(strlen($bitString)).$bitString;

        $publicKeyInfo = $this->derSequence($algorithmSeq.$bitStringEncoded);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($publicKeyInfo), 64)."-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function derInteger(string $value): string
    {
        if (ord($value[0]) > 0x7f) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derSequence(string $value): string
    {
        return "\x30".$this->derLength(strlen($value)).$value;
    }
}
