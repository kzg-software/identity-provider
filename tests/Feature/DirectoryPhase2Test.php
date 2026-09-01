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

        $this->actingAs($admin)->post(route('admin.group-role-mappings.store'), [
            'directory_group_id' => $group->id,
            'role' => 'admin',
            'claims' => '{"roles":["admin"]}',
        ])->assertRedirect();

        $this->assertDatabaseHas('group_role_mappings', [
            'directory_group_id' => $group->id,
            'role' => 'admin',
        ]);

        $mapping = GroupRoleMapping::first();

        $this->actingAs($admin)
            ->delete(route('admin.group-role-mappings.destroy', $mapping))
            ->assertRedirect();

        $this->assertDatabaseMissing('group_role_mappings', ['id' => $mapping->id]);
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
