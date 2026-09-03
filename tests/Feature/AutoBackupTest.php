<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\AutoBackupRunner;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupService;
use App\Support\Secret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AutoBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/autobackup-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function admin(): User
    {
        SystemSetting::set('installed', '1');

        return User::factory()->create([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);
    }

    private function configureLocalTarget(): void
    {
        SystemSetting::set('auto_backup_target', 'local');
        SystemSetting::set('auto_backup_dir', $this->dir);
        SystemSetting::set('auto_backup_archive_password', Secret::encrypt('a-long-backup-password'));
    }

    private function fakeBackupService(): void
    {
        $this->mock(BackupService::class, function ($mock) {
            $tmpDir = storage_path('framework/testing/build-'.uniqid());
            File::ensureDirectoryExists($tmpDir);
            File::put($tmpDir.'/archive.authbak', 'ENCRYPTED-CONTENT');

            $mock->shouldReceive('create')->andReturn($tmpDir.'/archive.authbak');
            $mock->shouldReceive('fileName')->andReturn('idp-sicherung-'.now()->format('Y-m-d-His').'.authbak');
        });
    }

    public function test_settings_are_saved_and_secrets_are_stored_encrypted(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.backups.auto.update'), [
                'enabled' => '1',
                'frequency' => 'daily',
                'time' => '02:30',
                'keep' => '5',
                'target' => 's3',
                'dir' => 'idp-backups',
                'archive_password' => 'super-secret-archive',
                's3_bucket' => 'my-bucket',
                's3_region' => 'eu-central-1',
                's3_key' => 'AKIA...',
                's3_secret' => 'the-s3-secret',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('1', SystemSetting::get('auto_backup_enabled'));
        $this->assertSame('02:30', SystemSetting::get('auto_backup_time'));

        $storedArchive = SystemSetting::get('auto_backup_archive_password');
        $this->assertNotSame('super-secret-archive', $storedArchive);
        $this->assertSame('super-secret-archive', Secret::decrypt($storedArchive));
        $this->assertSame('the-s3-secret', Secret::decrypt(SystemSetting::get('auto_backup_s3_secret')));
    }

    public function test_a_blank_secret_keeps_the_existing_one(): void
    {
        SystemSetting::set('auto_backup_archive_password', Secret::encrypt('original-password'));

        $this->actingAs($this->admin())
            ->put(route('admin.backups.auto.update'), [
                'frequency' => 'weekly', 'time' => '04:00', 'keep' => '3', 'target' => 'local',
                'archive_password' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('original-password', Secret::decrypt(SystemSetting::get('auto_backup_archive_password')));
    }

    public function test_run_uploads_the_archive_to_the_local_target(): void
    {
        $this->configureLocalTarget();
        $this->fakeBackupService();

        $result = app(AutoBackupRunner::class)->run();

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty(glob($this->dir.'/*.authbak'));
        $this->assertSame('', SystemSetting::get('auto_backup_last_error'));
        $this->assertNotEmpty(SystemSetting::get('auto_backup_last_run'));
    }

    public function test_run_prunes_old_backups_beyond_the_retention(): void
    {
        $this->configureLocalTarget();
        SystemSetting::set('auto_backup_keep', '2');

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $day) {
            File::put($this->dir."/idp-sicherung-{$day}-000000.authbak", 'old');
        }

        $this->fakeBackupService();
        app(AutoBackupRunner::class)->run();

        // 3 alte + 1 neue = 4, davon bleiben die 2 jüngsten.
        $this->assertCount(2, glob($this->dir.'/*.authbak'));
    }

    public function test_run_fails_gracefully_without_an_archive_password(): void
    {
        SystemSetting::set('auto_backup_target', 'local');
        SystemSetting::set('auto_backup_dir', $this->dir);

        $result = app(AutoBackupRunner::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty(SystemSetting::get('auto_backup_last_error'));
    }

    public function test_command_skips_when_disabled_and_runs_with_force(): void
    {
        $this->configureLocalTarget();
        SystemSetting::set('auto_backup_enabled', '0');

        $this->artisan('backup:run')->assertExitCode(0);
        $this->assertEmpty(glob($this->dir.'/*.authbak'));

        $this->fakeBackupService();
        $this->artisan('backup:run --force')->assertExitCode(0);
        $this->assertNotEmpty(glob($this->dir.'/*.authbak'));
    }

    public function test_target_list_is_limited(): void
    {
        $this->assertSame(['local', 's3', 'ftp', 'sftp'], BackupDestination::TARGETS);
    }
}
