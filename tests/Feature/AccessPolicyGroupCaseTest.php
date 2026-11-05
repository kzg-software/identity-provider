<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AccessPolicyEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gruppennamen im Active Directory unterscheiden nicht zwischen Groß- und
 * Kleinschreibung. Zugriffsregeln müssen deshalb ebenfalls unabhängig davon
 * greifen – sonst wirkt eine „Verweigern"-Regel mit abweichender Schreibweise
 * gar nicht.
 */
class AccessPolicyGroupCaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function userInGroup(string $groupName): User
    {
        $user = User::factory()->create(['auth_source' => 'active_directory', 'is_active' => true]);
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'base_dn' => 'DC=test,DC=local']);
        $directoryUser = DirectoryUser::create([
            'directory_id' => $directory->id,
            'user_id' => $user->id,
            'object_guid' => (string) Str::uuid(),
            'sam_account_name' => 'tester',
            'distinguished_name' => 'CN=tester,DC=test,DC=local',
        ]);
        $group = DirectoryGroup::create([
            'directory_id' => $directory->id,
            'object_guid' => (string) Str::uuid(),
            'name' => $groupName,
            'distinguished_name' => "CN={$groupName},DC=test,DC=local",
        ]);
        $directoryUser->groups()->attach($group->id);

        return $user->fresh();
    }

    private function application(): Application
    {
        return Application::create(['name' => 'App', 'slug' => 'app', 'is_active' => true]);
    }

    private function policy(Application $app, string $effect, string $group): void
    {
        AccessPolicy::create([
            'application_id' => $app->id,
            'effect' => $effect,
            'subject_type' => 'group',
            'subject_value' => $group,
            'priority' => 0,
        ]);
    }

    public function test_allow_rule_matches_group_regardless_of_case(): void
    {
        $user = $this->userInGroup('IT-Admins');
        $app = $this->application();
        $this->policy($app, 'allow', 'it-admins');

        $this->assertTrue(AccessPolicyEvaluator::passesPolicies($app->id, $user));
    }

    public function test_deny_rule_matches_group_regardless_of_case(): void
    {
        $user = $this->userInGroup('Extern');
        $app = $this->application();
        $this->policy($app, 'allow', 'Extern');
        $this->policy($app, 'deny', 'EXTERN');

        $this->assertFalse(AccessPolicyEvaluator::passesPolicies($app->id, $user));
    }

    public function test_rule_for_other_group_still_does_not_match(): void
    {
        $user = $this->userInGroup('Extern');
        $app = $this->application();
        $this->policy($app, 'allow', 'Intern');

        $this->assertFalse(AccessPolicyEvaluator::passesPolicies($app->id, $user));
    }
}
