<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\Secret;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Führt eine automatische Sicherung durch: Archiv bauen (via {@see BackupService}),
 * an das konfigurierte Ziel hochladen ({@see BackupDestination}), alte Sicherungen
 * gemäss Aufbewahrungsregel entfernen und das Ergebnis in den Systemeinstellungen
 * vermerken (für die Anzeige unter "Datensicherung").
 */
class AutoBackupRunner
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupDestination $destination,
    ) {}

    public function enabled(): bool
    {
        return SystemSetting::bool('auto_backup_enabled', false);
    }

    /**
     * Ist gerade eine automatische Sicherung fällig? Der Scheduler ruft den
     * Befehl regelmässig auf; hier wird entschieden, ob wirklich gesichert wird.
     */
    public function isDue(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $time = (string) (SystemSetting::get('auto_backup_time') ?: '03:00');
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        $scheduled = now()->setTime($h, $m, 0);

        if (SystemSetting::get('auto_backup_frequency', 'daily') === 'weekly') {
            $scheduled = $scheduled->startOfWeek(Carbon::MONDAY)->setTime($h, $m, 0);
        }

        if (now()->lessThan($scheduled)) {
            return false;
        }

        $lastRun = SystemSetting::get('auto_backup_last_run');

        return $lastRun === null || Carbon::parse($lastRun)->lessThan($scheduled);
    }

    /**
     * @return array{ok: bool, file?: string, message?: string, pruned?: int}
     */
    public function run(): array
    {
        $password = Secret::decrypt(SystemSetting::get('auto_backup_archive_password'));

        if ($password === '') {
            return $this->fail('Es ist kein Passwort für die Sicherungsdatei hinterlegt.');
        }

        @set_time_limit(0);

        try {
            $built = $this->backups->create($password);
        } catch (Throwable $e) {
            return $this->fail('Archiv konnte nicht erstellt werden: '.$e->getMessage());
        }

        $name = $this->backups->fileName();

        try {
            $disk = $this->destination->disk();
            $stream = fopen($built, 'rb');
            $disk->writeStream($this->destination->path($name), $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable $e) {
            File::deleteDirectory(dirname($built));

            return $this->fail('Upload zum Ziel fehlgeschlagen: '.$e->getMessage());
        }

        File::deleteDirectory(dirname($built));

        $pruned = $this->prune();

        SystemSetting::set('auto_backup_last_run', now()->toIso8601String());
        SystemSetting::set('auto_backup_last_error', '');
        SystemSetting::set('auto_backup_last_file', $name);

        AuditLog::record('admin.backup_auto_created', null, [
            'file' => $name,
            'target' => $this->destination->target(),
            'pruned' => $pruned,
        ]);

        return ['ok' => true, 'file' => $name, 'pruned' => $pruned];
    }

    /**
     * Entfernt alte Sicherungen, sodass nur die jüngsten N übrig bleiben.
     * 0 = alle behalten.
     */
    public function prune(): int
    {
        $keep = (int) SystemSetting::get('auto_backup_keep', 0);

        if ($keep <= 0) {
            return 0;
        }

        $disk = $this->destination->disk();
        $backups = $this->destination->existingBackups();
        $stale = array_slice($backups, $keep);

        foreach ($stale as $file) {
            rescue(fn () => $disk->delete($file['path']), null, false);
        }

        return count($stale);
    }

    private function fail(string $message): array
    {
        SystemSetting::set('auto_backup_last_run', now()->toIso8601String());
        SystemSetting::set('auto_backup_last_error', $message);

        AuditLog::record('admin.backup_auto_failed', null, ['error' => $message]);

        return ['ok' => false, 'message' => $message];
    }
}
