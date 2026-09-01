<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OidcKey;
use App\Models\SamlCertificate;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', '1');
    }

    public function test_admin_sees_the_system_stats_dashboard(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewIs('admin.dashboard');
        $response->assertSee('Benutzer insgesamt');
        $response->assertSee('Aktive Benutzer');
        $response->assertSee('Schnellzugriffe');
    }

    public function test_admin_dashboard_route_shows_the_personal_portal(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard-user');
    }

    public function test_normal_user_only_sees_applications_they_are_allowed_to_access(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_admin' => false, 'is_active' => true]);

        $allowed = Application::create([
            'name' => 'Freigegebene App',
            'slug' => 'freigegebene-app',
            'launch_url' => 'https://allowed.example.de',
            'is_active' => true,
        ]);

        $restricted = Application::create([
            'name' => 'Gesperrte App',
            'slug' => 'gesperrte-app',
            'launch_url' => 'https://restricted.example.de',
            'is_active' => true,
        ]);

        AccessPolicy::create([
            'application_id' => $restricted->id,
            'effect' => 'allow',
            'subject_type' => 'user',
            'subject_value' => 'jemand-anders',
            'priority' => 0,
        ]);

        $inactive = Application::create([
            'name' => 'Deaktivierte App',
            'slug' => 'deaktivierte-app',
            'launch_url' => 'https://inactive.example.de',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard-user');
        $response->assertSee('Freigegebene App');
        $response->assertDontSee('Gesperrte App');
        $response->assertDontSee('Deaktivierte App');
        $response->assertDontSee('Benutzer insgesamt');
    }

    public function test_normal_user_sees_app_restricted_to_their_group(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_admin' => false, 'is_active' => true, 'username' => 'jdoe']);

        $app = Application::create([
            'name' => 'Nur für jdoe',
            'slug' => 'nur-fuer-jdoe',
            'is_active' => true,
        ]);

        AccessPolicy::create([
            'application_id' => $app->id,
            'effect' => 'allow',
            'subject_type' => 'user',
            'subject_value' => 'jdoe',
            'priority' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Nur für jdoe');
    }

    public function test_applications_are_grouped_into_dynamic_categories_the_user_may_access(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_admin' => false, 'is_active' => true]);

        Application::create([
            'name' => 'App A',
            'slug' => 'app-a',
            'category' => 'Allgemein',
            'is_active' => true,
        ]);

        Application::create([
            'name' => 'App B',
            'slug' => 'app-b',
            'category' => 'Allgemein',
            'is_active' => true,
        ]);

        Application::create([
            'name' => 'App C',
            'slug' => 'app-c',
            'category' => null,
            'is_active' => true,
        ]);

        // A category the user has NO access to at all must not appear.
        $restrictedCategory = Application::create([
            'name' => 'App D',
            'slug' => 'app-d',
            'category' => 'Finanzen',
            'is_active' => true,
        ]);
        AccessPolicy::create([
            'application_id' => $restrictedCategory->id,
            'effect' => 'allow',
            'subject_type' => 'user',
            'subject_value' => 'jemand-anders',
            'priority' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSeeInOrder(['Allgemein', 'App A', 'App B']);
        $response->assertSee('Weitere Anwendungen');
        $response->assertSee('App C');
        $response->assertDontSee('Finanzen');
        $response->assertDontSee('App D');
    }

    public function test_expiring_saml_certificate_shows_up_as_a_dashboard_warning(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        SamlCertificate::create([
            'name' => 'Signing',
            'type' => 'signing',
            'certificate' => 'dummy',
            'private_key_encrypted' => 'dummy',
            'fingerprint' => 'AA:BB',
            'algorithm' => 'RSA-SHA256',
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addDays(10),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Handlungsbedarf');
        $response->assertSee('SAML-Zertifikat läuft bald ab');
    }

    public function test_no_warnings_shown_when_everything_is_healthy(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        SamlCertificate::create([
            'name' => 'Signing',
            'type' => 'signing',
            'certificate' => 'dummy',
            'private_key_encrypted' => 'dummy',
            'fingerprint' => 'AA:BB',
            'algorithm' => 'RSA-SHA256',
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addYears(2),
            'is_active' => true,
        ]);

        OidcKey::create([
            'kid' => 'test-kid',
            'algorithm' => 'RS256',
            'public_key' => 'dummy',
            'private_key_encrypted' => 'dummy',
            'is_active' => true,
            'rotated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Handlungsbedarf');
    }

    public function test_failed_logins_within_24_hours_are_counted_on_the_dashboard(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        (new AuditLog)->forceFill(['event' => 'login.failed', 'created_at' => now()->subHours(2)])->save();
        (new AuditLog)->forceFill(['event' => 'login.failed', 'created_at' => now()->subHours(10)])->save();
        (new AuditLog)->forceFill(['event' => 'login.failed', 'created_at' => now()->subDays(3)])->save();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Fehlgeschlagene Logins (24h)');
        $response->assertSee('>2<', false);
    }

    public function test_category_section_appears_already_with_a_single_application(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_admin' => false, 'is_active' => true]);

        Application::create([
            'name' => 'Solo App',
            'slug' => 'solo-app',
            'category' => 'Allgemein',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Allgemein');
        $response->assertSee('Solo App');
    }
}
