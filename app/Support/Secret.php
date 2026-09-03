<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Verschlüsselt Geheimnisse (z. B. Zugangsdaten für Sicherungsziele), bevor
 * sie in system_settings landen, und entschlüsselt sie beim Lesen. Nutzt den
 * APP_KEY über Laravels Crypt. Ein leerer Wert bleibt leer.
 */
class Secret
{
    public static function encrypt(?string $plain): string
    {
        $plain = (string) $plain;

        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    public static function decrypt(?string $stored): string
    {
        $stored = (string) $stored;

        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }
}
