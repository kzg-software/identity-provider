<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Hintergrundbilder der Anmeldeseite.
 *
 * Es können mehrere Bilder hinterlegt werden; der Modus legt fest, welches
 * angezeigt wird:
 *
 *   fixed      immer das erste Bild der Liste
 *   random     bei jedem Aufruf ein zufälliges Bild
 *   daily      jeden Tag ein anderes Bild (für alle gleich)
 *   slideshow  die Bilder wechseln sich mit Überblendung ab
 *
 * Gespeichert wird die Liste als JSON unter "login_backgrounds". Ältere
 * Installationen hatten genau ein Bild unter "login_background_path"; das
 * wird beim Lesen übernommen und beim ersten Schreiben in die Liste
 * überführt.
 */
class LoginBackgrounds
{
    public const MAX_IMAGES = 20;

    public const MODES = ['fixed', 'random', 'daily', 'slideshow'];

    public const DEFAULT_INTERVAL = 12;

    public const DEFAULT_OVERLAY = 40;

    /** @return list<string> Pfade auf der public-Disk */
    public static function paths(): array
    {
        $list = json_decode((string) SystemSetting::get('login_backgrounds', ''), true);

        if (is_array($list)) {
            return array_values(array_filter($list, fn ($p) => is_string($p) && $p !== ''));
        }

        $legacy = (string) SystemSetting::get('login_background_path', '');

        return $legacy !== '' ? [$legacy] : [];
    }

    public static function add(string $path): void
    {
        $paths = self::paths();
        $paths[] = $path;

        self::store($paths);
    }

    /** Entfernt ein Bild (anhand des Dateinamens) und löscht die Datei. */
    public static function remove(string $file): bool
    {
        $paths = self::paths();
        $match = self::find($paths, $file);

        if ($match === null) {
            return false;
        }

        Storage::disk('public')->delete($match);
        self::store(array_values(array_filter($paths, fn ($p) => $p !== $match)));

        return true;
    }

    /** Stellt ein Bild an den Anfang der Liste (= Bild für den Modus "fixed"). */
    public static function promote(string $file): bool
    {
        $paths = self::paths();
        $match = self::find($paths, $file);

        if ($match === null) {
            return false;
        }

        self::store([$match, ...array_values(array_filter($paths, fn ($p) => $p !== $match))]);

        return true;
    }

    public static function removeAll(): void
    {
        foreach (self::paths() as $path) {
            Storage::disk('public')->delete($path);
        }

        self::store([]);
    }

    public static function mode(): string
    {
        $mode = (string) SystemSetting::get('login_background_mode', 'fixed');

        return in_array($mode, self::MODES, true) ? $mode : 'fixed';
    }

    public static function interval(): int
    {
        $seconds = (int) SystemSetting::get('login_background_interval', self::DEFAULT_INTERVAL);

        return max(3, min(600, $seconds ?: self::DEFAULT_INTERVAL));
    }

    /** Abdunklung über dem Bild in Prozent (0 = keine). */
    public static function overlay(): int
    {
        $value = SystemSetting::get('login_background_overlay');

        if ($value === null || $value === '') {
            return self::DEFAULT_OVERLAY;
        }

        return max(0, min(90, (int) $value));
    }

    /**
     * Alles, was die Anmeldeseite zum Zeichnen braucht.
     *
     * @return array{urls: list<string>, mode: string, interval: int, overlay: int}
     */
    public static function forDisplay(): array
    {
        $urls = array_map(fn (string $p) => Storage::disk('public')->url($p), self::paths());
        $mode = self::mode();

        if (count($urls) > 1) {
            $urls = match ($mode) {
                'random' => [$urls[array_rand($urls)]],
                'daily' => [$urls[((int) now()->format('z') + (int) now()->format('Y')) % count($urls)]],
                'slideshow' => $urls,
                default => [$urls[0]],
            };
        }

        return [
            'urls' => $urls,
            'mode' => count($urls) > 1 ? 'slideshow' : $mode,
            'interval' => self::interval(),
            'overlay' => self::overlay(),
        ];
    }

    /** @param list<string> $paths */
    private static function find(array $paths, string $file): ?string
    {
        foreach ($paths as $path) {
            if (basename($path) === $file) {
                return $path;
            }
        }

        return null;
    }

    /** @param list<string> $paths */
    private static function store(array $paths): void
    {
        SystemSetting::set('login_backgrounds', json_encode(array_values($paths)));
        // Altes Einzelbild-Feld: das erste Bild, damit bestehende Auswertungen
        // (z. B. Datensicherung, Übersicht) weiter stimmen.
        SystemSetting::set('login_background_path', $paths[0] ?? '');
    }
}
