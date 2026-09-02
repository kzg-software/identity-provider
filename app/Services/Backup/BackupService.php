<?php

namespace App\Services\Backup;

use App\Models\SystemSetting;
use App\Support\Version;
use Illuminate\Support\Facades\File;
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

    public function __construct(private readonly DatabaseTransfer $database) {}

    /**
     * Baut die Sicherung und gibt den Pfad zur fertigen, verschlüsselten
     * Datei zurück. Der Aufrufer ist dafür verantwortlich, das umliegende
     * Arbeitsverzeichnis (dirname des Rückgabewerts) nach dem Versand wieder
     * zu löschen.
     */
    public function create(string $password): string
    {
        $workDir = storage_path('framework/backups/'.Str::uuid());
        $payloadDir = $workDir.'/payload';

        File::ensureDirectoryExists($payloadDir);

        try {
            $databaseManifest = $this->database->dump($payloadDir.'/database');

            $this->copyEnv($payloadDir);
            $storage = $this->copyStorage($payloadDir);

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

            return $archivePath;
        } catch (\Throwable $e) {
            File::deleteDirectory($workDir);

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
     * @return array{public: bool, private: bool}
     */
    private function copyStorage(string $payloadDir): array
    {
        $result = ['public' => false, 'private' => false];

        foreach (['public', 'private'] as $area) {
            $source = storage_path('app/'.$area);

            if (! is_dir($source)) {
                continue;
            }

            File::copyDirectory($source, $payloadDir.'/storage/app/'.$area);
            $result[$area] = true;
        }

        return $result;
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
