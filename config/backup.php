<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vorgaben für die automatische Sicherung
    |--------------------------------------------------------------------------
    |
    | Diese Werte sind nur die Standardwerte, solange ein Administrator unter
    | Administration -> Datensicherung nichts anderes einstellt (System-
    | Settings "auto_backup_keep" / "auto_backup_retention_days" überschreiben
    | diese Werte pro Installation).
    |
    */

    // Maximale Anzahl an Sicherungen am Ziel, 0 = unbegrenzt.
    'max_count' => (int) env('BACKUP_MAX_COUNT', 14),

    // Sicherungen, die älter als so viele Tage sind, werden entfernt. 0 = aus.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    // Sicherheitsreserve an freiem Speicherplatz, die vor einer Sicherung
    // am Arbeits- und am Zielverzeichnis vorhanden sein muss.
    'min_free_bytes' => (int) env('BACKUP_MIN_FREE_BYTES', 2 * 1024 * 1024 * 1024),

    // Wie lange der Sperr-Lock maximal gehalten wird (Sicherheitsnetz, falls
    // ein Prozess abstürzt, ohne ihn freizugeben).
    'lock_timeout_seconds' => (int) env('BACKUP_LOCK_TIMEOUT_SECONDS', 3600),

];
