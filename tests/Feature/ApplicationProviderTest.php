<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\OauthClient;
use App\Models\Provider;
use App\Models\SamlServiceProvider;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);
    }

    public function test_application_can_be_created_without_a_provider(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.applications.store'), [
                'name' => 'Internes Wiki',
                'visibility' => 'portal',
                'provider_mode' => 'none',
            ])
            ->assertRedirect();

        $app = Application::firstWhere('name', 'Internes Wiki');
        $this->assertNotNull($app);
        $this->assertNull($app->provider_id);
    }

    public function test_wizard_creates_oidc_provider_and_links_it(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.applications.store'), [
                'name' => 'Paperless',
                'visibility' => 'portal',
                'provider_mode' => 'oidc',
                'redirect_uris' => 'https://paperless.example.de/oauth/callback',
                'scopes' => ['openid', 'profile', 'email'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'access_token_lifetime' => 3600,
                'refresh_token_lifetime' => 1209600,
                'id_token_lifetime' => 3600,
                'pkce_required' => '1',
                'secret_required' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('plain_client_secret');

        $app = Application::firstWhere('name', 'Paperless');
        $this->assertNotNull($app->provider);
        $this->assertTrue($app->provider->isOidc());

        $client = $app->provider->oauthClient;
        $this->assertNotNull($client);
        $this->assertEquals(['https://paperless.example.de/oauth/callback'], $client->redirectUris->where('type', 'login')->pluck('uri')->all());
        $this->assertContains('email', $client->allowed_scopes);
        $this->assertSame($app->id, $client->application?->id);
    }

    public function test_wizard_creates_saml_provider_with_default_mappings(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.applications.store'), [
                'name' => 'Nextcloud',
                'visibility' => 'hidden',
                'provider_mode' => 'saml',
                'entity_id' => 'https://nextcloud.example.de/saml/metadata',
                'acs_url' => 'https://nextcloud.example.de/saml/acs',
                'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            ])
            ->assertRedirect();

        $app = Application::firstWhere('name', 'Nextcloud');
        $sp = $app->provider?->samlServiceProvider;
        $this->assertNotNull($sp);
        $this->assertSame('https://nextcloud.example.de/saml/acs', $sp->acs_url);
        $this->assertGreaterThan(0, $sp->attributeMappings()->count());
    }

    public function test_provider_can_be_attached_and_detached(): void
    {
        $admin = $this->admin();
        $app = Application::create(['name' => 'App', 'slug' => 'app-x', 'visibility' => 'portal', 'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true]);
        $provider = Provider::create(['name' => 'Freier Provider', 'type' => 'oidc', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.applications.provider.attach', $app), ['provider_id' => $provider->id])
            ->assertRedirect();
        $this->assertSame($provider->id, $app->fresh()->provider_id);

        $this->actingAs($admin)
            ->delete(route('admin.applications.provider.detach', $app))
            ->assertRedirect();
        $this->assertNull($app->fresh()->provider_id);
        $this->assertNotNull($provider->fresh(), 'Provider bleibt bestehen');
    }

    public function test_provider_index_lists_both_types(): void
    {
        $oidc = Provider::create(['name' => 'OIDC A', 'type' => 'oidc', 'is_active' => true]);
        OauthClient::create(['provider_id' => $oidc->id, 'name' => 'OIDC A', 'client_id' => 'cid-a', 'access_token_lifetime' => 3600, 'refresh_token_lifetime' => 1209600, 'id_token_lifetime' => 3600, 'is_active' => true]);
        $saml = Provider::create(['name' => 'SAML B', 'type' => 'saml', 'is_active' => true]);
        SamlServiceProvider::create(['provider_id' => $saml->id, 'name' => 'SAML B', 'entity_id' => 'urn:b', 'acs_url' => 'https://b.example/acs', 'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', 'is_active' => true]);

        $this->actingAs($this->admin())->get(route('admin.providers.index'))
            ->assertOk()
            ->assertSee('OIDC A')
            ->assertSee('SAML B')
            ->assertSee('OAuth2 / OIDC')
            ->assertSee('SAML 2.0');
    }

    public function test_oidc_provider_scopes_can_be_updated_after_creation(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'oidc', 'is_active' => true]);
        $client = OauthClient::create(['provider_id' => $provider->id, 'name' => 'P', 'client_id' => 'cid', 'allowed_scopes' => ['openid', 'profile', 'email'], 'access_token_lifetime' => 3600, 'refresh_token_lifetime' => 1209600, 'id_token_lifetime' => 3600, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $provider), [
                'section' => 'claims',
                '_has_scopes' => '1',
                'scopes' => ['openid', 'profile'],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(['openid', 'profile'], $client->fresh()->allowed_scopes);
    }

    public function test_all_admin_screens_render(): void
    {
        $admin = $this->admin();

        // OIDC-Anwendung + Provider
        $oidc = Provider::create(['name' => 'OIDC', 'type' => 'oidc', 'is_active' => true]);
        $client = OauthClient::create(['provider_id' => $oidc->id, 'name' => 'OIDC', 'client_id' => 'cid', 'allowed_grant_types' => ['authorization_code'], 'allowed_scopes' => ['openid'], 'access_token_lifetime' => 3600, 'refresh_token_lifetime' => 1209600, 'id_token_lifetime' => 3600, 'is_active' => true]);
        $client->redirectUris()->create(['uri' => 'https://a.example/cb', 'type' => 'login']);
        $oidcApp = Application::create(['name' => 'OIDC App', 'slug' => 'oidc-app', 'provider_id' => $oidc->id, 'visibility' => 'portal', 'consent_required' => true, 'consent_mode' => 'first_time', 'login_mode' => 'user_choice', 'is_active' => true]);

        // SAML-Anwendung + Provider
        $saml = Provider::create(['name' => 'SAML', 'type' => 'saml', 'is_active' => true]);
        SamlServiceProvider::create(['provider_id' => $saml->id, 'name' => 'SAML', 'entity_id' => 'urn:x', 'acs_url' => 'https://x.example/acs', 'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', 'is_active' => true]);
        $samlApp = Application::create(['name' => 'SAML App', 'slug' => 'saml-app', 'provider_id' => $saml->id, 'visibility' => 'hidden', 'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true]);

        // Anwendung ohne Provider
        $bare = Application::create(['name' => 'Bare', 'slug' => 'bare', 'visibility' => 'portal', 'consent_required' => false, 'consent_mode' => 'skip', 'login_mode' => 'user_choice', 'is_active' => true]);

        $this->actingAs($admin);

        $this->get(route('admin.applications.index'))->assertOk();
        $this->get(route('admin.applications.create'))->assertOk();
        $this->get(route('admin.providers.index'))->assertOk();
        $this->get(route('admin.providers.create'))->assertOk();
        $this->get(route('admin.providers.create', ['type' => 'oidc']))->assertOk();
        $this->get(route('admin.providers.create', ['type' => 'saml']))->assertOk();

        foreach (['allgemein', 'provider', 'zugriff', 'darstellung'] as $tab) {
            $this->get(route('admin.applications.show', [$oidcApp, 'tab' => $tab]))->assertOk();
            $this->get(route('admin.applications.show', [$bare, 'tab' => $tab]))->assertOk();
        }

        foreach (['allgemein', 'protokoll', 'claims', 'sicherheit', 'token', 'erweitert'] as $tab) {
            $this->get(route('admin.providers.show', [$oidc, 'tab' => $tab]))->assertOk();
            $this->get(route('admin.providers.show', [$saml, 'tab' => $tab]))->assertOk();
        }

        $this->get(route('admin.applications.show', [$samlApp, 'tab' => 'provider']))->assertOk();
    }

    public function test_legacy_saml_route_redirects(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/saml-service-providers')
            ->assertRedirect('admin/providers');
    }
}
