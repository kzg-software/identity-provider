<?php

namespace App\Support;

use App\Models\Application;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Wartungsmodus - zwei Ebenen:
 *
 *  1. Systemweit  (system_settings: maintenance_mode / _message / _allow)
 *     -> das gesamte Portal ist gesperrt, durchgesetzt in
 *        App\Http\Middleware\EnsureNotInMaintenance.
 *
 *  2. Pro Anwendung (applications.maintenance_mode / _message / _allow)
 *     -> nur die betroffene Anwendung ist gesperrt, durchgesetzt an den
 *        SSO-Einsprungpunkten (OIDC-Authorize, SAML-SSO) und sichtbar auf
 *        dem Benutzer-Dashboard.
 *
 * "Wer trotzdem rein darf": lokale Administratoren immer, plus jeder Eintrag
 * der Freigabeliste (eine Zeile je Benutzername oder @Gruppenname).
 */
class MaintenanceGate
{
    // ---- System -----------------------------------------------------------

    public static function systemActive(): bool
    {
        return SystemSetting::get('maintenance_mode') === '1';
    }

    public static function systemMessage(): string
    {
        $message = trim((string) SystemSetting::get('maintenance_message'));

        return $message !== ''
            ? $message
            : 'Das System wird zurzeit gewartet. Bitte versuchen Sie es später erneut.';
    }

    public static function userBypassesSystem(?User $user): bool
    {
        return static::userIsExempt($user, (string) SystemSetting::get('maintenance_allow'));
    }

    // ---- Pro Anwendung ---------------------------------------------------

    public static function applicationActive(?Application $application): bool
    {
        return (bool) $application?->maintenance_mode;
    }

    public static function applicationMessage(Application $application): string
    {
        $message = trim((string) $application->maintenance_message);

        return $message !== ''
            ? $message
            : 'Die Anwendung „'.$application->name.'" wird zurzeit gewartet. Bitte versuchen Sie es später erneut.';
    }

    public static function userBypassesApplication(Application $application, ?User $user): bool
    {
        return static::userIsExempt($user, (string) $application->maintenance_allow);
    }

    /**
     * True, wenn die Anwendung für diesen Benutzer gerade nicht erreichbar ist.
     */
    public static function applicationBlockedFor(?Application $application, ?User $user): bool
    {
        return $application !== null
            && static::applicationActive($application)
            && ! static::userBypassesApplication($application, $user);
    }

    // ---- Freigabe-Auswertung -------------------------------------------

    private static function userIsExempt(?User $user, string $allowList): bool
    {
        if (! $user) {
            return false;
        }

        // Administratoren müssen immer durchkommen - sonst könnte niemand den
        // Wartungsmodus wieder abschalten.
        if ($user->is_admin) {
            return true;
        }

        $entries = collect(preg_split('/\r\n|\r|\n/', $allowList))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->all();

        if ($entries === []) {
            return false;
        }

        $groups = array_map('mb_strtolower', $user->groupNames());
        $identifiers = array_map(
            'mb_strtolower',
            array_filter([$user->username, $user->upn, $user->sam_account_name, $user->email])
        );

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '@')) {
                if (in_array(mb_strtolower(substr($entry, 1)), $groups, true)) {
                    return true;
                }

                continue;
            }

            $needle = mb_strtolower($entry);

            if (in_array($needle, $identifiers, true) || in_array($needle, $groups, true)) {
                return true;
            }
        }

        return false;
    }
}
