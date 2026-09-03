<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $overrides = []): User
    {
        SystemSetting::set('installed', '1');

        return User::factory()->create(array_merge([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true,
        ], $overrides));
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('users.csv', $content);
    }

    public function test_export_returns_a_csv_of_users(): void
    {
        $admin = $this->admin(['username' => 'chef']);
        User::factory()->create(['username' => 'anna', 'email' => 'anna@example.test', 'auth_source' => 'local']);

        $body = $this->actingAs($admin)->get(route('admin.users.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('username,first_name,last_name', $body);
        $this->assertStringContainsString('anna', $body);
        $this->assertStringContainsString('chef', $body);
    }

    public function test_export_respects_the_search_filter(): void
    {
        $admin = $this->admin(['username' => 'chef']);
        User::factory()->create(['username' => 'anna', 'name' => 'Anna Muster', 'auth_source' => 'local']);
        User::factory()->create(['username' => 'bob', 'name' => 'Bob Beispiel', 'auth_source' => 'local']);

        $body = $this->actingAs($admin)->get(route('admin.users.export', ['q' => 'anna']))->streamedContent();

        $this->assertStringContainsString('anna', $body);
        $this->assertStringNotContainsString('bob', $body);
    }

    public function test_import_creates_local_users(): void
    {
        $admin = $this->admin();

        $csv = "username,email,first_name,last_name,password,is_admin\n"
            ."neu1,neu1@example.test,Neu,Eins,Sicheres-Passwort-1,0\n"
            ."neu2,neu2@example.test,Neu,Zwei,,1\n";

        $response = $this->actingAs($admin)->post(route('admin.users.import.run'), ['file' => $this->csv($csv)]);
        $response->assertRedirect(route('admin.users.import'));

        $this->assertDatabaseHas('users', ['username' => 'neu1', 'email' => 'neu1@example.test', 'auth_source' => 'local']);
        $this->assertTrue(User::where('username', 'neu2')->first()->is_admin);

        $result = $response->getSession()->get('import_result');
        $this->assertSame(2, $result['created']);
        $this->assertArrayHasKey('neu2', $result['generated']);
    }

    public function test_import_updates_an_existing_local_user_without_touching_the_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'username' => 'anna', 'email' => 'old@example.test', 'name' => 'Alt',
            'auth_source' => 'local', 'is_active' => true,
        ]);
        $hash = $user->password;

        $csv = "username,email,name,is_active\nanna,neu@example.test,Anna Neu,0\n";
        $this->actingAs($admin)->post(route('admin.users.import.run'), ['file' => $this->csv($csv)]);

        $user->refresh();
        $this->assertSame('neu@example.test', $user->email);
        $this->assertFalse($user->is_active);
        $this->assertSame($hash, $user->password);
    }

    public function test_import_skips_a_directory_user_and_a_bad_row(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'aduser', 'auth_source' => 'active_directory']);

        $csv = "username,email\naduser,x@example.test\nkaputt,keine-email\n";
        $response = $this->actingAs($admin)->post(route('admin.users.import.run'), ['file' => $this->csv($csv)]);

        $result = $response->getSession()->get('import_result');
        $this->assertSame(0, $result['created']);
        $this->assertCount(2, $result['skipped']);
    }

    public function test_bulk_lock_and_unlock(): void
    {
        $admin = $this->admin();
        $a = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $b = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'lock', 'ids' => [$a->id, $b->id]])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'unlock', 'ids' => [$a->id]]);
        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_bulk_delete_removes_users_but_skips_self(): void
    {
        $admin = $this->admin();
        $a = User::factory()->create(['auth_source' => 'local']);

        $this->actingAs($admin)->post(route('admin.users.bulk'), ['action' => 'delete', 'ids' => [$admin->id, $a->id]]);

        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_bulk_delete_protects_the_last_local_admin(): void
    {
        // Der handelnde Admin kommt aus dem Verzeichnis, der einzige lokale
        // Admin ist das Ziel -> er darf nicht geloescht werden.
        $adActor = $this->admin(['auth_source' => 'active_directory']);
        $localAdmin = User::factory()->admin()->create(['auth_source' => 'local', 'is_active' => true]);

        $this->actingAs($adActor)->post(route('admin.users.bulk'), ['action' => 'delete', 'ids' => [$localAdmin->id]]);

        $this->assertDatabaseHas('users', ['id' => $localAdmin->id]);
    }
}
