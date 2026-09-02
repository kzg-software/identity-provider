<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Hilfen rund um die PHP-Upload-Grenzen (upload_max_filesize / post_max_size).
 *
 * PHP verwirft zu große Uploads schon, bevor Laravel sie sieht: $_FILES ist
 * dann leer oder enthält nur einen Fehlercode. Ohne diese Prüfung landet der
 * Nutzer bei einer nichtssagenden "Feld ist erforderlich"-Meldung.
 */
class UploadLimits
{
    /**
     * Wirft eine ValidationException mit klarer Meldung, wenn der Upload im
     * Feld $field an einer PHP-Grenze gescheitert ist.
     */
    public static function guard(Request $request, string $field): void
    {
        $file = $request->file($field);

        if ($file && ! $file->isValid()) {
            $reason = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE => 'Die Datei ist größer als auf dem Server erlaubt ('.self::humanMax().'). Bitte die PHP-Werte upload_max_filesize und post_max_size erhöhen.',
                UPLOAD_ERR_FORM_SIZE => 'Die Datei ist größer als im Formular erlaubt.',
                UPLOAD_ERR_PARTIAL => 'Der Upload wurde unterbrochen und ist unvollständig.',
                UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei übertragen.',
                UPLOAD_ERR_NO_TMP_DIR => 'Auf dem Server fehlt ein temporäres Upload-Verzeichnis (upload_tmp_dir).',
                UPLOAD_ERR_CANT_WRITE => 'Der Server konnte die Datei nicht zwischenspeichern (Schreibrechte im Temp-Verzeichnis).',
                UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload abgebrochen.',
                default => 'Der Upload ist fehlgeschlagen ('.$file->getErrorMessage().').',
            };

            throw ValidationException::withMessages([$field => $reason]);
        }

        if (! $file && (int) $request->server('CONTENT_LENGTH') > self::postMaxSizeInBytes()) {
            throw ValidationException::withMessages([
                $field => 'Die hochgeladene Datei ist größer als auf dem Server erlaubt ('.self::humanMax().'). '
                    .'Bitte die PHP-Werte upload_max_filesize und post_max_size erhöhen.',
            ]);
        }
    }

    /** Die kleinere der beiden relevanten Grenzen, menschenlesbar. */
    public static function humanMax(): string
    {
        $bytes = min(
            self::toBytes(ini_get('upload_max_filesize') ?: '2M'),
            self::postMaxSizeInBytes(),
        );

        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024).' MB'
            : round($bytes / 1024).' KB';
    }

    public static function postMaxSizeInBytes(): int
    {
        return self::toBytes(ini_get('post_max_size') ?: '8M');
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => (int) $value,
        };
    }
}
