<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;

/**
 * Zentrale Stelle für alles, was mit "was wird gesichert" und "was darf
 * niemals Teil einer Sicherung sein" zu tun hat.
 *
 * Der Auslöser für diese Klasse: eine Sicherung, deren lokales Ziel
 * (storage/app/private/backups) selbst innerhalb des gesicherten Bereichs
 * (storage/app/private) liegt, sichert bei jedem Lauf zusätzlich alle
 * bisherigen Sicherungsdateien mit ein - die Größe verdoppelt sich dadurch
 * bei jedem Lauf. Die Prüfungen hier greifen unabhängig davon, wie das
 * Sicherungsziel konfiguriert ist, damit dieser Fehler technisch
 * ausgeschlossen ist und nicht erneut auftreten kann.
 */
class BackupPaths
{
    /**
     * Verzeichnisse, deren Inhalt in eine Sicherung aufgenommen wird.
     *
     * @return list<string>
     */
    public static function sourceRoots(): array
    {
        return array_values(array_filter([
            storage_path('app/public'),
            storage_path('app/private'),
        ], 'is_dir'));
    }

    /** Arbeitsverzeichnis für temporäre Sicherungs-/Wiederherstellungsdateien. */
    public static function workRoot(): string
    {
        return storage_path('framework/backups');
    }

    /**
     * Pfade, die beim Einsammeln des Sicherungsinhalts grundsätzlich
     * übersprungen werden - unabhängig vom aktuell konfigurierten Ziel. Legacy-
     * Namen bleiben bewusst enthalten, damit ein Sicherungsordner, der aus der
     * Zeit vor dieser Korrektur noch unter storage/app/... liegt, nicht erneut
     * mitgesichert wird.
     *
     * @return list<string>
     */
    public static function excludedFromBackupContent(): array
    {
        $excluded = [
            storage_path('app/private/backups'),
            storage_path('app/public/backups'),
            self::workRoot(),
        ];

        try {
            $destination = app(BackupDestination::class);

            if ($destination->target() === 'local') {
                $excluded[] = $destination->localRoot();
            }
        } catch (\Throwable) {
            // Einstellungen evtl. noch nicht verfügbar (z. B. während der Installation).
        }

        return $excluded;
    }

    /** True, wenn $path identisch mit $base ist oder darunter liegt. */
    public static function isWithin(string $path, string $base): bool
    {
        $path = self::normalize($path);
        $base = self::normalize($base);

        if ($base === '') {
            return false;
        }

        return $path === $base || str_starts_with($path.'/', $base.'/');
    }

    /**
     * Stellt sicher, dass ein lokales Sicherungsziel sich nicht mit einem zu
     * sichernden Quellpfad oder dem Arbeitsverzeichnis überschneidet - in
     * keiner Richtung. Wirft eine BackupException, wenn nicht sicher.
     */
    public static function assertDestinationIsSafe(string $destinationDir): void
    {
        foreach (array_merge(self::sourceRoots(), [self::workRoot()]) as $source) {
            if (self::isWithin($destinationDir, $source) || self::isWithin($source, $destinationDir)) {
                throw new BackupException(
                    'Das Sicherungsziel ('.$destinationDir.') überschneidet sich mit einem zu sichernden '.
                    'oder temporären Verzeichnis ('.$source.'). Sicherung abgebrochen, um eine rekursive '.
                    'Verdopplung der Sicherungen zu verhindern. Bitte ein Sicherungsziel außerhalb von '.
                    storage_path('app').' wählen.'
                );
            }
        }
    }

    /**
     * Bricht mit einer BackupException ab, wenn am Zielpfad nicht mindestens
     * $reserveBytes frei sind (Standard: 2 GB Sicherheitsreserve).
     */
    public static function assertEnoughFreeSpace(string $checkPath, ?int $reserveBytes = null): void
    {
        $reserveBytes ??= (int) config('backup.min_free_bytes', 2 * 1024 * 1024 * 1024);

        $dir = is_dir($checkPath) ? $checkPath : dirname($checkPath);
        File::ensureDirectoryExists($dir);

        $free = @disk_free_space($dir);

        if ($free !== false && $free < $reserveBytes) {
            throw new BackupException(sprintf(
                'Nicht genügend freier Speicherplatz für eine Sicherung: %s frei, mindestens %s erforderlich (Sicherheitsreserve). Sicherung abgebrochen.',
                self::humanBytes((int) $free),
                self::humanBytes($reserveBytes),
            ));
        }
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max($bytes, 0);

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === end($units)) {
                return round($value, 1).' '.$unit;
            }

            $value /= 1024;
        }

        return $bytes.' B';
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
