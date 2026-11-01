<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthRedirectUri;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAcceptance;
use App\Workflow\WorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', '1');
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'jdoe',
            'name' => 'John Doe',
            'email' => 'jdoe@example.test',
            'auth_source' => 'local',
            'is_admin' => false,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ], $overrides));
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login.attempt'), ['username' => 'jdoe', 'password' => 'Password123!']);
    }

    /** Graph aus einer Kette: Start → … → Erlauben, Bedingungen mit „nein“ → Ablehnen. */
    private function setGraph(string $phase, array $nodes, array $edges, ?Application $application = null): Workflow
    {
        $workflow = $application
            ? Workflow::create(['phase' => $phase, 'application_id' => $application->id, 'name' => 'App', 'is_active' => true, 'graph' => [], 'version' => 1])
            : WorkflowResolver::global($phase);

        $workflow->replaceGraph(['nodes' => $nodes, 'edges' => $edges]);

        return $workflow;
    }

    private static function node(string $id, string $type, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'config' => $config, 'position' => ['x' => 0, 'y' => 0]];
    }

    private static function edge(string $from, string $output, string $to): array
    {
        return ['from' => $from, 'output' => $output, 'to' => $to];
    }

    private function createOidcApp(string $name = 'Gitea'): array
    {
        $application = Application::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'consent_required' => false,
            'consent_mode' => 'skip',
            'login_mode' => 'user_choice',
            'is_active' => true,
        ]);

        $provider = $this->linkProvider($application, 'oidc', $name);

        $client = OauthClient::create([
            'provider_id' => $provider->id,
            'name' => $name,
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'secret',
            'allowed_grant_types' => ['authorization_code'],
            'access_token_lifetime' => 3600,
            'refresh_token_lifetime' => 3600,
            'id_token_lifetime' => 3600,
            'pkce_required' => false,
            'secret_required' => true,
            'is_active' => true,
        ]);

        OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => 'https://client.example.test/callback', 'type' => 'login']);

        return [$application, $client];
    }

    private function authorizeUrl(OauthClient $client): string
    {
        return route('oauth.authorize', [
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'abc',
        ]);
    }

    // ----- Login ---------------------------------------------------

    public function test_default_login_workflow_is_created_and_logs_in(): void
    {
        $this->user();

        $this->login()->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('workflows', ['phase' => 'login', 'application_id' => null]);
    }

    public function test_login_workflow_can_deny_by_role(): void
    {
        $this->user(['manual_roles' => ['extern']]);

        $this->setGraph('login', [
            self::node('start', 'start'),
            self::node('role', 'condition.role', ['roles' => 'extern']),
            self::node('deny', 'deny', ['message' => 'Externe dürfen sich hier nicht anmelden.']),
            self::node('allow', 'allow'),
        ], [
            self::edge('start', 'weiter', 'role'),
            self::edge('role', 'ja', 'deny'),
            self::edge('role', 'nein', 'allow'),
        ]);

        $this->from(route('login'))->login()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Externe dürfen sich hier nicht anmelden.']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'workflow.denied']);
    }

    public function test_login_notice_is_shown_before_login_completes(): void
    {
        $this->user();

        $this->setGraph('login', [
            self::node('start', 'start'),
            self::node('notice', 'notice', ['title' => 'Wartungsfenster', 'text' => 'Am Samstag ist das System nicht erreichbar.']),
            self::node('allow', 'allow'),
        ], [
            self::edge('start', 'weiter', 'notice'),
            self::edge('notice', 'weiter', 'allow'),
        ]);

        $this->login()->assertRedirect(route('workflow.continue'));
        $this->assertGuest();

        $this->get(route('workflow.continue'))->assertOk()->assertSee('Wartungsfenster')->assertSee('Am Samstag');

        $this->post(route('workflow.submit'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_terms_must_be_accepted_once_per_version(): void
    {
        $user = $this->user();

        $terms = ['title' => 'Nutzungsbedingungen', 'text' => 'Bitte verantwortungsvoll nutzen.', 'document' => 'agb', 'version' => '1'];

        $this->setGraph('login', [
            self::node('start', 'start'),
            self::node('terms', 'terms', $terms),
            self::node('allow', 'allow'),
            self::node('deny', 'deny', ['message' => 'Ohne Zustimmung keine Anmeldung.']),
        ], [
            self::edge('start', 'weiter', 'terms'),
            self::edge('terms', 'akzeptiert', 'allow'),
            self::edge('terms', 'abgelehnt', 'deny'),
        ]);

        // Ohne Häkchen: Seite erneut mit Fehler
        $this->login()->assertRedirect(route('workflow.continue'));
        $this->from(route('workflow.continue'))->post(route('workflow.submit'), ['decision' => 'accept'])
            ->assertRedirect(route('workflow.continue'))
            ->assertSessionHasErrors('workflow');

        $this->post(route('workflow.submit'), ['decision' => 'accept', 'accept' => '1'])->assertRedirect(route('dashboard'));
        $this->assertTrue(WorkflowAcceptance::has($user->id, 'agb', '1'));

        // Zweite Anmeldung: keine erneute Abfrage
        $this->post(route('logout'));
        $this->login()->assertRedirect(route('dashboard'));
    }

    public function test_declining_terms_follows_the_declined_output(): void
    {
        $this->user();

        $this->setGraph('login', [
            self::node('start', 'start'),
            self::node('terms', 'terms', ['title' => 'AGB', 'text' => 'Text', 'document' => 'agb', 'version' => '1']),
            self::node('allow', 'allow'),
            self::node('deny', 'deny', ['message' => 'Ohne Zustimmung keine Anmeldung.']),
        ], [
            self::edge('start', 'weiter', 'terms'),
            self::edge('terms', 'akzeptiert', 'allow'),
            self::edge('terms', 'abgelehnt', 'deny'),
        ]);

        $this->login();
        $this->post(route('workflow.submit'), ['decision' => 'decline'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => 'Ohne Zustimmung keine Anmeldung.']);

        $this->assertGuest();
    }

    public function test_broken_workflow_fails_closed(): void
    {
        $this->user();

        $this->setGraph('login', [
            self::node('start', 'start'),
            self::node('admin', 'condition.admin'),
            self::node('allow', 'allow'),
        ], [
            self::edge('start', 'weiter', 'admin'),
            self::edge('admin', 'ja', 'allow'),
            // „nein“ ist nicht verbunden
        ]);

        $this->login()->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'workflow.error']);
    }

    public function test_flow_page_without_pending_run_redirects_to_login(): void
    {
        $this->get(route('workflow.continue'))->assertRedirect(route('login'));
    }

    // ----- Authorize ----------------------------------------------

    public function test_application_override_replaces_global_authorize_workflow(): void
    {
        [$application, $client] = $this->createOidcApp();
        [, $otherClient] = $this->createOidcApp('Wiki');
        $this->user();
        $this->login();

        $this->setGraph('authorize', [
            self::node('start', 'start'),
            self::node('deny', 'deny', ['message' => 'Gitea ist für dich gesperrt.']),
        ], [
            self::edge('start', 'weiter', 'deny'),
        ], $application);

        $this->get($this->authorizeUrl($client))->assertStatus(403)->assertSee('Gitea ist für dich gesperrt.');

        // Andere Anwendung nutzt weiter den globalen Standard
        $this->get($this->authorizeUrl($otherClient))->assertRedirectContains('https://client.example.test/callback');
    }

    public function test_authorize_notice_resumes_the_oidc_request(): void
    {
        [, $client] = $this->createOidcApp();
        $this->user();
        $this->login();

        $this->setGraph('authorize', [
            self::node('start', 'start'),
            self::node('notice', 'notice', ['title' => 'Hinweis zu Gitea', 'text' => 'Bitte keine Kundendaten ablegen.']),
            self::node('allow', 'allow'),
        ], [
            self::edge('start', 'weiter', 'notice'),
            self::edge('notice', 'weiter', 'allow'),
        ]);

        $this->get($this->authorizeUrl($client))->assertRedirect(route('workflow.continue'));
        $this->get(route('workflow.continue'))->assertOk()->assertSee('Hinweis zu Gitea')->assertSee('Weiter zu Gitea');

        $this->post(route('workflow.submit'))->assertRedirect(route('oauth.authorize.resume'));

        $location = $this->get(route('oauth.authorize.resume'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://client.example.test/callback', $location);
        $this->assertStringContainsString('code=', $location);
        $this->assertDatabaseHas('audit_logs', ['event' => 'oauth.authorize.success']);
    }

    public function test_second_factor_condition_can_require_mfa_for_an_application(): void
    {
        [$application, $client] = $this->createOidcApp();
        $this->user();
        $this->login();

        $this->setGraph('authorize', [
            self::node('start', 'start'),
            self::node('mfa', 'condition.second_factor_used'),
            self::node('allow', 'allow'),
            self::node('deny', 'deny', ['message' => 'Für Gitea ist eine Anmeldung mit zweitem Faktor nötig.']),
        ], [
            self::edge('start', 'weiter', 'mfa'),
            self::edge('mfa', 'ja', 'allow'),
            self::edge('mfa', 'nein', 'deny'),
        ], $application);

        $this->get($this->authorizeUrl($client))->assertStatus(403)->assertSee('zweitem Faktor');
        $this->assertSame(1, AuditLog::query()->where('event', 'workflow.denied')->count());
    }
}
