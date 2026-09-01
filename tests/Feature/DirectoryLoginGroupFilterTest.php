<?php

namespace Tests\Feature;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\GroupMembershipFilter;
use App\Directory\LdapConnectionFactory;
use App\Models\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Tests\TestCase;

class DirectoryLoginGroupFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DirectoryEmulator::tearDown();
        Container::getInstance()->getConnectionManager()->flush();

        parent::tearDown();
    }

    public function test_filter_entries_are_parsed_from_lines_and_commas(): void
    {
        $directory = Directory::create([
            'name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=t,DC=l',
            'login_group_filter' => "CN=IDP-Login,OU=G,DC=t,DC=l\n  GG_Admins \n;GG_Reviewer\n\n",
        ]);

        $this->assertSame(
            ['CN=IDP-Login,OU=G,DC=t,DC=l', 'GG_Admins', 'GG_Reviewer'],
            $directory->loginGroupFilters()
        );
        $this->assertTrue($directory->hasLoginGroupFilter());

        $empty = Directory::create(['name' => 'B', 'type' => 'active_directory', 'base_dn' => 'DC=t,DC=l']);
        $this->assertSame([], $empty->loginGroupFilters());
        $this->assertFalse($empty->hasLoginGroupFilter());
    }

    public function test_constrain_adds_a_nested_memberof_filter(): void
    {
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=t,DC=l']);
        $name = DirectoryConnectionResolver::connectionName($directory);
        Container::addConnection(LdapConnectionFactory::make($directory), $name);
        DirectoryEmulator::setup($name);

        $query = GroupMembershipFilter::constrain(
            LdapUser::on($name),
            ['CN=IDP-Login,OU=G,DC=t,DC=l', 'CN=Second,DC=t,DC=l']
        );

        $filter = $query->getUnescapedQuery();

        // LDAP_MATCHING_RULE_IN_CHAIN für verschachtelte Mitgliedschaft
        $this->assertStringContainsString('1.2.840.113556.1.4.1941', $filter);
        $this->assertStringContainsString('CN=IDP-Login,OU=G,DC=t,DC=l', $filter);
        $this->assertStringContainsString('CN=Second,DC=t,DC=l', $filter);
    }

    public function test_dn_entries_pass_through_without_a_lookup(): void
    {
        $directory = Directory::create([
            'name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=t,DC=l',
            'login_group_filter' => 'CN=IDP-Login,OU=Gruppen,DC=t,DC=l',
        ]);
        $name = DirectoryConnectionResolver::connectionName($directory);
        Container::addConnection(LdapConnectionFactory::make($directory), $name);
        DirectoryEmulator::setup($name);

        $this->assertSame(
            ['CN=IDP-Login,OU=Gruppen,DC=t,DC=l'],
            GroupMembershipFilter::groupDns($directory, $name)
        );
    }

    public function test_no_filter_leaves_the_query_untouched(): void
    {
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=t,DC=l']);
        $name = DirectoryConnectionResolver::connectionName($directory);
        Container::addConnection(LdapConnectionFactory::make($directory), $name);
        DirectoryEmulator::setup($name);

        $before = LdapUser::on($name)->getUnescapedQuery();
        $after = GroupMembershipFilter::constrain(LdapUser::on($name), [])->getUnescapedQuery();

        $this->assertSame($before, $after);
    }
}
