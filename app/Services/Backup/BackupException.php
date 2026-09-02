<?php

namespace App\Services\Backup;

/**
 * Fehler beim Erstellen oder Wiederherstellen einer Datensicherung. Die
 * Nachricht ist immer so formuliert, dass sie einem Administrator direkt
 * angezeigt werden kann.
 */
class BackupException extends \RuntimeException
{
}
