<?php

namespace App\Services\Backup;

use App\Models\SystemSetting;
use App\Support\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Erstellt eine vollständige, passwortgeschützte Sicherung des Systems:
 * Datenbank, Konfigurationsdatei (.env) und alle hochgeladenen Dateien
 * (Logo, Favicon, Login-Hintergrund und sonstige Uploads).
 *
 * Aus einer solchen Sicherung lässt sich das System 1:1 wiederherstellen,
 * auch auf einem frischen Server.
 */
class BackupService
{
    public const MANIFEST_FORMAT = 1;

    /**
     * Gemeinsamer Lock über alle Aufrufer hinweg (manueller Download,
     * "Jetzt sichern" und den geplanten Befehl), damit niemals zwei
     * Sicherungen gleichzeitig gebaut werden - auch nicht aus verschiedenen
     * Containern (idp-app, idp-queue, idp-scheduler) heraus, da der Lock über
     * den (Datenbank-)Cache geteilt wird.
     */
    private const LOCK_KEY = 'backup:build-lock';

    public function __construct(private readonly DatabaseTransfer $database) {}

    /**
     * Baut die Sicherung und gibt den Pfad zur fertigen, verschlüsselten
     * Datei zurück. Der Aufrufer ist dafür verantwortlich, das umliegende
     * Arbeitsverzeichnis (dirname des Rückgabewerts) nach dem Versand wieder
     * zu löschen.
     */
    public function create(string $password): string
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) config('backup.lock_timeout_seconds', 3600));

        if (! $lock->get()) {
            throw new BackupException('Es läuft bereits eine andere Sicherung. Bitte in ein paar Minuten erneut versuchen.');
        }

        try {
            return $this->buildLocked($password);
        } finally {
            $lock->release();
        }
    }

    private function buildLocked(string $password): string
    {
        $startedAt = now();
        $start = microtime(true);

        $workDir = BackupPaths::workRoot().'/'.Str::uuid();
        $payloadDir = $workDir.'/payload';
        $excluded = BackupPaths::excludedFromBackupContent();

        BackupPaths::assertEnoughFreeSpace(BackupPaths::workRoot());

        File::ensureDirectoryExists($payloadDir);

        Log::info('backup.create.started', [
            'work_dir' => $workDir,
            'excluded_paths' => $excluded,
        ]);

        try {
            $databaseManifest = $this->database->dump($payloadDir.'/database');

            $this->copyEnv($payloadDir);
            $storage = $this->copyStorage($payloadDir, $excluded);

            File::put($payloadDir.'/manifest.json', json_encode([
                'format' => self::MANIFEST_FORMAT,
                'generator' => 'auth-system',
                'app_version' => Version::current(),
                'created_at' => now()->toIso8601String(),
                'system_name' => (string) SystemSetting::get('system_name', config('app.name')),
                'database' => $databaseManifest,
                'contents' => [
                    'env' => true,
                    'database' => true,
                    'storage_public' => $storage['public'],
                    'storage_private' => $storage['private'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $zipPath = $workDir.'/archive.zip';
            $this->zipDirectory($payloadDir, $zipPath);

            $archivePath = $workDir.'/'.$this->fileName();
            ArchiveCipher::encryptFile($zipPath, $archivePath, $password);

            File::deleteDirectory($payloadDir);
            File::delete($zipPath);

            Log::info('backup.create.completed', [
                'started_at' => $startedAt->toIso8601String(),
                'target_path' => $archivePath,
                'included_paths' => $storage['included_paths'],
                'excluded_paths' => $excluded,
                'size_bytes' => is_file($archivePath) ? filesize($archivePath) : null,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            return $archivePath;
        } catch (\Throwable $e) {
            File::deleteDirectory($workDir);

            Log::error('backup.create.failed', [
                'started_at' => $startedAt->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ]);

            throw $e instanceof BackupException
                ? $e
                : new BackupException('Die Sicherung konnte nicht erstellt werden: '.$e->getMessage());
        }
    }

    public function fileName(): string
    {
        $slug = Str::slug((string) SystemSetting::get('system_name', 'auth')) ?: 'auth';

        return $slug.'-sicherung-'.now()->format('Y-m-d-His').'.authbak';
    }

    private function copyEnv(string $payloadDir): void
    {
        $env = base_path('.env');

        if (! is_file($env)) {
            throw new BackupException('Es wurde keine .env-Datei gefunden, die gesichert werden könnte.');
        }

        File::copy($env, $payloadDir.'/env');
    }

    /**
     * @param  list<string>  $excluded  Absolute Pfade, die nie Teil des
     *                                  Sicherungsinhalts werden dürfen (siehe
     *                                  {@see BackupPaths::excludedFromBackupContent()}).
     * @return array{public: bool, private: bool, included_paths: list<string>}
     */
    private function copyStorage(string $payloadDir, array $excluded): array
    {
        $result = ['public' => false, 'private' => false, 'included_paths' => []];

        foreach (['public', 'private'] as $area) {
            $source = storage_path('app/'.$area);

            if (! is_dir($source)) {
                continue;
            }

            $this->copyDirectoryExcluding($source, $payloadDir.'/storage/app/'.$area, $excluded);
            $result[$area] = true;
            $result['included_paths'][] = $source;
        }

        return $result;
    }

    /**
     * Wie File::copyDirectory(), überspringt dabei aber jeden Pfad, der
     * innerhalb eines der übergebenen $excluded-Verzeichnisse liegt - und
     * steigt dort erst gar nicht hinein ab, statt nur einzelne Dateien zu
     * filtern. Das ist der eigentliche technische Schutz gegen eine
     * rekursive Sicherung: unabhängig davon, wo das konfigurierte
     * Sicherungsziel liegt, landet sein Inhalt nie im Payload.
     *
     * @param  list<string>  $excluded
     */
    private function copyDirectoryExcluding(string $source, string $destination, array $excluded): void
    {
        File::ensureDirectoryExists($destination);

        $isExcluded = function (string $absolutePath) use ($excluded): bool {
            foreach ($excluded as $root) {
                if (BackupPaths::isWithin($absolutePath, $root)) {
                    return true;
                }
            }

            return false;
        };

        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $current) => ! $isExcluded($current->getPathname())
        );

        $files = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
        $prefixLength = strlen($source) + 1;

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $target = $destination.'/'.substr($file->getPathname(), $prefixLength);

            if ($file->isDir()) {
                File::ensureDirectoryExists($target);
            } else {
                File::ensureDirectoryExists(dirname($target));
                File::copy($file->getPathname(), $target);
            }
        }
    }

    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Das Sicherungsarchiv konnte nicht angelegt werden.');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $prefixLength = strlen($sourceDir) + 1;

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), $prefixLength);
            $relative = str_replace('\\', '/', $relative);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($file->getPathname(), $relative);
            }
        }

        if (! $zip->close()) {
            throw new BackupException('Das Sicherungsarchiv konnte nicht geschrieben werden.');
        }
    }
}
