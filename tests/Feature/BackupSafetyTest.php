<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\Backup\ArchiveCipher;
use App\Services\Backup\AutoBackupRunner;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupPaths;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseTransfer;
use App\Services\Backup\RestoreService;
use App\Support\Secret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Deckt die Korrektur des rekursiven Backup-Fehlers ab: das lokale
 * Sicherungsziel lag bisher standardmäßig innerhalb des gesicherten Bereichs
 * (storage/app/private/backups unter storage/app/private), wodurch jede neue
 * Sicherung alle bisherigen mit einsammelte und sich bei jedem Lauf
 * verdoppelte.
 *
 * Jeder Test läuft gegen ein eigenes, isoliertes Storage-Verzeichnis
 * (App::useStoragePath), damit nichts an der echten storage/app-Ablage
 * verändert wird.
 */
class BackupSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', '1');

        $this->storageRoot = sys_get_temp_dir().'/idp-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->storageRoot.'/app/public');
        File::ensureDirectoryExists($this->storageRoot.'/app/private');
        File::ensureDirectoryExists($this->storageRoot.'/framework/backups');
        File::ensureDirectoryExists($this->storageRoot.'/framework/testing');
        $this->app->useStoragePath($this->storageRoot);

        // Die Tests laufen gegen eine In-Memory-SQLite-DB, die sich nicht per
        // VACUUM INTO kopieren lässt - der eigentliche Datenbank-Export ist
        // hier nicht der Untersuchungsgegenstand, daher ein einfacher Fake.
        $this->mock(DatabaseTransfer::class, function ($mock) {
            $mock->shouldReceive('dump')->andReturnUsing(function (string $dir) {
                File::ensureDirectoryExists($dir);
                File::put($dir.'/database.sqlite', 'FAKE-DB');

                return ['driver' => 'sqlite', 'database' => 'fake.sqlite', 'tables' => 0];
            });
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    /** @return list<string> */
    private function zipEntries(string $archivePath, string $password): array
    {
        $workDir = $this->storageRoot.'/framework/testing/inspect-'.uniqid();
        File::ensureDirectoryExists($workDir);
        $zipPath = $workDir.'/a.zip';

        ArchiveCipher::decryptFile($archivePath, $zipPath, $password);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $zip->close();
        File::deleteDirectory($workDir);

        return $entries;
    }

    // ----- Test 1: vorhandene .authbak-Datei darf nicht erneut mitgesichert werden -----

    public function test_existing_authbak_in_default_backup_dir_is_never_included(): void
    {
        $legacyDir = storage_path('app/private/backups');
        File::ensureDirectoryExists($legacyDir);
        File::put($legacyDir.'/alte-sicherung.authbak', str_repeat('X', 2048));

        $archive = app(BackupService::class)->create('a-long-backup-password');
        $entries = $this->zipEntries($archive, 'a-long-backup-password');
        File::deleteDirectory(dirname($archive));

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('backups/', $entry, "Zip enthält den Sicherungsordner: {$entry}");
            $this->assertStringNotContainsString('alte-sicherung.authbak', $entry);
        }
    }

    // ----- Test 2: die Größe darf nicht allein wegen alter Sicherungsdateien wachsen -----

    public function test_backup_size_does_not_grow_from_accumulated_old_backups(): void
    {
        $archiveOne = app(BackupService::class)->create('a-long-backup-password');
        $sizeOne = filesize($archiveOne);
        File::deleteDirectory(dirname($archiveOne));

        // Mehrere "alte" Sicherungen an der Default-Ablage simulieren - genau
        // das historische Verhalten vor der Korrektur.
        $legacyDir = storage_path('app/private/backups');
        File::ensureDirectoryExists($legacyDir);

        for ($i = 0; $i < 5; $i++) {
            File::put($legacyDir."/junk-{$i}.authbak", str_repeat('Y', 500_000));
        }

        $archiveTwo = app(BackupService::class)->create('a-long-backup-password');
        $sizeTwo = filesize($archiveTwo);
        File::deleteDirectory(dirname($archiveTwo));

        // Toleranz für Metadaten-Rauschen, aber keine ~2,5 MB aus den fünf
        // künstlichen 500-KB-"alten Sicherungen".
        $this->assertLessThan($sizeOne + 50_000, $sizeTwo);
    }

    // ----- Test 3: das temporäre Arbeitsverzeichnis wird nicht mitgesichert -----

    public function test_temp_work_directory_is_never_backed_up(): void
    {
        $stray = BackupPaths::workRoot().'/stray-uuid/payload';
        File::ensureDirectoryExists($stray);
        File::put($stray.'/leftover.txt', 'sollte nie gesichert werden');

        $archive = app(BackupService::class)->create('a-long-backup-password');
        $entries = $this->zipEntries($archive, 'a-long-backup-password');
        File::deleteDirectory(dirname($archive));
        File::deleteDirectory(BackupPaths::workRoot().'/stray-uuid');

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('leftover.txt', $entry);
        }
    }

    public function test_destination_inside_a_source_root_is_rejected(): void
    {
        $this->expectException(BackupException::class);

        BackupPaths::assertDestinationIsSafe(storage_path('app/private/irgendwo'));
    }

    public function test_destination_equal_to_temp_work_root_is_rejected(): void
    {
        $this->expectException(BackupException::class);

        BackupPaths::assertDestinationIsSafe(BackupPaths::workRoot());
    }

    public function test_auto_backup_run_aborts_when_local_destination_is_unsafe(): void
    {
        SystemSetting::set('auto_backup_target', 'local');
        SystemSetting::set('auto_backup_dir', 'app/private/backups'); // absichtlich unsicher
        SystemSetting::set('auto_backup_archive_password', Secret::encrypt('a-long-backup-password'));

        $result = app(AutoBackupRunner::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('überschneidet sich', $result['message']);
    }

    // ----- Test 5: zwei gleichzeitige Sicherungen -----

    public function test_second_concurrent_backup_is_blocked_by_the_lock(): void
    {
        $lock = Cache::lock('backup:build-lock', 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(BackupException::class);
            $this->expectExceptionMessage('läuft bereits');

            app(BackupService::class)->create('a-long-backup-password');
        } finally {
            $lock->release();
        }
    }

    // ----- Test 6: zu wenig Speicherplatz -----

    public function test_backup_is_not_started_when_free_space_is_too_low(): void
    {
        config(['backup.min_free_bytes' => PHP_INT_MAX]);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Nicht genügend freier Speicherplatz');

        app(BackupService::class)->create('a-long-backup-password');
    }

    // ----- Test 4: Rotation löscht nur alte Sicherungen (Alter zusätzlich zur Anzahl) -----

    public function test_retention_days_removes_only_backups_older_than_the_configured_age(): void
    {
        $dir = $this->storageRoot.'/retdays';
        File::ensureDirectoryExists($dir);

        SystemSetting::set('auto_backup_target', 'local');
        SystemSetting::set('auto_backup_dir', $dir);
        SystemSetting::set('auto_backup_keep', '0'); // nur das Alter zählt
        SystemSetting::set('auto_backup_retention_days', '5');

        $old = $dir.'/idp-sicherung-2020-01-01-000000.authbak';
        $recent = $dir.'/idp-sicherung-2020-01-02-000000.authbak';
        File::put($old, 'old');
        File::put($recent, 'recent');
        touch($old, now()->subDays(10)->getTimestamp());
        touch($recent, now()->subDays(1)->getTimestamp());

        $pruned = app(AutoBackupRunner::class)->prune();

        $this->assertSame(1, $pruned);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_prune_never_deletes_the_newest_backup_even_if_it_looks_old(): void
    {
        $dir = $this->storageRoot.'/retdays2';
        File::ensureDirectoryExists($dir);

        SystemSetting::set('auto_backup_target', 'local');
        SystemSetting::set('auto_backup_dir', $dir);
        SystemSetting::set('auto_backup_keep', '0');
        SystemSetting::set('auto_backup_retention_days', '1');

        $onlyOne = $dir.'/idp-sicherung-2020-01-01-000000.authbak';
        File::put($onlyOne, 'einzige Sicherung');
        touch($onlyOne, now()->subYears(2)->getTimestamp());

        $pruned = app(AutoBackupRunner::class)->prune();

        $this->assertSame(0, $pruned);
        $this->assertFileExists($onlyOne);
    }

    // ----- Test 7 / Anforderung 15: Restore ignoriert einen mitgesicherten "backups"-Unterordner -----

    public function test_restoring_storage_area_discards_legacy_backups_subfolder(): void
    {
        $source = $this->storageRoot.'/restore-src';
        $target = $this->storageRoot.'/restore-target';

        File::ensureDirectoryExists($source.'/backups');
        File::put($source.'/backups/alte-sicherung.authbak', 'junk');
        File::put($source.'/echter-upload.png', 'echte Nutzdaten');

        app(RestoreService::class)->restoreStorageArea($source, $target);

        $this->assertFileExists($target.'/echter-upload.png');
        $this->assertDirectoryDoesNotExist($target.'/backups');
    }

    public function test_inspecting_a_backup_still_works_after_the_fix(): void
    {
        $archive = app(BackupService::class)->create('a-long-backup-password');

        $manifest = app(RestoreService::class)->inspect($archive, 'a-long-backup-password');
        File::deleteDirectory(dirname($archive));

        $this->assertSame(BackupService::MANIFEST_FORMAT, $manifest['format']);
        $this->assertTrue($manifest['contents']['database']);
    }
}
