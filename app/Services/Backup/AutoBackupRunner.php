<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\Secret;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
        $startedAt = now();

        $password = Secret::decrypt(SystemSetting::get('auto_backup_archive_password'));

        if ($password === '') {
            return $this->fail('Es ist kein Passwort für die Sicherungsdatei hinterlegt.', $startedAt);
        }

        $isLocal = $this->destination->target() === 'local';

        if ($isLocal) {
            try {
                BackupPaths::assertDestinationIsSafe($this->destination->localRoot());
                BackupPaths::assertEnoughFreeSpace($this->destination->localRoot());
            } catch (BackupException $e) {
                return $this->fail($e->getMessage(), $startedAt);
            }
        }

        @set_time_limit(0);

        Log::info('backup.auto.started', [
            'target' => $this->destination->target(),
            'destination' => $isLocal ? $this->destination->localRoot() : $this->destination->path('*'),
        ]);

        try {
            $built = $this->backups->create($password);
        } catch (Throwable $e) {
            return $this->fail('Archiv konnte nicht erstellt werden: '.$e->getMessage(), $startedAt);
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

            return $this->fail('Upload zum Ziel fehlgeschlagen: '.$e->getMessage(), $startedAt);
        }

        $size = is_file($built) ? filesize($built) : null;
        File::deleteDirectory(dirname($built));

        $pruned = $this->prune();

        SystemSetting::set('auto_backup_last_run', now()->toIso8601String());
        SystemSetting::set('auto_backup_last_error', '');
        SystemSetting::set('auto_backup_last_file', $name);

        Log::info('backup.auto.completed', [
            'started_at' => $startedAt->toIso8601String(),
            'file' => $name,
            'target' => $this->destination->target(),
            'size_bytes' => $size,
            'duration_ms' => now()->diffInMilliseconds($startedAt),
            'pruned' => $pruned,
        ]);

        AuditLog::record('admin.backup_auto_created', null, [
            'file' => $name,
            'target' => $this->destination->target(),
            'pruned' => $pruned,
        ]);

        return ['ok' => true, 'file' => $name, 'pruned' => $pruned];
    }

    /**
     * Entfernt alte Sicherungen: alles jenseits der behaltenen Anzahl
     * (auto_backup_keep) und - falls gesetzt - alles älter als
     * auto_backup_retention_days. Die jüngste Sicherung wird nie entfernt,
     * damit nach einer Rotation immer mindestens eine Sicherung übrig bleibt.
     * Läuft ausschließlich gegen das Zielverzeichnis, nie gegen das
     * Arbeitsverzeichnis eines laufenden Backups.
     */
    public function prune(): int
    {
        $keep = (int) SystemSetting::get('auto_backup_keep', config('backup.max_count', 14));
        $retentionDays = (int) SystemSetting::get('auto_backup_retention_days', 0);

        $backups = $this->destination->existingBackups();

        if (count($backups) <= 1) {
            return 0;
        }

        $cutoff = $retentionDays > 0 ? now()->subDays($retentionDays)->getTimestamp() : null;
        $disk = $this->destination->disk();
        $stale = [];

        foreach ($backups as $index => $file) {
            if ($index === 0) {
                continue; // die jüngste Sicherung bleibt immer erhalten.
            }

            $beyondCount = $keep > 0 && $index >= $keep;
            $tooOld = $cutoff !== null && $file['last_modified'] !== null && $file['last_modified'] < $cutoff;

            if ($beyondCount || $tooOld) {
                $stale[] = $file;
            }
        }

        foreach ($stale as $file) {
            rescue(fn () => $disk->delete($file['path']), null, false);
        }

        if ($stale !== []) {
            Log::info('backup.auto.pruned', [
                'removed' => array_map(fn ($f) => $f['name'], $stale),
                'keep' => $keep,
                'retention_days' => $retentionDays,
            ]);
        }

        return count($stale);
    }

    private function fail(string $message, Carbon $startedAt): array
    {
        SystemSetting::set('auto_backup_last_run', now()->toIso8601String());
        SystemSetting::set('auto_backup_last_error', $message);

        Log::error('backup.auto.failed', [
            'started_at' => $startedAt->toIso8601String(),
            'duration_ms' => now()->diffInMilliseconds($startedAt),
            'error' => $message,
        ]);

        AuditLog::record('admin.backup_auto_failed', null, ['error' => $message]);

        return ['ok' => false, 'message' => $message];
    }
}
