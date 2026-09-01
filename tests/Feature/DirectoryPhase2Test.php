<?php

namespace Tests\Feature;

use App\Directory\DirectoryTestService;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\GroupRoleMapping;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectoryPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Registrierte LDAP-Verbindungen nicht in den nächsten Test durchreichen.
        \LdapRecord\Container::getInstance()->getConnectionManager()->flush();

        parent::tearDown();
    }

    private function admin(): User
    {
        SystemSetting::set('installed', '1');

        return User::factory()->create([
            'username' => 'admin',
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    public function test_windows_sso_middleware_does_nothing_without_remote_user(): void
    {
        SystemSetting::set('installed', '1');

        // Ohne REMOTE_USER-Server-Variable bleibt der Benutzer Gast, kein Fake-Login.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_switch_off_automatic_windows_login(): void
    {
        $admin = $this->admin();

        // Standard: an. Die Anmeldeseite versucht die automatische Anmeldung.
        $this->assertTrue(SystemSetting::windowsSsoEnabled());
        $this->get(route('login'))->assertSee('auth/negotiate', false);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'system_name' => 'Auth',
            'base_url' => 'https://auth.example.com',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => 120,
            // windows_sso_enabled nicht mitgeschickt -> aus (wie eine leere Checkbox)
        ])->assertSessionHasNoErrors();

        $this->assertFalse(SystemSetting::windowsSsoEnabled());

        // Als Gast zeigt die Anmeldeseite jetzt keinen Auto-Login-Versuch mehr.
        auth()->logout();
        $this->get(route('login'))->assertDontSee('auth/negotiate', false);
    }

    public function test_admin_can_delete_a_directory_user(): void
    {
        $admin = $this->admin();
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);

        $adUser = User::factory()->create([
            'username' => 'aduser',
            'auth_source' => 'active_directory',
            'is_active' => true,
            'directory_id' => $directory->id,
        ]);
        \App\Models\DirectoryUser::create([
            'directory_id' => $directory->id,
            'user_id' => $adUser->id,
            'object_guid' => (string) \Illuminate\Support\Str::uuid(),
            'sam_account_name' => 'aduser',
            'distinguished_name' => 'CN=aduser,DC=test,DC=local',
        ]);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $adUser))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $adUser->id]);
        $this->assertDatabaseMissing('directory_users', ['sam_account_name' => 'aduser']);
    }

    public function test_admin_cannot_delete_their_own_account_or_the_last_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_dn_fields_are_trimmed_on_save(): void
    {
        $directory = Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'base_dn' => "  DC=ad,DC=firma,DC=de\r\n",
            'user_dn' => "OU=Benutzer,DC=ad,DC=firma,DC=de\n",
            'group_dn' => '   ',
            'bind_user' => "  svc-ldap@firma.de  ",
        ]);

        $this->assertSame('DC=ad,DC=firma,DC=de', $directory->fresh()->base_dn);
        $this->assertSame('OU=Benutzer,DC=ad,DC=firma,DC=de', $directory->fresh()->user_dn);
        $this->assertNull($directory->fresh()->group_dn);
        $this->assertSame('svc-ldap@firma.de', $directory->fresh()->bind_user);

        // Group-Suche fällt ohne group_dn auf den Base DN zurück.
        $this->assertSame('DC=ad,DC=firma,DC=de', $directory->fresh()->groupSearchDn());
    }

    public function test_raw_query_without_base_dn_gives_a_clear_message_not_invalid_dn(): void
    {
        $directory = Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'ldap_server' => '127.0.0.1',
            'ldap_port' => 1,
            'base_dn' => null,
        ]);

        $result = (new \App\Directory\DirectoryTestService)->rawQuery(
            $directory,
            '(&(objectClass=user)(memberOf=CN=IDP-Login,OU=Gruppen,DC=ad,DC=firma,DC=de))'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsStringIgnoringCase('invalid dn syntax', $result['message']);
    }

    public function test_bind_password_is_stored_encrypted(): void
    {
        $directory = Directory::create([
            'name' => 'Test-AD',
            'type' => 'active_directory',
            'bind_user' => 'svc-bind',
            'bind_password_encrypted' => 'PlaintextSecret123',
            'base_dn' => 'DC=test,DC=local',
        ]);

        $raw = DB::table('directories')->where('id', $directory->id)->value('bind_password_encrypted');

        $this->assertNotSame('PlaintextSecret123', $raw);
        $this->assertSame('PlaintextSecret123', $directory->fresh()->bind_password_encrypted);
    }

    public function test_unreachable_directory_fails_gracefully_without_crashing(): void
    {
        $directory = Directory::create([
            'name' => 'Unreachable',
            'type' => 'active_directory',
            'ldap_server' => '127.0.0.1',
            'ldap_port' => 1, // kein LDAP-Dienst dort
            'base_dn' => 'DC=test,DC=local',
        ]);

        $result = (new DirectoryTestService)->testConnection($directory);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_local_admin_login_still_works_when_directory_unreachable(): void
    {
        Directory::create([
            'name' => 'Unreachable',
            'type' => 'active_directory',
            'ldap_server' => '127.0.0.1',
            'ldap_port' => 1,
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.directories.index'))->assertOk();

        // Der "Verbindung testen"-Aufruf gegen den nicht erreichbaren Server darf
        // nicht die Anwendung zum Absturz bringen.
        $directory = Directory::first();
        $this->actingAs($admin)
            ->post(route('admin.directories.test-connection', $directory))
            ->assertRedirect();
    }

    public function test_directory_create_and_edit_forms_render_with_field_help(): void
    {
        $admin = $this->admin();
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);

        $this->actingAs($admin)->get(route('admin.directories.create'))
            ->assertOk()
            ->assertSee('Erklärung zu diesem Feld', false)
            ->assertSee('Für die Verbindung nötig');

        $this->actingAs($admin)->get(route('admin.directories.edit', $directory))
            ->assertOk()
            ->assertSee('Erklärung zu diesem Feld', false);
    }

    public function test_group_role_mapping_can_be_created_and_deleted(): void
    {
        $admin = $this->admin();

        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);
        $group = DirectoryGroup::create([
            'directory_id' => $directory->id,
            'object_guid' => 'guid-1',
            'name' => 'IT-Admins',
            'distinguished_name' => 'CN=IT-Admins,DC=test,DC=local',
        ]);

        // Exakter Name einer bekannten Gruppe -> wird direkt verknüpft.
        $this->actingAs($admin)->post(route('admin.group-role-mappings.store'), [
            'group' => 'it-admins',
            'role' => 'admin',
            'claims' => '{"roles":["admin"]}',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('group_role_mappings', [
            'directory_group_id' => $group->id,
            'group_name' => null,
            'role' => 'admin',
        ]);

        $mapping = GroupRoleMapping::first();

        $this->actingAs($admin)
            ->delete(route('admin.group-role-mappings.destroy', $mapping))
            ->assertRedirect();

        $this->assertDatabaseMissing('group_role_mappings', ['id' => $mapping->id]);
    }

    public function test_group_role_mapping_accepts_a_free_text_group_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.group-role-mappings.store'), [
            'group' => 'GG_Nicht_Synchronisiert',
            'role' => 'reviewer',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('group_role_mappings', [
            'directory_group_id' => null,
            'group_name' => 'GG_Nicht_Synchronisiert',
            'role' => 'reviewer',
        ]);
    }

    public function test_roles_resolve_from_both_linked_and_free_text_group_mappings(): void
    {
        $this->admin();
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);

        $linked = DirectoryGroup::create([
            'directory_id' => $directory->id, 'object_guid' => 'g-linked',
            'name' => 'GG_App_Admins', 'distinguished_name' => 'CN=GG_App_Admins,DC=test,DC=local',
        ]);
        $byName = DirectoryGroup::create([
            'directory_id' => $directory->id, 'object_guid' => 'g-name',
            'name' => 'GG_Reviewer', 'distinguished_name' => 'CN=GG_Reviewer,DC=test,DC=local',
        ]);

        $other = Directory::create(['name' => 'AD2', 'type' => 'active_directory', 'base_dn' => 'DC=other,DC=local']);

        GroupRoleMapping::create(['directory_group_id' => $linked->id, 'role' => 'admin']);
        GroupRoleMapping::create(['group_name' => 'gg_reviewer', 'directory_id' => null, 'role' => 'reviewer']);
        // an ein anderes Verzeichnis gebunden -> darf hier NICHT greifen
        GroupRoleMapping::create(['group_name' => 'gg_reviewer', 'directory_id' => $other->id, 'role' => 'nope']);

        $roles = (new \App\Directory\DirectorySyncService)->resolveRoles($directory, [$linked->id, $byName->id]);

        sort($roles);
        $this->assertSame(['admin', 'reviewer'], $roles);
    }

    public function test_non_admin_cannot_manage_directories(): void
    {
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'auth_source' => 'active_directory',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('admin.directories.index'))->assertForbidden();
    }
}
