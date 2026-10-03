<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\SamlServiceProvider;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AccessRestrictions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ip_ranges_match_single_addresses_and_cidr_networks(): void
    {
        $ranges = "10.0.0.0/8, 192.168.1.5\n2001:db8::/32";

        $this->assertTrue(AccessRestrictions::ipAllowed('10.20.30.40', $ranges));
        $this->assertTrue(AccessRestrictions::ipAllowed('192.168.1.5', $ranges));
        $this->assertTrue(AccessRestrictions::ipAllowed('2001:db8::1', $ranges));
        $this->assertFalse(AccessRestrictions::ipAllowed('192.168.1.6', $ranges));
        $this->assertFalse(AccessRestrictions::ipAllowed('8.8.8.8', $ranges));
        $this->assertTrue(AccessRestrictions::ipAllowed('8.8.8.8', ''));
    }

    public function test_ip_range_validation(): void
    {
        $this->assertTrue(AccessRestrictions::isValidIpRange('10.0.0.0/8'));
        $this->assertTrue(AccessRestrictions::isValidIpRange('::1'));
        $this->assertFalse(AccessRestrictions::isValidIpRange('10.0.0.0/33'));
        $this->assertFalse(AccessRestrictions::isValidIpRange('nonsense'));
    }

    public function test_time_windows(): void
    {
        $windows = "Mo-Fr 08:00-18:00\nSa 09:00-12:00";

        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-02 10:00'), $windows));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-02 18:00'), $windows));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-02 07:59'), $windows));
        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-07 11:30'), $windows));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-08 10:00'), $windows));
        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-08 10:00'), ''));
    }

    public function test_time_windows_across_midnight_and_day_lists(): void
    {
        $windows = 'Fr 22:00-06:00';

        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-06 23:00'), $windows));
        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-07 05:00'), $windows));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-07 07:00'), $windows));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-05 23:00'), $windows));

        $this->assertTrue(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-04 09:00'), 'Mo,Mi,Fr 08:00-10:00'));
        $this->assertFalse(AccessRestrictions::timeAllowed(Carbon::parse('2026-03-03 09:00'), 'Mo,Mi,Fr 08:00-10:00'));
    }

    public function test_invalid_time_window_syntax_is_rejected(): void
    {
        $this->assertNull(AccessRestrictions::parseTimeWindows('Montag bis Freitag'));
        $this->assertNull(AccessRestrictions::parseTimeWindows('Xx 08:00-18:00'));
        $this->assertNull(AccessRestrictions::parseTimeWindows('Mo 25:00-26:00'));
        $this->assertSame([], AccessRestrictions::parseTimeWindows(''));
    }

    private function samlSp(array $applicationOverrides = []): SamlServiceProvider
    {
        $application = Application::create(array_merge([
            'name' => 'Restricted SP',
            'slug' => 'restricted-sp-'.Str::random(6),
            'consent_required' => false,
            'consent_mode' => 'skip',
            'login_mode' => 'user_choice',
            'is_active' => true,
        ], $applicationOverrides));

        $provider = $this->linkProvider($application, 'saml', 'Restricted SP');

        return SamlServiceProvider::create([
            'provider_id' => $provider->id,
            'name' => 'Restricted SP',
            'entity_id' => 'https://restricted.example.test/metadata',
            'acs_url' => 'https://restricted.example.test/acs',
            'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress',
            'sign_assertions' => true,
            'sign_responses' => true,
            'is_active' => true,
        ]);
    }

    private function ssoRequest(SamlServiceProvider $sp)
    {
        $id = '_'.Str::uuid();
        $xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="'.$id.'" Version="2.0" IssueInstant="'.now()->toIso8601ZuluString().'" AssertionConsumerServiceURL="'.$sp->acs_url.'"><saml:Issuer>'.$sp->entity_id.'</saml:Issuer></samlp:AuthnRequest>';

        return $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => base64_encode(gzdeflate($xml))]));
    }

    private function login(): void
    {
        SystemSetting::set('installed', '1');
        User::factory()->create([
            'username' => 'jdoe', 'email' => 'jdoe@example.test', 'auth_source' => 'local',
            'is_active' => true, 'password' => bcrypt('Password123!'),
        ]);
        $this->post(route('login.attempt'), ['username' => 'jdoe', 'password' => 'Password123!'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_sso_is_blocked_outside_the_allowed_network(): void
    {
        $sp = $this->samlSp(['allowed_ip_ranges' => '10.0.0.0/8']);
        $this->login();

        $this->ssoRequest($sp)->assertStatus(403);
    }

    public function test_sso_is_allowed_inside_the_allowed_network(): void
    {
        $sp = $this->samlSp(['allowed_ip_ranges' => '127.0.0.1']);
        $this->login();

        $this->ssoRequest($sp)->assertOk()->assertViewIs('saml.auto_submit');
    }

    public function test_sso_is_blocked_outside_the_allowed_time_window(): void
    {
        $sp = $this->samlSp(['access_time_windows' => 'Mo-Fr 08:00-18:00']);
        $this->login();

        Carbon::setTestNow(Carbon::parse('2026-03-08 10:00'));
        $this->ssoRequest($sp)->assertStatus(403);

        Carbon::setTestNow(Carbon::parse('2026-03-09 10:00'));
        $this->ssoRequest($sp)->assertOk();
    }

    public function test_oidc_authorize_is_blocked_outside_the_allowed_network(): void
    {
        $application = Application::create([
            'name' => 'OIDC', 'slug' => 'oidc-'.Str::random(6), 'consent_required' => false, 'consent_mode' => 'skip',
            'login_mode' => 'user_choice', 'is_active' => true, 'allowed_ip_ranges' => '10.0.0.0/8',
        ]);
        $provider = $this->linkProvider($application, 'oidc', 'OIDC');
        $client = \App\Models\OauthClient::create([
            'provider_id' => $provider->id, 'name' => 'OIDC', 'client_id' => (string) Str::uuid(), 'client_secret' => 's',
            'allowed_grant_types' => ['authorization_code'], 'access_token_lifetime' => 3600, 'refresh_token_lifetime' => 3600,
            'id_token_lifetime' => 3600, 'pkce_required' => true, 'secret_required' => true, 'is_active' => true,
        ]);
        \App\Models\OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => 'https://c.example.test/cb', 'type' => 'login']);
        $this->login();

        $this->get(route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://c.example.test/cb',
            'response_type' => 'code',
            'scope' => 'openid',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', ['event' => 'oauth.authorize.restricted']);
    }

    public function test_admin_can_save_restrictions_and_invalid_input_is_rejected(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
        $application = Application::create(['name' => 'App', 'slug' => 'app', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.applications.update', $application), [
            'section' => 'zugriff',
            'allowed_ip_ranges' => "10.0.0.0/8\n192.168.0.0/16",
            'access_time_windows' => 'Mo-Fr 08:00-18:00',
        ])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertStringContainsString('192.168.0.0/16', $application->allowed_ip_ranges);
        $this->assertSame('Mo-Fr 08:00-18:00', $application->access_time_windows);

        $this->actingAs($admin)->put(route('admin.applications.update', $application), [
            'section' => 'zugriff',
            'allowed_ip_ranges' => 'not-an-ip',
            'access_time_windows' => '',
        ])->assertSessionHasErrors('allowed_ip_ranges');

        $this->actingAs($admin)->put(route('admin.applications.update', $application), [
            'section' => 'zugriff',
            'allowed_ip_ranges' => '',
            'access_time_windows' => 'whenever',
        ])->assertSessionHasErrors('access_time_windows');

        $this->actingAs($admin)->get(route('admin.applications.show', ['application' => $application, 'tab' => 'zugriff']))
            ->assertOk()->assertSee('Netzwerk und Uhrzeit');
    }
}
