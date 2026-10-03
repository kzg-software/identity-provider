<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\OauthClient;
use App\Models\OauthRedirectUri;
use App\Models\SamlServiceProvider;
use App\Models\SsoSession;
use App\Models\SystemSetting;
use App\Models\User;
use App\Oidc\IdTokenService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingleLogoutTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    /**
     * Im Testkontext bekommt jede Anfrage eine neue Session-ID. Fuer Single
     * Logout muss sie stabil sein, wie im Browser mit Session-Cookie.
     */
    private function useStableSession(): void
    {
        $this->sessionId = Str::random(40);
        $name = config('session.cookie');

        EncryptCookies::except($name);
        $this->withUnencryptedCookie($name, $this->sessionId);
    }

    private function login(): User
    {
        $this->useStableSession();
        SystemSetting::set('installed', '1');
        $user = User::factory()->create([
            'username' => 'jdoe', 'email' => 'jdoe@example.test', 'auth_source' => 'local',
            'is_active' => true, 'password' => bcrypt('Password123!'),
        ]);
        $this->post(route('login.attempt'), ['username' => 'jdoe', 'password' => 'Password123!'])
            ->assertRedirect(route('dashboard'));

        return $user;
    }

    private function oidcClient(?string $backchannelUri = 'https://client.example.test/backchannel'): OauthClient
    {
        $application = Application::create([
            'name' => 'OIDC App', 'slug' => 'oidc-app-'.Str::random(6),
            'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true,
        ]);
        $provider = $this->linkProvider($application, 'oidc', 'OIDC App');

        $client = OauthClient::create([
            'provider_id' => $provider->id,
            'name' => 'OIDC App',
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'secret',
            'allowed_grant_types' => ['authorization_code', 'refresh_token'],
            'access_token_lifetime' => 3600, 'refresh_token_lifetime' => 1209600, 'id_token_lifetime' => 3600,
            'pkce_required' => true, 'secret_required' => true, 'is_active' => true,
            'backchannel_logout_uri' => $backchannelUri,
        ]);

        OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => 'https://client.example.test/callback', 'type' => 'login']);

        return $client;
    }

    private function authorize(OauthClient $client): void
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertRedirect();
    }

    private function samlSp(string $entityId, string $slug): SamlServiceProvider
    {
        $application = Application::create([
            'name' => $slug, 'slug' => $slug.'-'.Str::random(6),
            'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true,
        ]);
        $provider = $this->linkProvider($application, 'saml', $slug);

        return SamlServiceProvider::create([
            'provider_id' => $provider->id,
            'name' => $slug,
            'entity_id' => $entityId,
            'acs_url' => 'https://'.$slug.'.example.test/acs',
            'slo_url' => 'https://'.$slug.'.example.test/slo',
            'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress',
            'sign_assertions' => true, 'sign_responses' => true, 'is_active' => true,
        ]);
    }

    private function samlLogin(SamlServiceProvider $sp): string
    {
        $xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_'.Str::uuid().'" Version="2.0" IssueInstant="'.now()->toIso8601ZuluString().'" AssertionConsumerServiceURL="'.$sp->acs_url.'"><saml:Issuer>'.$sp->entity_id.'</saml:Issuer></samlp:AuthnRequest>';

        $response = $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => base64_encode(gzdeflate($xml))]))->assertOk();

        return base64_decode($response->viewData('samlResponse'));
    }

    private function decodeRedirectRequest(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return gzinflate(base64_decode($query['SAMLRequest']));
    }

    public function test_portal_logout_sends_backchannel_logout_token_to_oidc_clients(): void
    {
        Http::fake(['client.example.test/*' => Http::response('', 200)]);
        $user = $this->login();
        $client = $this->oidcClient();

        $this->authorize($client);
        $this->assertSame(1, SsoSession::query()->where('protocol', 'oidc')->count());

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();

        Http::assertSent(function (HttpRequest $request) use ($client, $user) {
            if ($request->url() !== 'https://client.example.test/backchannel') {
                return false;
            }

            $claims = app(IdTokenService::class)->decode($request['logout_token']);

            return $claims !== null
                && $claims['aud'] === $client->client_id
                && $claims['sub'] === (string) $user->id
                && isset($claims['events']->{'http://schemas.openid.net/event/backchannel-logout'})
                && ! isset($claims['nonce']);
        });

        $this->assertSame(0, SsoSession::query()->count());
    }

    public function test_clients_without_backchannel_uri_are_not_called(): void
    {
        Http::fake();
        $this->login();
        $client = $this->oidcClient(backchannelUri: null);

        $this->authorize($client);
        $this->post(route('logout'));

        Http::assertNothingSent();
    }

    public function test_a_failing_backchannel_endpoint_does_not_break_logout(): void
    {
        Http::fake(['client.example.test/*' => Http::response('boom', 500)]);
        $this->login();
        $client = $this->oidcClient();

        $this->authorize($client);
        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'oauth.backchannel_logout.failed']);
    }

    public function test_rp_initiated_logout_skips_the_initiating_client(): void
    {
        Http::fake();
        $this->login();
        $client = $this->oidcClient();

        $this->authorize($client);
        $this->get(route('oauth.logout', ['client_id' => $client->client_id]))->assertRedirect();

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_discovery_advertises_backchannel_logout(): void
    {
        SystemSetting::set('installed', '1');

        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('backchannel_logout_supported', true);
    }

    public function test_sso_records_the_saml_session_with_index_and_name_id(): void
    {
        $sp = $this->samlSp('https://a.example.test/metadata', 'a');
        $this->login();

        $xml = $this->samlLogin($sp);

        $row = SsoSession::query()->where('protocol', 'saml')->firstOrFail();
        $this->assertSame('jdoe@example.test', $row->name_id);
        $this->assertStringContainsString('SessionIndex="'.$row->session_index.'"', $xml);
    }

    public function test_portal_logout_sends_saml_logout_requests_to_other_service_providers(): void
    {
        $spA = $this->samlSp('https://a.example.test/metadata', 'a');
        $spB = $this->samlSp('https://b.example.test/metadata', 'b');
        $this->login();
        $this->samlLogin($spA);
        $this->samlLogin($spB);

        $response = $this->post(route('logout'))->assertOk()->assertViewIs('saml.slo_chain');
        $this->assertGuest();

        $requests = $response->viewData('logoutRequests');
        $this->assertCount(2, $requests);

        $sessionIndexA = null;
        foreach ($requests as $request) {
            $xml = $this->decodeRedirectRequest($request['url']);
            $this->assertStringContainsString('<samlp:LogoutRequest', $xml);
            $this->assertStringContainsString('jdoe@example.test', $xml);
            $this->assertStringContainsString('<samlp:SessionIndex>', $xml);
            $this->assertStringContainsString('Signature=', $request['url']);
        }

        $this->assertSame(route('login', ['manual' => 1]), $response->viewData('finalUrl'));
        $this->assertSame(0, SsoSession::query()->count());
    }

    public function test_sp_initiated_logout_propagates_to_the_other_service_providers_only(): void
    {
        $spA = $this->samlSp('https://a.example.test/metadata', 'a');
        $spB = $this->samlSp('https://b.example.test/metadata', 'b');
        $this->login();
        $this->samlLogin($spA);
        $this->samlLogin($spB);

        $logoutXml = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_'.Str::uuid().'" Version="2.0" IssueInstant="'.now()->toIso8601ZuluString().'"><saml:Issuer>'.$spA->entity_id.'</saml:Issuer><saml:NameID>jdoe@example.test</saml:NameID></samlp:LogoutRequest>';

        $response = $this->get('/saml/slo?'.http_build_query(['SAMLRequest' => base64_encode(gzdeflate($logoutXml)), 'RelayState' => 'rs1']))
            ->assertOk()
            ->assertViewIs('saml.slo_chain');

        $requests = $response->viewData('logoutRequests');
        $this->assertCount(1, $requests);
        $this->assertStringStartsWith('https://b.example.test/slo?', $requests[0]['url']);

        $form = $response->viewData('finalForm');
        $this->assertSame('https://a.example.test/slo', $form['action']);
        $this->assertSame('rs1', $form['fields']['RelayState']);
        $this->assertStringContainsString('<samlp:LogoutResponse', base64_decode($form['fields']['SAMLResponse']));
        $this->assertGuest();
    }

    public function test_revoking_a_session_sends_backchannel_logout(): void
    {
        Http::fake(['client.example.test/*' => Http::response('', 200)]);
        $user = $this->login();
        $client = $this->oidcClient();
        $this->authorize($client);

        $session = $user->sessions()->firstOrFail();
        $session->update(['session_id' => $this->sessionId]);
        app(\App\Services\SessionTracker::class)->revoke($session);

        Http::assertSentCount(1);
    }
}
