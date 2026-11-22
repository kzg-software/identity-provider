<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Eigenes CSS aus den Systemeinstellungen.
 *
 * Das CSS wird in einen <style>-Block der Seiten geschrieben. Damit daraus
 * kein HTML/JavaScript werden kann, sind spitze Klammern grundsätzlich
 * verboten (ein "</style>" würde den Block sonst beenden). Für Selektoren
 * wie "a > b" lässt sich ein Leerzeichen-Kombinator oder :is() nutzen.
 *
 * Geltungsbereich:
 *   login   nur Anmeldeseite (inkl. Zwei-Faktor, Passwort vergessen, Workflow-Seiten)
 *   portal  Anmeldeseite und Portal ("Meine Anwendungen", Profil)
 *   all     überall, auch in der Administration
 *
 * Notbremse: Mit ?custom_css=off in der URL wird das CSS für diesen Aufruf
 * nicht geladen – falls ein Stil die Oberfläche unbenutzbar macht.
 */
class CustomCss
{
    public const MAX_LENGTH = 50000;

    public const SCOPES = ['login', 'portal', 'all'];

    /** Fehlermeldung für ungültiges CSS, oder null wenn es in Ordnung ist. */
    public static function problem(?string $css): ?string
    {
        $css = (string) $css;

        if (str_contains($css, '<') || str_contains($css, '>')) {
            return 'Das CSS darf keine spitzen Klammern („<" oder „>") enthalten. Für Kind-Selektoren bitte z. B. „.a .b" oder „:is()" verwenden.';
        }

        if (preg_match('/expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding/i', $css)) {
            return 'Das CSS enthält nicht erlaubte Ausdrücke (expression, javascript:, behavior, -moz-binding).';
        }

        return null;
    }

    public static function css(): string
    {
        $css = (string) SystemSetting::get('custom_css', '');

        // Doppelte Absicherung, falls ein Wert an der Prüfung vorbei in die
        // Datenbank gelangt ist (z. B. per Wiederherstellung einer Sicherung).
        return self::problem($css) === null ? trim($css) : '';
    }

    public static function scope(): string
    {
        $scope = (string) SystemSetting::get('custom_css_scope', 'login');

        return in_array($scope, self::SCOPES, true) ? $scope : 'login';
    }

    /**
     * Soll das CSS auf einer Seite des angegebenen Bereichs geladen werden?
     *
     * @param  string  $area  login | portal | admin
     */
    public static function appliesTo(string $area, ?Request $request = null): bool
    {
        $request ??= request();

        if ($request->query('custom_css') === 'off') {
            return false;
        }

        return match (self::scope()) {
            'all' => true,
            'portal' => in_array($area, ['login', 'portal'], true),
            default => $area === 'login',
        };
    }
}
