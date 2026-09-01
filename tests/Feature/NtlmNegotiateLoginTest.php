<?php

namespace Tests\Feature;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\LdapConnectionFactory;
use App\Models\Directory;
use App\Models\SystemSetting;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Tests\TestCase;

class NtlmNegotiateLoginTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function tearDown(): void
    {
        DirectoryEmulator::tearDown();

        parent::tearDown();
    }

    private function emulate(Directory $directory): \LdapRecord\Testing\ConnectionFake
    {
        $name = DirectoryConnectionResolver::connectionName($directory);

        Container::addConnection(LdapConnectionFactory::make($directory), $name);

        return DirectoryEmulator::setup($name);
    }

    private function makeDirectory(): Directory
    {
        SystemSetting::set('installed', '1');

        return Directory::create([
            'name' => 'AD',
            'type' => 'active_directory',
            'domain' => 'test.local',
            'netbios_domain' => 'RL',
            'upn_suffix' => 'test.local',
            'base_dn' => 'DC=test,DC=local',
            'is_active' => true,
            'priority' => 10,
        ]);
    }

    private function buildType1(): string
    {
        return base64_encode("NTLMSSP\x00".pack('V', 1).str_repeat("\x00", 24));
    }

    private function buildType3(string $username, string $domain): string
    {
        $domainBytes = mb_convert_encoding($domain, 'UTF-16LE', 'UTF-8');
        $userBytes = mb_convert_encoding($username, 'UTF-16LE', 'UTF-8');

        $domainOffset = 64;
        $userOffset = $domainOffset + strlen($domainBytes);

        $buf = str_repeat("\x00", $userOffset + strlen($userBytes));
        $buf = substr_replace($buf, "NTLMSSP\x00", 0, 8);
        $buf = substr_replace($buf, pack('V', 3), 8, 4);
        $buf = substr_replace($buf, pack('v', strlen($domainBytes)), 28, 2);
        $buf = substr_replace($buf, pack('v', strlen($domainBytes)), 30, 2);
        $buf = substr_replace($buf, pack('V', $domainOffset), 32, 4);
        $buf = substr_replace($buf, pack('v', strlen($userBytes)), 36, 2);
        $buf = substr_replace($buf, pack('v', strlen($userBytes)), 38, 2);
        $buf = substr_replace($buf, pack('V', $userOffset), 40, 4);
        $buf = substr_replace($buf, $domainBytes, $domainOffset, strlen($domainBytes));
        $buf = substr_replace($buf, $userBytes, $userOffset, strlen($userBytes));

        return base64_encode($buf);
    }

    public function test_missing_auth_header_returns_ntlm_challenge(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->get(route('auth.negotiate'));

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate', 'NTLM');
    }

    public function test_type1_message_returns_type2_challenge(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->get(route('auth.negotiate'), [
            'Authorization' => 'NTLM '.$this->buildType1(),
        ]);

        $response->assertStatus(401);
        $this->assertStringStartsWith('NTLM ', $response->headers->get('WWW-Authenticate'));
    }

    public function test_valid_type3_message_logs_in_known_ad_user(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $user = new LdapUser([
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'givenname' => 'Jane',
            'sn' => 'Doe',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);
        $user->setConnection($name);
        $user->save();

        $response = $this->get(route('auth.negotiate'), [
            'Authorization' => 'NTLM '.$this->buildType3('jdoe', 'RL'),
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'sam_account_name' => 'jdoe',
            'auth_source' => 'active_directory',
            'last_login_method' => 'windows_sso_ntlm',
        ]);
    }

    public function test_ntlm_auto_login_returns_to_the_originally_intended_url_not_the_dashboard(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $user = new LdapUser([
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);
        $user->setConnection($name);
        $user->save();

        // Simulate what AuthorizationController::authorize() does when a
        // guest hits /oauth/authorize: stash the original URL before
        // bouncing to /login, exactly like redirect()->guest()/->intended()
        // would for a normal auth-gated route.
        $intended = 'https://auth.test/oauth/authorize?client_id=abc&response_type=code';
        $this->withSession(['url.intended' => $intended])
            ->get(route('auth.negotiate'), [
                'Authorization' => 'NTLM '.$this->buildType3('jdoe', 'RL'),
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'redirect' => $intended]);

        $this->assertAuthenticated();
        $this->assertNull(session('url.intended'), 'url.intended must be consumed, like redirect()->intended() does.');
    }

    public function test_ntlm_auto_login_falls_back_to_dashboard_without_an_intended_url(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $user = new LdapUser([
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);
        $user->setConnection($name);
        $user->save();

        $this->get(route('auth.negotiate'), [
            'Authorization' => 'NTLM '.$this->buildType3('jdoe', 'RL'),
        ])->assertJson(['success' => true, 'redirect' => route('dashboard')]);
    }

    public function test_manual_logout_cookie_blocks_auto_login(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->withCookie('auth_manual', '1')->get(route('auth.negotiate'));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'manual_logout']);
    }

    public function test_logout_sets_manual_cookie_and_redirects_with_manual_flag(): void
    {
        $directory = $this->makeDirectory();
        $name = DirectoryConnectionResolver::connectionName($directory);
        $this->emulate($directory);

        $user = new LdapUser([
            'cn' => 'Jane Doe',
            'samaccountname' => 'jdoe',
            'userprincipalname' => 'jdoe@test.local',
            'mail' => 'jdoe@test.local',
            'objectguid' => (string) Str::uuid(),
        ]);
        $user->setConnection($name);
        $user->save();

        $this->get(route('auth.negotiate'), [
            'Authorization' => 'NTLM '.$this->buildType3('jdoe', 'RL'),
        ]);
        $this->assertAuthenticated();

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login', ['manual' => 1]));
        $response->assertCookie('auth_manual', '1');
    }
}
