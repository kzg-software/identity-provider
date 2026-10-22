<?php

namespace Tests\Feature;

use App\Directory\DirectoryHealth;
use App\Models\Directory;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryHealthTest extends TestCase
{
    use RefreshDatabase;

    private function directory(array $state = []): Directory
    {
        $directory = Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'domain' => 'test.local',
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
        ]);
        $directory->forceFill($state)->save();

        return $directory;
    }

    public function test_inactive_directory_is_gray(): void
    {
        $health = DirectoryHealth::for($this->directory(['is_active' => false]));

        $this->assertSame('gray', $health['color']);
    }

    public function test_never_synced_directory_is_amber(): void
    {
        $health = DirectoryHealth::for($this->directory());

        $this->assertSame('amber', $health['color']);
        $this->assertSame('Noch nicht synchronisiert', $health['title']);
    }

    public function test_sync_error_is_red_and_explains_the_cause(): void
    {
        $health = DirectoryHealth::for($this->directory([
            'last_sync_at' => now()->subMinutes(5),
            'last_sync_error' => "ldap_bind(): Unable to bind to server: Can't contact LDAP server",
        ]));

        $this->assertSame('red', $health['color']);
        $this->assertStringContainsString('Der Server ist nicht erreichbar.', $health['reason']);
        $this->assertStringContainsString("Can't contact LDAP server", $health['reason']);
    }

    public function test_overdue_sync_is_amber(): void
    {
        $health = DirectoryHealth::for($this->directory(['last_sync_at' => now()->subDays(2)]));

        $this->assertSame('amber', $health['color']);
        $this->assertSame('Synchronisierung überfällig', $health['title']);
    }

    public function test_overdue_threshold_is_shorter_with_delta_sync(): void
    {
        $health = DirectoryHealth::for($this->directory([
            'delta_sync_enabled' => true,
            'last_sync_at' => now()->subHours(2),
        ]));

        $this->assertSame('amber', $health['color']);
    }

    public function test_recent_successful_sync_is_green(): void
    {
        $health = DirectoryHealth::for($this->directory([
            'last_sync_at' => now()->subHours(3),
            'last_sync_user_count' => 42,
            'last_sync_group_count' => 7,
        ]));

        $this->assertSame('green', $health['color']);
        $this->assertStringContainsString('42 Benutzer, 7 Gruppen', $health['reason']);
    }

    public function test_directory_list_shows_status_dot_with_reason(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);
        $this->directory([
            'last_sync_at' => now()->subMinutes(5),
            'last_sync_error' => 'Invalid credentials',
        ]);

        $this->actingAs($admin)->get(route('admin.directories.index'))
            ->assertOk()
            ->assertSee('Fehler bei der Synchronisierung')
            ->assertSee('Bind-Benutzer und Passwort prüfen.');
    }
}
