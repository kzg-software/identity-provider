<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\ScimConnection;
use App\Models\ScimResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Scim\ScimSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScimProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function connection(array $overrides = []): ScimConnection
    {
        $application = Application::create(['name' => 'Wiki', 'slug' => 'wiki', 'is_active' => true]);

        return $application->scimConnection()->create(array_merge([
            'base_url' => 'https://wiki.example.test/scim/v2',
            'auth_token' => 'secret-token',
            'sync_users' => true,
            'sync_groups' => false,
            'on_removal' => 'deactivate',
            'is_active' => true,
        ], $overrides));
    }

    private function user(string $username, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'username' => $username,
            'email' => $username.'@example.test',
            'first_name' => ucfirst($username),
            'last_name' => 'Tester',
            'is_active' => true,
            'auth_source' => 'local',
        ], $overrides));
    }

    private function fakeScim(): void
    {
        Http::fake([
            'wiki.example.test/scim/v2/Users' => Http::sequence()
                ->push(['id' => 'remote-1'], 201)
                ->push(['id' => 'remote-2'], 201)
                ->push(['id' => 'remote-3'], 201),
            'wiki.example.test/scim/v2/Users/*' => Http::response([], 200),
            'wiki.example.test/scim/v2/Groups' => Http::response(['id' => 'group-remote-1'], 201),
            'wiki.example.test/scim/v2/Groups/*' => Http::response([], 200),
            'wiki.example.test/scim/v2/ServiceProviderConfig' => Http::response(['patch' => ['supported' => true]], 200),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_sync_creates_users_with_bearer_token_and_scim_payload(): void
    {
        $this->fakeScim();
        $user = $this->user('anna');
        $connection = $this->connection();

        $stats = app(ScimSyncService::class)->sync($connection);

        $this->assertSame(1, $stats['created']);
        $this->assertSame([], $stats['errors']);

        Http::assertSent(function (Request $request) use ($user) {
            return $request->method() === 'POST'
                && $request->url() === 'https://wiki.example.test/scim/v2/Users'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && str_starts_with($request->header('Content-Type')[0], 'application/scim+json')
                && $request['userName'] === 'anna'
                && $request['externalId'] === (string) $user->id
                && $request['name']['givenName'] === 'Anna'
                && $request['emails'][0]['value'] === 'anna@example.test'
                && $request['active'] === true;
        });

        $this->assertDatabaseHas('scim_resources', ['resource_type' => 'user', 'local_id' => $user->id, 'remote_id' => 'remote-1']);
        $this->assertSame('ok', $connection->fresh()->last_status);
    }

    public function test_second_sync_without_changes_sends_nothing(): void
    {
        $this->fakeScim();
        $this->user('anna');
        $connection = $this->connection();

        app(ScimSyncService::class)->sync($connection);
        Http::fake();
        $stats = app(ScimSyncService::class)->sync($connection->fresh());

        $this->assertSame(0, $stats['created'] + $stats['updated'] + $stats['removed']);
        Http::assertNothingSent();
    }

    public function test_changed_user_is_updated_with_put(): void
    {
        $this->fakeScim();
        $user = $this->user('anna');
        $connection = $this->connection();
        app(ScimSyncService::class)->sync($connection);

        $user->update(['last_name' => 'Neu']);
        $stats = app(ScimSyncService::class)->sync($connection->fresh());

        $this->assertSame(1, $stats['updated']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://wiki.example.test/scim/v2/Users/remote-1'
            && $r['name']['familyName'] === 'Neu');
    }

    public function test_user_losing_access_is_deactivated_and_reactivated(): void
    {
        $this->fakeScim();
        $allowed = $this->user('anna');
        $other = $this->user('bob');
        $connection = $this->connection();

        AccessPolicy::create(['application_id' => $connection->application_id, 'effect' => 'allow', 'subject_type' => 'user', 'subject_value' => 'anna', 'priority' => 0]);

        app(ScimSyncService::class)->sync($connection);
        $this->assertDatabaseMissing('scim_resources', ['local_id' => $other->id]);

        AccessPolicy::create(['application_id' => $connection->application_id, 'effect' => 'allow', 'subject_type' => 'user', 'subject_value' => 'bob', 'priority' => 0]);
        app(ScimSyncService::class)->sync($connection->fresh());
        $this->assertDatabaseHas('scim_resources', ['local_id' => $other->id, 'deactivated' => false]);

        AccessPolicy::create(['application_id' => $connection->application_id, 'effect' => 'deny', 'subject_type' => 'user', 'subject_value' => 'bob', 'priority' => 5]);
        $stats = app(ScimSyncService::class)->sync($connection->fresh());

        $this->assertSame(1, $stats['removed']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/Users/remote-2')
            && $r['Operations'][0]['value'] === false);
        $this->assertDatabaseHas('scim_resources', ['local_id' => $other->id, 'deactivated' => true]);
        $this->assertDatabaseHas('scim_resources', ['local_id' => $allowed->id, 'deactivated' => false]);

        AccessPolicy::where('effect', 'deny')->delete();
        app(ScimSyncService::class)->sync($connection->fresh());
        $this->assertDatabaseHas('scim_resources', ['local_id' => $other->id, 'deactivated' => false]);
    }

    public function test_deleted_user_is_removed_remotely_when_configured(): void
    {
        $this->fakeScim();
        $user = $this->user('anna');
        $connection = $this->connection(['on_removal' => 'delete']);
        app(ScimSyncService::class)->sync($connection);

        $user->delete();
        app(ScimSyncService::class)->sync($connection->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/Users/remote-1'));
        $this->assertSame(0, ScimResource::count());
    }

    public function test_inactive_users_are_not_provisioned(): void
    {
        $this->fakeScim();
        $this->user('gone', ['is_active' => false]);
        $connection = $this->connection();

        $stats = app(ScimSyncService::class)->sync($connection);

        $this->assertSame(0, $stats['created']);
        Http::assertNothingSent();
    }

    public function test_existing_remote_user_is_adopted_on_conflict(): void
    {
        Http::fake([
            'wiki.example.test/scim/v2/Users?*' => Http::response(['Resources' => [['id' => 'existing-9']]], 200),
            'wiki.example.test/scim/v2/Users/*' => Http::response([], 200),
            'wiki.example.test/scim/v2/Users' => Http::response(['detail' => 'exists'], 409),
        ]);
        $user = $this->user('anna');
        $connection = $this->connection();

        $stats = app(ScimSyncService::class)->sync($connection);

        $this->assertSame([], $stats['errors']);
        $this->assertDatabaseHas('scim_resources', ['local_id' => $user->id, 'remote_id' => 'existing-9']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/Users/existing-9'));
    }

    public function test_failed_requests_are_reported_without_aborting_the_run(): void
    {
        Http::fake([
            'wiki.example.test/scim/v2/Users' => Http::sequence()
                ->push(['detail' => 'kaputt'], 500)
                ->push(['id' => 'remote-2'], 201),
        ]);
        $this->user('anna');
        $this->user('bob');
        $connection = $this->connection();

        $stats = app(ScimSyncService::class)->sync($connection);

        $this->assertSame(1, $stats['created']);
        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('kaputt', $stats['errors'][0]);
        $this->assertSame('error', $connection->fresh()->last_status);
    }

    public function test_groups_are_synced_with_provisioned_members(): void
    {
        $this->fakeScim();
        $user = $this->user('anna');
        $directory = Directory::create(['name' => 'AD', 'type' => 'active_directory', 'host' => 'ad.test', 'is_active' => true]);
        $directoryUser = DirectoryUser::create(['directory_id' => $directory->id, 'user_id' => $user->id, 'object_guid' => 'g-1', 'sam_account_name' => 'anna', 'distinguished_name' => 'CN=anna,DC=test']);
        $group = DirectoryGroup::create(['directory_id' => $directory->id, 'object_guid' => 'grp-1', 'name' => 'Redaktion', 'distinguished_name' => 'CN=Redaktion,DC=test']);
        $group->directoryUsers()->attach($directoryUser->id);
        DirectoryGroup::create(['directory_id' => $directory->id, 'object_guid' => 'grp-2', 'name' => 'Leer', 'distinguished_name' => 'CN=Leer,DC=test']);
        $connection = $this->connection(['sync_groups' => true]);

        $stats = app(ScimSyncService::class)->sync($connection);

        $this->assertSame(2, $stats['created']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/Groups')
            && $r['displayName'] === 'Redaktion'
            && $r['members'] === [['value' => 'remote-1']]);
        $this->assertDatabaseCount('scim_resources', 2);
    }

    public function test_command_runs_active_connections_only(): void
    {
        $this->fakeScim();
        $this->user('anna');
        $this->connection();

        $this->artisan('scim:sync')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_user_changes_queue_a_debounced_sync_only_with_an_active_connection(): void
    {
        $this->user('before');
        Queue::assertNothingPushed();

        $this->connection();
        $this->user('after');
        $this->user('after2');

        Queue::assertPushed(\App\Jobs\SyncScimConnections::class, 1);
    }

    public function test_admin_can_manage_the_connection(): void
    {
        $this->fakeScim();
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
        $application = Application::create(['name' => 'Wiki', 'slug' => 'wiki', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.applications.scim.save', $application), [
            'base_url' => 'https://wiki.example.test/scim/v2/',
            'auth_token' => 'abc',
            'on_removal' => 'deactivate',
            'sync_users' => '1',
            'sync_groups' => '1',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $connection = $application->fresh()->scimConnection;
        $this->assertSame('https://wiki.example.test/scim/v2', $connection->base_url);
        $this->assertSame('abc', $connection->auth_token);
        $this->assertNotSame('abc', $connection->getRawOriginal('auth_token'));

        $this->actingAs($admin)->put(route('admin.applications.scim.save', $application), [
            'base_url' => 'https://wiki.example.test/scim/v2',
            'auth_token' => '',
            'on_removal' => 'delete',
            'sync_users' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('abc', $application->fresh()->scimConnection->auth_token);
        $this->assertSame('delete', $application->fresh()->scimConnection->on_removal);

        $this->actingAs($admin)->post(route('admin.applications.scim.test', $application))
            ->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.applications.scim.sync', $application))
            ->assertSessionHas('status');

        $this->actingAs($admin)->get(route('admin.applications.show', ['application' => $application, 'tab' => 'provisionierung']))
            ->assertOk()
            ->assertSee('SCIM-Basis-URL');

        $this->actingAs($admin)->delete(route('admin.applications.scim.destroy', $application))
            ->assertSessionHas('status');
        $this->assertNull($application->fresh()->scimConnection);
    }

    public function test_connection_test_reports_failures(): void
    {
        Http::fake(['wiki.example.test/*' => Http::response(['detail' => 'Unauthorized'], 401)]);
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
        $connection = $this->connection();

        $this->actingAs($admin)->post(route('admin.applications.scim.test', $connection->application))
            ->assertSessionHasErrors('scim');
    }

    public function test_token_is_never_exposed_in_the_form(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
        $connection = $this->connection();

        $this->actingAs($admin)->get(route('admin.applications.show', ['application' => $connection->application, 'tab' => 'provisionierung']))
            ->assertOk()
            ->assertDontSee('secret-token');
    }
}
