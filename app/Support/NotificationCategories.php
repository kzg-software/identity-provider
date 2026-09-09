<?php

namespace App\Support;

/**
 * Kategorien für Benachrichtigungen und ihre E-Mail-Einstellbarkeit.
 *
 * "locked" heißt: geht immer per E-Mail und an die Glocke, unabhängig von
 * der Nutzereinstellung (sicherheitsrelevant). Alle anderen kann der Nutzer
 * pro Kategorie ab- und anschalten. "admin" heißt: nur für Administratoren
 * relevant und wird sonst nicht angezeigt.
 */
class NotificationCategories
{
    /**
     * @return array<string, array{label: string, description: string, locked: bool, admin: bool}>
     */
    public static function all(): array
    {
        return [
            'account_security' => [
                'label' => 'Konto-Sicherheit',
                'description' => 'Passwort geändert, Passkeys, Zwei-Faktor, verdächtige Aktivität.',
                'locked' => true,
                'admin' => false,
            ],
            'updates' => [
                'label' => 'Software-Updates',
                'description' => 'Wenn eine neue Version verfügbar ist.',
                'locked' => false,
                'admin' => true,
            ],
            'certificates' => [
                'label' => 'Zertifikate und Schlüssel',
                'description' => 'Wenn ein SAML-Zertifikat oder OIDC-Schlüssel abläuft oder fehlt.',
                'locked' => false,
                'admin' => true,
            ],
            'backup' => [
                'label' => 'Datensicherung',
                'description' => 'Wenn eine automatische Sicherung fehlschlägt.',
                'locked' => false,
                'admin' => true,
            ],
            'scheduler' => [
                'label' => 'Aufgabenplaner',
                'description' => 'Wenn der Aufgabenplaner keine Lebenszeichen mehr sendet.',
                'locked' => false,
                'admin' => true,
            ],
            'directories' => [
                'label' => 'Verzeichnisse',
                'description' => 'Wenn ein verbundenes Verzeichnis nicht erreichbar ist.',
                'locked' => false,
                'admin' => true,
            ],
            'login_alerts' => [
                'label' => 'Anmeldeversuche',
                'description' => 'Bei auffällig vielen fehlgeschlagenen Anmeldungen.',
                'locked' => false,
                'admin' => true,
            ],
        ];
    }

    /** @return array<int, string> Schlüssel der einstellbaren (nicht gesperrten) Kategorien. */
    public static function adjustableKeys(): array
    {
        return array_keys(array_filter(self::all(), fn ($c) => ! $c['locked']));
    }

    public static function isLocked(string $key): bool
    {
        return self::all()[$key]['locked'] ?? false;
    }

    /** Ordnet einen Benachrichtigungstyp ("system.update", "security.change") einer Kategorie zu. */
    public static function forType(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'security.') => 'account_security',
            $type === 'system.update' => 'updates',
            $type === 'system.expiry' => 'certificates',
            $type === 'system.backup' => 'backup',
            $type === 'system.scheduler' => 'scheduler',
            $type === 'system.directory' => 'directories',
            $type === 'system.logins' => 'login_alerts',
            default => 'other',
        };
    }
}
