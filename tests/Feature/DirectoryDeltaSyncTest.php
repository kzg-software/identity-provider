<?php

namespace Tests\Feature;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\DirectorySyncService;
use App\Directory\LdapConnectionFactory;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use LdapRecord\Testing\ConnectionFake;
use Tests\TestCase;

class DirectoryDeltaSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DirectoryEmulator::tearDown();
        Container::getInstance()->getConnectionManager()->flush();

        parent::tearDown();
    }

    private function makeDirectory(array $overrides = []): Directory
    {
        SystemSetting::set('installed', '1');

        return Directory::create(array_merge([
            'name' => 'AD',
            'type' => 'active_directory',
            'domain' => 'test.local',
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
            'delta_sync_enabled' => true,
        ], $overrides));
    }

    private function emulate(Directory $directory): ConnectionFake
    {
        $name = DirectoryConnectionResolver::connectionName($directory);
        Container::addConnection(LdapConnectionFactory::make($directory), $name);

        return DirectoryEmulator::setup($name);
    }

    private function ldapUser(string $connectionName, string $sam, int $usn, array $extra = []): LdapUser
    {
        $user = new LdapUser(array_merge([
            'cn' => ucfirst($sam),
            'samaccountname' => $sam,
            'userprincipalname' => "{$sam}@test.local",
            'mail' => "{$sam}@test.local",
            'objectguid' => (string) Str::uuid(),
            'usnchanged' => (string) $usn,
        ], $extra));
        $user->setConnection($connectionName);
        $user->save();

        return $user;
    }

    public function test_first_delta_run_without_a_cursor_falls_back_to_a_full_sync(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $this->ldapUser($name, 'alice', 4000);
        $this->ldapUser($name, 'bob', 4002);

        $result = (new DirectorySyncService)->syncDelta($directory);

        $this->assertTrue($result['ok']);
        $this->assertSame('full', $result['mode']);
        $this->assertDatabaseHas('users', ['username' => 'alice']);
        $this->assertDatabaseHas('users', ['username' => 'bob']);

        $directory->refresh();
        $this->assertSame(4002, (int) $directory->last_sync_usn);
        $this->assertNotNull($directory->last_full_sync_at);
    }

    public function test_delta_run_only_touches_objects_changed_since_the_cursor(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $alice = $this->ldapUser($name, 'alice', 5000);
        $bob = $this->ldapUser($name, 'bob', 5001);

        // Erster Lauf: voll, Cursor steht danach auf 5001.
        (new DirectorySyncService)->syncDelta($directory);
        $aliceSyncedAt = DirectoryUser::where('sam_account_name', 'alice')->value('last_synced_at');

        // Nur Bob aendert sich (Mailadresse + hoeherer uSNChanged).
        $bob->setFirstAttribute('mail', 'bob.new@test.local');
        $bob->setFirstAttribute('usnchanged', '5005');
        $bob->save();

        $result = (new DirectorySyncService)->syncDelta($directory);

        $this->assertSame('delta', $result['mode']);
        $this->assertSame(1, $result['users']);
        $this->assertDatabaseHas('users', ['username' => 'bob', 'email' => 'bob.new@test.local']);

        // Alice wurde nicht erneut angefasst.
        $this->assertEquals(
            $aliceSyncedAt,
            DirectoryUser::where('sam_account_name', 'alice')->value('last_synced_at')
        );

        $this->assertSame(5005, (int) $directory->refresh()->last_sync_usn);
    }

    public function test_a_changed_group_re_syncs_its_locally_known_members(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $alice = $this->ldapUser($name, 'alice', 6000);
        (new DirectorySyncService)->syncDelta($directory);

        // Lokale Mitgliedschaft alice -> Gruppe herstellen (wie sie ein
        // frueherer voller Lauf angelegt haette).
        $group = DirectoryGroup::create([
            'directory_id' => $directory->id,
            'object_guid' => (string) Str::uuid(),
            'name' => 'it',
            'distinguished_name' => 'CN=it,DC=test,DC=local',
        ]);
        DirectoryUser::where('sam_account_name', 'alice')->first()
            ->groups()->attach($group->id, ['synced_at' => now()]);

        // Die Gruppe aendert sich (uSNChanged steigt), Alice selbst nicht -
        // ihr Konto bekommt aber eine neue Mailadresse im Verzeichnis.
        $alice->setFirstAttribute('mail', 'alice.dept@test.local');
        $alice->save();

        $ldapGroup = new Group([
            'cn' => 'it',
            'objectguid' => $group->object_guid,
            'usnchanged' => '6010',
        ]);
        $ldapGroup->setConnection($name);
        $ldapGroup->setDn('CN=it,DC=test,DC=local');
        $ldapGroup->save();

        $result = (new DirectorySyncService)->syncDelta($directory);

        $this->assertSame('delta', $result['mode']);
        $this->assertDatabaseHas('users', ['username' => 'alice', 'email' => 'alice.dept@test.local']);
        $this->assertSame(6010, (int) $directory->refresh()->last_sync_usn);
    }

    public function test_ldap_directories_always_run_a_full_sync(): void
    {
        $directory = $this->makeDirectory(['type' => 'ldap']);

        $this->assertFalse($directory->deltaSyncEnabled());
    }
}
