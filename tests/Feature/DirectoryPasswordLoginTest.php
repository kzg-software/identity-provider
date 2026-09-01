<?php

namespace Tests\Feature;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\LdapConnectionFactory;
use App\Models\Directory;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Tests\TestCase;

class DirectoryPasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DirectoryEmulator::tearDown();

        parent::tearDown();
    }

    /**
     * DirectoryEmulator::setup($name) requires a connection to already be
     * registered under that name (it swaps it out for a fake), so register
     * one via the same factory the app uses before faking it.
     */
    private function emulate(Directory $directory): \LdapRecord\Testing\ConnectionFake
    {
        $name = DirectoryConnectionResolver::connectionName($directory);

        Container::addConnection(LdapConnectionFactory::make($directory), $name);

        return DirectoryEmulator::setup($name);
    }

    private function createLdapUser(string $connectionName, array $attributes): LdapUser
    {
        $user = new LdapUser($attributes);
        $user->setConnection($connectionName);
        $user->save();

        return $user;
    }

    private function makeDirectory(): Directory
    {
        SystemSetting::set('installed', '1');

        return Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'domain' => 'test.local',
            'upn_suffix' => 'test.local',
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
            'priority' => 10,
        ]);
    }

    public function test_ad_user_can_log_in_with_username_and_password(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $fake = $this->emulate($directory);

        $ldapUser = $this->createLdapUser($name, [
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'givenname' => 'Jane',
            'sn' => 'Doe',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);

        // The end-user login binds with the user's UPN, not the DN, so allow
        // the fake LDAP server to accept a bind with that identifier (a single
        // matching expectation, since LdapFake asserts expectations in order).
        $fake->getLdapConnection()->shouldAllowBindWith('jdoe@test.local');

        $response = $this->post(route('login.directory'), [
            'ad_username' => 'jdoe',
            'ad_password' => 'correct-horse-battery-staple',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'sam_account_name' => 'jdoe',
            'auth_source' => 'active_directory',
        ]);
    }

    public function test_ad_login_with_wrong_password_is_rejected(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory)->shouldBeConnected();

        $this->createLdapUser($name, [
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);

        // Kein actingAs() -> jeder Bind-Versuch mit Endnutzer-Zugangsdaten schlägt fehl.
        $response = $this->from(route('login'))->post(route('login.directory'), [
            'ad_username' => 'jdoe',
            'ad_password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('ad_username');
        $this->assertGuest();
    }

    public function test_disabled_ad_user_cannot_log_in(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $fake = $this->emulate($directory);

        $ldapUser = $this->createLdapUser($name, [
            'cn' => 'Locked Out',
            'samaccountname' => 'locked',
            'userprincipalname' => 'locked@test.local',
            'mail' => 'locked@test.local',
            'objectguid' => (string) Str::uuid(),
            'useraccountcontrol' => 2, // ACCOUNTDISABLE
        ]);

        $fake->getLdapConnection()->shouldAllowBindWith('locked@test.local');

        $response = $this->from(route('login'))->post(route('login.directory'), [
            'ad_username' => 'locked',
            'ad_password' => 'whatever',
        ]);

        $response->assertSessionHasErrors('ad_username');
        $this->assertGuest();

        $user = User::where('sam_account_name', 'locked')->first();
        $this->assertNotNull($user);
        $this->assertFalse((bool) $user->is_active);
    }

    public function test_windows_sso_middleware_logs_in_via_php_auth_user(): void
    {
        // IIS mit aktivierter Windows Authentication reicht den bereits per
        // Kerberos/NTLM validierten Benutzer teils als PHP_AUTH_USER statt
        // REMOTE_USER durch (FastCGI-abhängig) - die Middleware muss beides.
        $directory = $this->makeDirectory();
        $directory->forceFill(['netbios_domain' => 'RL'])->save();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $this->createLdapUser($name, [
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'givenname' => 'Jane',
            'sn' => 'Doe',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);

        $response = $this->withServerVariables(['PHP_AUTH_USER' => 'RL\\jdoe'])
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'sam_account_name' => 'jdoe',
            'auth_source' => 'active_directory',
        ]);
    }
}
