<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_uninstalled_system_redirects_to_installer(): void
    {
        $this->get('/')->assertRedirect(route('install.index'));
    }

    public function test_full_installer_wizard_completes_and_creates_admin(): void
    {
        $this->post(route('install.database.store'), [
            'connection' => 'sqlite',
            'database' => 'database.sqlite',
        ])->assertRedirect(route('install.system'));

        $this->post(route('install.system.store'), [
            'system_name' => 'Auth Test',
            'base_url' => 'http://127.0.0.1:8140',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => 120,
        ])->assertRedirect(route('install.admin'));

        $this->post(route('install.admin.store'), [
            'username' => 'jkinzig',
            'first_name' => 'Jimmy',
            'last_name' => 'Kinzig',
            'email' => 'jimmy@kinzig.de',
            'password' => 'SuperSecret123!',
            'password_confirmation' => 'SuperSecret123!',
        ])->assertRedirect(route('install.directory'));

        $this->assertDatabaseHas('users', [
            'username' => 'jkinzig',
            'auth_source' => 'local',
            'is_admin' => true,
        ]);

        $this->post(route('install.directory.store'), ['skip' => 1])
            ->assertRedirect(route('install.windows-sso'));

        $this->post(route('install.windows-sso.store'), [])
            ->assertRedirect(route('install.finish'));

        $this->post(route('install.complete'))
            ->assertRedirect(route('login'));

        $this->assertTrue(SystemSetting::isInstalled());

        // Installer ist danach gesperrt.
        $this->get(route('install.index'))->assertRedirect(route('login'));
    }

    public function test_database_connection_test_keeps_the_entered_values(): void
    {
        $response = $this->from(route('install.database'))
            ->followingRedirects()
            ->post(route('install.database.test'), [
                'connection' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3307',
                'database' => 'auth_prod',
                'username' => 'auth_rw',
                'password' => 'Geheim!123',
            ]);

        $response->assertOk();
        $response->assertSee('value="3307"', false);
        $response->assertSee('value="auth_prod"', false);
        $response->assertSee('value="auth_rw"', false);
        $response->assertSee('value="Geheim!123"', false);
        $response->assertSee('value="mysql" selected', false);
    }

    public function test_directory_connection_test_keeps_the_entered_values(): void
    {
        $response = $this->from(route('install.directory'))
            ->followingRedirects()
            ->post(route('install.directory.test'), [
                'name' => 'AD Zentrale',
                'domain' => 'intern.example',
                'ldap_server' => '127.0.0.1',
                'ldap_port' => '389',
                'use_ldaps' => '1',
                'base_dn' => 'DC=intern,DC=example',
                'bind_user' => 'svc-ldap',
                'bind_password' => 'BindPw!42',
            ]);

        $response->assertOk();
        $response->assertSee('value="intern.example"', false);
        $response->assertSee('value="DC=intern,DC=example"', false);
        $response->assertSee('checked', false);
        $response->assertSee('value="svc-ldap"', false);
        $response->assertSee('value="BindPw!42"', false);
    }

    public function test_finish_is_rejected_without_local_admin(): void
    {
        $this->post(route('install.complete'))->assertRedirect(route('install.admin'));
        $this->assertFalse(SystemSetting::isInstalled());
    }

    public function test_local_admin_can_login_and_view_dashboard(): void
    {
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'username' => 'admin1',
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);

        $this->post(route('login.attempt'), [
            'username' => 'admin1',
            'password' => 'Password123!',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Übersicht');
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard-user');
        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.settings.edit'))->assertOk();
    }

    public function test_invalid_login_is_rejected(): void
    {
        SystemSetting::set('installed', '1');

        User::factory()->create([
            'username' => 'admin2',
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);

        $this->post(route('login.attempt'), [
            'username' => 'admin2',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_non_admin_cannot_access_admin_area(): void
    {
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'username' => 'ad_user',
            'auth_source' => 'active_directory',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    }
}
