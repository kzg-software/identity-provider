<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Die Sprachen, für die Übersetzungen vorliegen (ein Verzeichnis unter lang/).
 * Deutsch ist immer dabei, weitere erscheinen automatisch, sobald ein
 * passendes lang/<code>-Verzeichnis hinzugefügt wird.
 */
class Locales
{
    private const NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt' => 'Português',
        'ru' => 'Русский',
        'tr' => 'Türkçe',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'nb' => 'Norsk',
        'fi' => 'Suomi',
        'uk' => 'Українська',
    ];

    /**
     * @return array<string, string> Sprachcode => Anzeigename, nach Name sortiert.
     */
    public static function available(): array
    {
        $codes = ['de'];

        if (File::isDirectory(lang_path())) {
            foreach (File::directories(lang_path()) as $dir) {
                $codes[] = basename($dir);
            }
        }

        $codes = array_values(array_unique($codes));

        $map = [];
        foreach ($codes as $code) {
            $map[$code] = self::NAMES[$code] ?? $code;
        }

        asort($map, SORT_NATURAL | SORT_FLAG_CASE);

        return $map;
    }

    public static function isAvailable(string $code): bool
    {
        return array_key_exists($code, self::available());
    }

    public static function name(string $code): string
    {
        return self::available()[$code] ?? (self::NAMES[$code] ?? $code);
    }
}
