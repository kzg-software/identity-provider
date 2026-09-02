<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Spielt eine mit {@see BackupService} erstellte Sicherung wieder ein.
 *
 * Reihenfolge: Archiv entschlüsseln und entpacken, Prüfsummen kontrollieren,
 * aktuelle .env und Datenbank als Rücksicherung wegkopieren, dann .env,
 * Datenbank und Dateien zurückschreiben und zum Schluss offene Migrationen
 * nachziehen. Schlägt ein Schritt fehl, wird der Ausgangszustand aus der
 * Rücksicherung wiederhergestellt.
 */
class RestoreService
{
    public function __construct(private readonly DatabaseTransfer $database) {}

    /**
     * Prüft eine hochgeladene Sicherungsdatei und gibt ihr Manifest zurück,
     * ohne etwas am System zu verändern. Wirft eine {@see BackupException}
     * bei falschem Passwort oder ungültiger Datei.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $archivePath, string $password): array
    {
        $workDir = $this->makeWorkDir();

        try {
            return $this->extract($archivePath, $password, $workDir)['manifest'];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Führt die vollständige Wiederherstellung durch und gibt das Manifest
     * der eingespielten Sicherung zurück.
     *
     * @return array<string, mixed>
     */
    public function restore(string $archivePath, string $password): array
    {
        $workDir = $this->makeWorkDir();
        $safetyDir = storage_path('framework/backups/vor-wiederherstellung-'.now()->format('Y-m-d-His'));

        try {
            ['manifest' => $manifest, 'payload' => $payloadDir] = $this->extract($archivePath, $password, $workDir);

            File::ensureDirectoryExists($safetyDir);
            $this->saveSafetyCopy($safetyDir);

            try {
                $this->restoreEnv($payloadDir);
                $this->reloadDatabaseConfig($payloadDir.'/env');
                $this->database->restore($payloadDir.'/database', $manifest['database'] ?? []);
                $this->restoreStorage($payloadDir, $manifest);
                $this->refreshCaches();
                Artisan::call('migrate', ['--force' => true]);
            } catch (\Throwable $e) {
                $this->rollBack($safetyDir);

                throw $e instanceof BackupException
                    ? $e
                    : new BackupException('Die Wiederherstellung ist fehlgeschlagen und wurde zurückgenommen: '.$e->getMessage());
            }

            return $manifest;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, payload: string}
     */
    private function extract(string $archivePath, string $password, string $workDir): array
    {
        $zipPath = $workDir.'/archive.zip';
        $payloadDir = $workDir.'/payload';

        ArchiveCipher::decryptFile($archivePath, $zipPath, $password);

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new BackupException('Die entschlüsselte Sicherung konnte nicht entpackt werden.');
        }

        File::ensureDirectoryExists($payloadDir);
        $zip->extractTo($payloadDir);
        $zip->close();

        $manifestFile = $payloadDir.'/manifest.json';

        if (! is_file($manifestFile)) {
            throw new BackupException('Die Sicherung enthält kein Manifest und stammt vermutlich nicht von diesem System.');
        }

        $manifest = json_decode(File::get($manifestFile), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::MANIFEST_FORMAT) {
            throw new BackupException('Das Format dieser Sicherung wird von der installierten Version nicht unterstützt.');
        }

        if (! is_file($payloadDir.'/env')) {
            throw new BackupException('In der Sicherung fehlt die Konfigurationsdatei.');
        }

        return ['manifest' => $manifest, 'payload' => $payloadDir];
    }

    private function makeWorkDir(): string
    {
        $dir = storage_path('framework/backups/restore-'.Str::uuid());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function saveSafetyCopy(string $safetyDir): void
    {
        if (is_file(base_path('.env'))) {
            File::copy(base_path('.env'), $safetyDir.'/env');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $path = DB::connection()->getConfig('database');

            if (is_string($path) && is_file($path)) {
                File::copy($path, $safetyDir.'/database.sqlite');
            }
        }
    }

    private function rollBack(string $safetyDir): void
    {
        if (is_file($safetyDir.'/env')) {
            @copy($safetyDir.'/env', base_path('.env'));
            $this->reloadDatabaseConfig($safetyDir.'/env');
        }

        if (is_file($safetyDir.'/database.sqlite')) {
            $path = DB::connection()->getConfig('database');

            if (is_string($path)) {
                DB::disconnect();
                @copy($safetyDir.'/database.sqlite', $path);
                DB::reconnect();
            }
        }

        $this->refreshCaches();
    }

    private function restoreEnv(string $payloadDir): void
    {
        if (! @copy($payloadDir.'/env', base_path('.env'))) {
            throw new BackupException('Die Konfigurationsdatei konnte nicht zurückgeschrieben werden.');
        }
    }

    /**
     * Liest die Datenbank-Zugangsdaten aus der wiederhergestellten .env und
     * legt sie sofort auf die laufende Konfiguration, damit die folgenden
     * Schritte gegen die richtige Datenbank arbeiten.
     */
    private function reloadDatabaseConfig(string $envFile): void
    {
        $values = $this->parseEnv($envFile);

        $connection = $values['DB_CONNECTION'] ?? config('database.default');

        if ($connection === 'sqlite') {
            $database = $values['DB_DATABASE'] ?? config('database.connections.sqlite.database');

            if (is_string($database) && $database !== ':memory:' && ! Str::contains($database, ['/', '\\'])) {
                $database = database_path($database);
            }

            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $database,
            ]);
        } else {
            $prefix = "database.connections.{$connection}";

            config([
                'database.default' => $connection,
                "{$prefix}.driver" => $connection === 'mariadb' ? 'mariadb' : $connection,
                "{$prefix}.host" => $values['DB_HOST'] ?? '127.0.0.1',
                "{$prefix}.port" => $values['DB_PORT'] ?? '3306',
                "{$prefix}.database" => $values['DB_DATABASE'] ?? '',
                "{$prefix}.username" => $values['DB_USERNAME'] ?? '',
                "{$prefix}.password" => $values['DB_PASSWORD'] ?? '',
            ]);
        }

        DB::purge($connection);
        DB::setDefaultConnection($connection);
        DB::reconnect($connection);
    }

    /**
     * @return array<string, string>
     */
    private function parseEnv(string $file): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) File::get($file)) as $line) {
            $line = trim($line);

            if ($line === '' || Str::startsWith($line, '#') || ! Str::contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function restoreStorage(string $payloadDir, array $manifest): void
    {
        foreach (['public', 'private'] as $area) {
            $source = $payloadDir.'/storage/app/'.$area;

            if (! is_dir($source)) {
                continue;
            }

            $target = storage_path('app/'.$area);

            File::deleteDirectory($target);
            File::ensureDirectoryExists($target);
            File::copyDirectory($source, $target);
        }
    }

    private function refreshCaches(): void
    {
        foreach (['config:clear', 'cache:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // Ein nicht leerbarer Cache darf die Wiederherstellung nicht stoppen.
            }
        }
    }
}
