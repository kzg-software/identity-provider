<?php

namespace App\Support;

/**
 * Ermittelt und normalisiert den aktuellen Release-Stand der Anwendung.
 *
 * Quellen in dieser Reihenfolge:
 *   1. config('app.version')  (ENV APP_VERSION – vom Docker-Build gesetzt)
 *   2. Datei VERSION im Projekt-Root (von deploy/install.sh + update.sh)
 *   3. "dev"
 */
class Version
{
    public static function current(): string
    {
        $configured = trim((string) config('app.version'));
        if ($configured !== '') {
            return self::normalize($configured);
        }

        $file = base_path('VERSION');
        if (is_file($file)) {
            $fromFile = trim((string) @file_get_contents($file));
            if ($fromFile !== '') {
                return self::normalize($fromFile);
            }
        }

        return 'dev';
    }

    /**
     * Semantische Version ohne führendes "v" – oder null, wenn der aktuelle
     * Stand keine Versionsnummer ist (z. B. "dev", "main").
     */
    public static function currentSemver(): ?string
    {
        return self::toSemver(self::current());
    }

    public static function isRelease(): bool
    {
        return self::currentSemver() !== null;
    }

    /**
     * "1.4.2" -> "v1.4.2"; "dev"/"main"/"dev-abc123" bleiben unverändert.
     */
    public static function normalize(string $version): string
    {
        $version = trim($version);

        if (preg_match('/^\d+\.\d+/', $version)) {
            return 'v'.$version;
        }

        return $version;
    }

    public static function toSemver(string $version): ?string
    {
        if (preg_match('/^v?(\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?)$/', trim($version), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Ist $candidate neuer als $current? Nicht-Versionen ("dev") ergeben false.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $a = self::toSemver($candidate);
        $b = self::toSemver($current);

        if ($a === null || $b === null) {
            return false;
        }

        return version_compare($a, $b, '>');
    }
}
