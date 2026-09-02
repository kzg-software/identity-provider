<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\DatabaseTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'username' => 'backup-admin',
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);
    }

    public function test_database_transfer_round_trips_all_rows(): void
    {
        $original = DB::getDefaultConnection();
        $file = storage_path('framework/testing/bt-'.uniqid().'.sqlite');
        $dumpDir = storage_path('framework/testing/btd-'.uniqid());

        File::ensureDirectoryExists(dirname($file));
        touch($file);

        config(['database.connections.bt' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        try {
            DB::setDefaultConnection('bt');
            Artisan::call('migrate:fresh', ['--database' => 'bt', '--force' => true]);

            DB::table('system_settings')->insert([
                'key' => 'system_name', 'value' => 'Vor der Sicherung',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $transfer = new DatabaseTransfer;
            $manifest = $transfer->dump($dumpDir.'/database');

            $this->assertSame('sqlite', $manifest['driver']);

            DB::table('system_settings')->where('key', 'system_name')->update(['value' => 'NACH der Änderung']);

            $transfer->restore($dumpDir.'/database', $manifest);

            $this->assertSame('Vor der Sicherung',
                DB::table('system_settings')->where('key', 'system_name')->value('value'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('bt');
            File::delete($file);
            File::deleteDirectory($dumpDir);
        }
    }

    public function test_backup_area_is_admin_only(): void
    {
        SystemSetting::set('installed', '1');

        $this->get(route('admin.backups.index'))->assertRedirect(route('login'));

        $user = User::factory()->create(['auth_source' => 'active_directory', 'is_admin' => false, 'is_active' => true]);
        $this->actingAs($user)->get(route('admin.backups.index'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.backups.index'))->assertOk()->assertSee('Datensicherung');
    }

    public function test_backup_download_rejects_a_wrong_account_password(): void
    {
        SystemSetting::set('installed', '1');

        $this->actingAs($this->admin())
            ->from(route('admin.backups.index'))
            ->post(route('admin.backups.download'), [
                'password' => 'a-long-backup-password',
                'password_confirmation' => 'a-long-backup-password',
                'current_password' => 'not-my-password',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_restore_requires_the_overwrite_confirmation(): void
    {
        SystemSetting::set('installed', '1');

        $this->actingAs($this->admin())
            ->from(route('admin.backups.index'))
            ->post(route('admin.backups.restore'), [
                'backup' => UploadedFile::fake()->create('backup.authbak', 8),
                'password' => 'irrelevant',
                'current_password' => 'Password123!',
            ])
            ->assertSessionHasErrors('confirm');
    }

    public function test_installer_welcome_offers_setup_and_restore(): void
    {
        $this->get(route('install.index'))
            ->assertOk()
            ->assertSee('Neu einrichten')
            ->assertSee('Aus Sicherung wiederherstellen');

        $this->get(route('install.restore'))
            ->assertOk()
            ->assertSee('Passwort der Sicherung');
    }

    public function test_installer_restore_rejects_a_file_that_is_not_a_backup(): void
    {
        $envBefore = File::get(base_path('.env'));

        $this->from(route('install.restore'))
            ->post(route('install.restore.store'), [
                'backup' => UploadedFile::fake()->createWithContent('bogus.authbak', str_repeat('x', 500)),
                'password' => 'whatever',
            ])
            ->assertSessionHasErrors('backup');

        $this->assertSame($envBefore, File::get(base_path('.env')), '.env must not be touched on a failed restore');
    }
}
