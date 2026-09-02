<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Sichert den kompletten Datenbankinhalt in ein Verzeichnis und spielt ihn
 * von dort wieder ein.
 *
 *  * SQLite: die Datenbankdatei wird 1:1 kopiert (über "VACUUM INTO", damit
 *    die Kopie in sich konsistent ist).
 *  * MySQL / MariaDB: jede Tabelle wird mit Struktur und allen Zeilen in eine
 *    JSON-Datei geschrieben und beim Wiederherstellen Zeile für Zeile neu
 *    aufgebaut. Binärwerte werden dabei Base64-kodiert.
 *
 * Wiederhergestellt wird immer in die aktuell konfigurierte Standard-
 * verbindung. Beim Restore ist das bereits die Verbindung aus der
 * wiederhergestellten .env.
 */
class DatabaseTransfer
{
    private const DUMP_FILE = 'database.json';

    private const SQLITE_FILE = 'database.sqlite';

    /**
     * @return array{driver: string, database?: string, tables?: int}
     */
    public function dump(string $targetDir): array
    {
        File::ensureDirectoryExists($targetDir);

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return $this->dumpSqlite($targetDir);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->dumpSql($targetDir, $driver);
        }

        throw new BackupException("Datenbanktreiber \"{$driver}\" wird für Sicherungen nicht unterstützt (nur SQLite, MySQL und MariaDB).");
    }

    /**
     * @param  array{driver: string}  $dbManifest
     */
    public function restore(string $sourceDir, array $dbManifest): void
    {
        $sourceDriver = $dbManifest['driver'] ?? 'unbekannt';
        $targetDriver = DB::connection()->getDriverName();

        $targetDriver = $targetDriver === 'mariadb' ? 'mysql' : $targetDriver;
        $normalisedSource = $sourceDriver === 'mariadb' ? 'mysql' : $sourceDriver;

        if ($normalisedSource !== $targetDriver) {
            throw new BackupException(
                "Die Sicherung wurde mit \"{$sourceDriver}\" erstellt, dieses System nutzt \"{$targetDriver}\". ".
                'Ein Wechsel des Datenbanktyps über eine Sicherung ist nicht möglich.'
            );
        }

        if ($targetDriver === 'sqlite') {
            $this->restoreSqlite($sourceDir);

            return;
        }

        $this->restoreSql($sourceDir);
    }

    private function dumpSqlite(string $targetDir): array
    {
        $path = DB::connection()->getConfig('database');

        if ($path === ':memory:' || ! is_string($path)) {
            throw new BackupException('Eine In-Memory-SQLite-Datenbank kann nicht gesichert werden.');
        }

        $target = $targetDir.'/'.self::SQLITE_FILE;

        if (is_file($target)) {
            unlink($target);
        }

        // VACUUM INTO schreibt eine konsistente, kompakte Kopie, auch während
        // parallel Schreibzugriffe laufen.
        DB::statement('VACUUM INTO ?', [$target]);

        if (! is_file($target)) {
            throw new BackupException('Die SQLite-Datenbank konnte nicht kopiert werden.');
        }

        return [
            'driver' => 'sqlite',
            'database' => basename($path),
            'tables' => count(Schema::getTableListing(schemaQualified: false)),
        ];
    }

    private function restoreSqlite(string $sourceDir): void
    {
        $source = $sourceDir.'/'.self::SQLITE_FILE;

        if (! is_file($source)) {
            throw new BackupException('In der Sicherung fehlt die SQLite-Datenbankdatei.');
        }

        $target = DB::connection()->getConfig('database');

        if ($target === ':memory:' || ! is_string($target)) {
            throw new BackupException('Dieses System nutzt eine In-Memory-Datenbank und kann nicht wiederhergestellt werden.');
        }

        DB::disconnect();

        File::ensureDirectoryExists(dirname($target));

        if (! @copy($source, $target)) {
            throw new BackupException('Die Datenbankdatei konnte nicht zurückgeschrieben werden.');
        }

        DB::reconnect();
    }

    private function dumpSql(string $targetDir, string $driver): array
    {
        $tables = Schema::getTableListing(schemaQualified: false);
        $payload = [
            'driver' => $driver,
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $createRow = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $create = $createRow['Create Table'] ?? $createRow['Create View'] ?? null;

            $rows = [];
            DB::table($table)->orderBy(
                Schema::getColumnListing($table)[0] ?? DB::raw('1')
            )->chunk(1000, function ($chunk) use (&$rows) {
                foreach ($chunk as $row) {
                    $rows[] = $this->encodeRow((array) $row);
                }
            });

            $payload['tables'][] = [
                'name' => $table,
                'create' => $create,
                'rows' => $rows,
            ];
        }

        File::put($targetDir.'/'.self::DUMP_FILE, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'driver' => $driver,
            'tables' => count($tables),
        ];
    }

    private function restoreSql(string $sourceDir): void
    {
        $file = $sourceDir.'/'.self::DUMP_FILE;

        if (! is_file($file)) {
            throw new BackupException('In der Sicherung fehlt der Datenbank-Export.');
        }

        $payload = json_decode(File::get($file), true);

        if (! is_array($payload) || ! isset($payload['tables']) || ! is_array($payload['tables'])) {
            throw new BackupException('Der Datenbank-Export in der Sicherung ist beschädigt.');
        }

        DB::transaction(function () use ($payload) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($payload['tables'] as $table) {
                    $name = $table['name'];
                    DB::statement("DROP TABLE IF EXISTS `{$name}`");

                    if (! empty($table['create'])) {
                        DB::statement($table['create']);
                    }

                    $rows = $table['rows'] ?? [];

                    foreach (array_chunk($rows, 500) as $batch) {
                        $decoded = array_map(fn ($row) => $this->decodeRow($row), $batch);
                        DB::table($name)->insert($decoded);
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encodeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $row[$key] = ['__b64__' => base64_encode($value)];
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_array($value) && isset($value['__b64__'])) {
                $row[$key] = base64_decode($value['__b64__']);
            }
        }

        return $row;
    }
}
