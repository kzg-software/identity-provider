<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

/**
 * Liest die in den Systemeinstellungen konfigurierbaren Sicherheitsregeln:
 * die Passwort-Richtlinie für lokale Konten und die Grenzen für fehlgeschlagene
 * Anmeldeversuche.
 */
class SecuritySettings
{
    public static function passwordMinLength(): int
    {
        return max(6, min(128, (int) SystemSetting::get('password_min_length', 10) ?: 10));
    }

    /**
     * Die Laravel-Passwortregel gemäß der aktuellen Richtlinie. Wird überall
     * verwendet, wo ein lokales Passwort gesetzt wird.
     */
    public static function passwordRule(): Password
    {
        $rule = Password::min(self::passwordMinLength());

        if (SystemSetting::bool('password_require_mixed_case')) {
            $rule->mixedCase();
        }

        if (SystemSetting::bool('password_require_number')) {
            $rule->numbers();
        }

        if (SystemSetting::bool('password_require_symbol')) {
            $rule->symbols();
        }

        if (SystemSetting::bool('password_check_pwned')) {
            // Prüft über die freie Pwned-Passwords-Range-API (k-Anonymität,
            // kein API-Schlüssel nötig). Ist die API nicht erreichbar, wird
            // das Passwort durchgelassen.
            $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * Ein Satz, der die aktuelle Richtlinie für Benutzer beschreibt.
     */
    public static function passwordHint(): string
    {
        $parts = ['mindestens '.self::passwordMinLength().' Zeichen'];

        if (SystemSetting::bool('password_require_mixed_case')) {
            $parts[] = 'Groß- und Kleinbuchstaben';
        }

        if (SystemSetting::bool('password_require_number')) {
            $parts[] = 'eine Ziffer';
        }

        if (SystemSetting::bool('password_require_symbol')) {
            $parts[] = 'ein Sonderzeichen';
        }

        $hint = 'Erforderlich: '.implode(', ', $parts).'.';

        if (SystemSetting::bool('password_check_pwned')) {
            $hint .= ' Passwörter aus bekannten Datenlecks werden abgelehnt.';
        }

        return $hint;
    }

    /**
     * Ist die passwortlose Anmeldung mit Passkeys für lokale Konten aktiv?
     * Standard: an.
     */
    public static function passwordlessLocalEnabled(): bool
    {
        return SystemSetting::bool('passkey_passwordless_local_enabled', true);
    }

    /**
     * Ist die passwortlose Anmeldung mit Passkeys für Active-Directory-Konten
     * aktiv? Standard: aus – sie umgeht sonst das AD-Passwort.
     */
    public static function passwordlessAdEnabled(): bool
    {
        return SystemSetting::bool('passkey_passwordless_ad_enabled', false);
    }

    public static function passwordlessAnyEnabled(): bool
    {
        return self::passwordlessLocalEnabled() || self::passwordlessAdEnabled();
    }

    public static function passwordlessEnabledFor(User $user): bool
    {
        return $user->isLocal()
            ? self::passwordlessLocalEnabled()
            : self::passwordlessAdEnabled();
    }

    public static function loginMaxAttempts(): int
    {
        return max(3, min(100, (int) SystemSetting::get('login_max_attempts', 5) ?: 5));
    }

    public static function loginLockoutSeconds(): int
    {
        $minutes = max(1, min(1440, (int) SystemSetting::get('login_lockout_minutes', 1) ?: 1));

        return $minutes * 60;
    }
}
