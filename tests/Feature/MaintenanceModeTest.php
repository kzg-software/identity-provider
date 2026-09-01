<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\MaintenanceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function user(bool $admin = false, string $username = 'jdoe'): User
    {
        return User::factory()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'auth_source' => $admin ? 'local' : 'active_directory',
            'is_admin' => $admin,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->user(true, 'admin');
    }

    // ---- Systemweiter Wartungsmodus ----------------------------------------

    public function test_system_maintenance_blocks_normal_users_with_503(): void
    {
        SystemSetting::set('maintenance_mode', '1');
        SystemSetting::set('maintenance_message', 'Kurze Pause.');

        $this->actingAs($this->user())
            ->get(route('dashboard'))
            ->assertStatus(503)
            ->assertSee('Kurze Pause.');
    }

    public function test_local_admins_always_bypass_system_maintenance(): void
    {
        SystemSetting::set('maintenance_mode', '1');

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk();
    }

    public function test_login_page_stays_reachable_during_system_maintenance(): void
    {
        SystemSetting::set('maintenance_mode', '1');

        $this->get(route('login'))->assertOk();
    }

    public function test_allowlisted_username_bypasses_system_maintenance(): void
    {
        SystemSetting::set('maintenance_mode', '1');
        SystemSetting::set('maintenance_allow', "someoneelse\njdoe");

        $this->actingAs($this->user())->get(route('dashboard'))->assertOk();
    }

    public function test_allowlisted_group_bypasses_system_maintenance(): void
    {
        SystemSetting::set('maintenance_mode', '1');
        SystemSetting::set('maintenance_allow', '@IT-Abteilung');

        $user = $this->user();
        $this->attachGroup($user, 'IT-Abteilung');

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_settings_form_persists_maintenance_fields(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'system_name' => 'Auth',
            'base_url' => 'https://auth.example.test',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => '120',
            'maintenance_mode' => '1',
            'maintenance_message' => 'Wartung bis 18 Uhr.',
            'maintenance_allow' => "mmustermann\n@IT",
        ])->assertSessionHasNoErrors();

        $this->assertSame('1', SystemSetting::get('maintenance_mode'));
        $this->assertSame('Wartung bis 18 Uhr.', SystemSetting::get('maintenance_message'));
        $this->assertTrue(MaintenanceGate::systemActive());
    }

    // ---- Wartungsmodus pro Anwendung -------------------------------------

    public function test_application_maintenance_gate_respects_bypass(): void
    {
        $app = Application::create([
            'name' => 'CRM', 'slug' => 'crm-abc123', 'login_mode' => 'user_choice',
            'consent_mode' => 'first_time', 'is_active' => true,
            'maintenance_mode' => true, 'maintenance_allow' => "keyuser",
        ]);

        $normal = $this->user(username: 'normalo');
        $keyUser = $this->user(username: 'keyuser');

        $this->assertTrue($app->isUnderMaintenanceFor($normal));
        $this->assertFalse($app->isUnderMaintenanceFor($keyUser));
        $this->assertFalse($app->isUnderMaintenanceFor($this->admin()));
    }

    public function test_application_maintenance_shows_on_user_dashboard(): void
    {
        Application::create([
            'name' => 'CRM', 'slug' => 'crm-abc123', 'login_mode' => 'user_choice',
            'consent_mode' => 'first_time', 'is_active' => true, 'launch_url' => 'https://crm.example.test',
            'maintenance_mode' => true, 'maintenance_message' => 'CRM-Update läuft.',
        ]);

        $this->actingAs($this->user())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('In Wartung')
            ->assertSee('CRM-Update läuft.')
            ->assertDontSee('>Öffnen<', false);
    }

    private function attachGroup(User $user, string $groupName): void
    {
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);

        $directoryUser = DirectoryUser::create([
            'directory_id' => $directory->id,
            'user_id' => $user->id,
            'object_guid' => 'guid-'.$user->id,
            'sam_account_name' => $user->username,
            'distinguished_name' => 'CN='.$user->username.',DC=test,DC=local',
        ]);

        $group = DirectoryGroup::create([
            'directory_id' => $directory->id,
            'object_guid' => 'gguid-'.$groupName,
            'name' => $groupName,
            'distinguished_name' => 'CN='.$groupName.',DC=test,DC=local',
        ]);

        $directoryUser->groups()->attach($group->id);
    }
}
