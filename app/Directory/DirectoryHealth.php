<?php

namespace App\Directory;

use App\Models\Directory;
use Illuminate\Support\Str;

/**
 * Zustand eines Verzeichnisses für den Statuspunkt in der Verzeichnisliste,
 * abgeleitet aus dem letzten Synchronisierungslauf.
 */
class DirectoryHealth
{
    /** @return array{color: string, title: string, reason: string} */
    public static function for(Directory $directory): array
    {
        if (! $directory->is_active) {
            return self::state('gray', 'Inaktiv',
                'Das Verzeichnis ist deaktiviert und wird weder für Anmeldungen noch für die Synchronisierung genutzt.');
        }

        if ($directory->last_sync_at === null) {
            return self::state('amber', 'Noch nicht synchronisiert',
                'Es lief noch keine Synchronisierung. Benutzer und Gruppen fehlen, bis der erste Lauf durch ist.');
        }

        $when = $directory->last_sync_at->diffForHumans();

        if ($directory->last_sync_error) {
            return self::state('red', 'Fehler bei der Synchronisierung',
                self::explain($directory->last_sync_error)." (Letzter Versuch {$when}.)");
        }

        $maxAgeMinutes = $directory->deltaSyncEnabled() ? 60 : 26 * 60;

        if ($directory->last_sync_at->lt(now()->subMinutes($maxAgeMinutes))) {
            return self::state('amber', 'Synchronisierung überfällig',
                "Der letzte erfolgreiche Lauf war {$when}. Läuft der Aufgabenplaner (schedule:run)?");
        }

        return self::state('green', 'Verbindung in Ordnung',
            "Letzte Synchronisierung {$when} erfolgreich: "
            .($directory->last_sync_user_count ?? 0).' Benutzer, '
            .($directory->last_sync_group_count ?? 0).' Gruppen.');
    }

    private static function explain(string $error): string
    {
        $hints = [
            "can't contact ldap server" => 'Der Server ist nicht erreichbar. Adresse, Port, Firewall und bei LDAPS das Zertifikat prüfen.',
            'invalid credentials' => 'Die Anmeldung am Verzeichnis ist fehlgeschlagen. Bind-Benutzer und Passwort prüfen.',
            'invalid dn syntax' => 'Ein DN (Base, Benutzer oder Gruppen) ist ungültig.',
            'no such object' => 'Ein DN (Base, Benutzer oder Gruppen) existiert im Verzeichnis nicht.',
            'timed out' => 'Der Server antwortet nicht rechtzeitig.',
            'certificate' => 'Das Zertifikat des Servers wird nicht akzeptiert.',
        ];

        $message = Str::limit(trim($error), 200);

        foreach ($hints as $needle => $hint) {
            if (str_contains(strtolower($error), $needle)) {
                return "{$hint} Meldung: {$message}";
            }
        }

        return $message;
    }

    private static function state(string $color, string $title, string $reason): array
    {
        return ['color' => $color, 'title' => $title, 'reason' => $reason];
    }
}
