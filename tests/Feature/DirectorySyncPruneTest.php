<?php

namespace Tests\Feature;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\DirectorySyncService;
use App\Directory\LdapConnectionFactory;
use App\Models\Directory;
use App\Models\DirectoryUser;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Tests\TestCase;

class DirectorySyncPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DirectoryEmulator::tearDown();
        Container::getInstance()->getConnectionManager()->flush();

        parent::tearDown();
    }

    private function makeDirectory(string $staleMode): Directory
    {
        SystemSetting::set('installed', '1');

        return Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'domain' => 'test.local',
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
            'stale_user_handling' => $staleMode,
        ]);
    }

    private function emulate(Directory $directory): \LdapRecord\Testing\ConnectionFake
    {
        $name = DirectoryConnectionResolver::connectionName($directory);
        Container::addConnection(LdapConnectionFactory::make($directory), $name);

        return DirectoryEmulator::setup($name);
    }

    private function ldapUser(string $connectionName, string $sam): LdapUser
    {
        $user = new LdapUser([
            'cn' => ucfirst($sam),
            'samaccountname' => $sam,
            'userprincipalname' => "{$sam}@test.local",
            'mail' => "{$sam}@test.local",
            'objectguid' => (string) Str::uuid(),
        ]);
        $user->setConnection($connectionName);
        $user->save();

        return $user;
    }

    public function test_sync_deletes_users_that_are_no_longer_in_the_directory(): void
    {
        $directory = $this->makeDirectory('delete');
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $keep = $this->ldapUser($name, 'keepme');
        $drop = $this->ldapUser($name, 'dropme');

        (new DirectorySyncService)->syncAll($directory);

        $this->assertDatabaseHas('users', ['username' => 'keepme']);
        $this->assertDatabaseHas('users', ['username' => 'dropme']);

        // Benutzer verlässt den Suchbereich.
        $drop->delete();

        $result = (new DirectorySyncService)->syncAll($directory);

        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseHas('users', ['username' => 'keepme']);
        $this->assertDatabaseMissing('users', ['username' => 'dropme']);
        $this->assertDatabaseMissing('directory_users', ['sam_account_name' => 'dropme']);
    }

    public function test_sync_disable_mode_only_deactivates(): void
    {
        $directory = $this->makeDirectory('disable');
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $this->ldapUser($name, 'keepme');
        $drop = $this->ldapUser($name, 'dropme');

        (new DirectorySyncService)->syncAll($directory);
        $drop->delete();
        (new DirectorySyncService)->syncAll($directory);

        $this->assertDatabaseHas('users', ['username' => 'dropme', 'is_active' => false]);
    }

    public function test_sync_keeps_everyone_by_default(): void
    {
        $directory = $this->makeDirectory('keep');
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $this->ldapUser($name, 'keepme');
        $drop = $this->ldapUser($name, 'dropme');

        (new DirectorySyncService)->syncAll($directory);
        $drop->delete();
        $result = (new DirectorySyncService)->syncAll($directory);

        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('users', ['username' => 'dropme']);
    }

    public function test_empty_sync_result_never_deletes_anything(): void
    {
        $directory = $this->makeDirectory('delete');
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $u = $this->ldapUser($name, 'someone');
        (new DirectorySyncService)->syncAll($directory);
        $this->assertDatabaseHas('users', ['username' => 'someone']);

        // Alle Benutzer verschwinden gleichzeitig (z.B. falsche User DN).
        $u->delete();
        $result = (new DirectorySyncService)->syncAll($directory);

        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('users', ['username' => 'someone']);
    }

    public function test_local_admin_is_never_removed_by_sync(): void
    {
        $directory = $this->makeDirectory('delete');
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $this->ldapUser($name, 'realuser');
        (new DirectorySyncService)->syncAll($directory);

        // Ein lokaler Admin, der (fälschlich) an das Verzeichnis geknüpft ist.
        $admin = User::factory()->create([
            'username' => 'break-glass',
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
            'directory_id' => $directory->id,
        ]);
        DirectoryUser::create([
            'directory_id' => $directory->id,
            'user_id' => $admin->id,
            'object_guid' => (string) Str::uuid(),
            'sam_account_name' => 'break-glass',
            'distinguished_name' => 'CN=break-glass,DC=test,DC=local',
        ]);

        (new DirectorySyncService)->syncAll($directory);

        $this->assertDatabaseHas('users', ['username' => 'break-glass', 'is_active' => true]);
    }
}
