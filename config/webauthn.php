<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | Die "Relying Party ID" ist die registrierbare Domain, für die Passkeys
    | gelten (z. B. "login.firma.de"). Standard ist der Host aus APP_URL.
    | Nur setzen, wenn davon abgewichen werden muss (etwa wenn das System
    | unter mehreren Hostnamen erreichbar ist und Passkeys für die
    | übergeordnete Domain gelten sollen).
    |
    */

    'rp_id' => env('WEBAUTHN_RP_ID') ?: parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Anzeigename der Relying Party
    |--------------------------------------------------------------------------
    |
    | Wird dem Nutzer beim Anlegen eines Passkeys angezeigt. Leer lassen, dann
    | wird zur Laufzeit der Systemname aus den Systemeinstellungen verwendet.
    |
    */

    'rp_name' => env('WEBAUTHN_RP_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Timeout (Sekunden)
    |--------------------------------------------------------------------------
    |
    | Wie lange der Browser auf den Authenticator wartet.
    |
    */

    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Zugelassene Attestation-Formate
    |--------------------------------------------------------------------------
    |
    | "none" verzichtet auf die Herkunftsbescheinigung des Authenticators.
    | Für einen internen Identity Provider ist das die datensparsame und
    | breit kompatible Wahl (funktioniert mit allen Passkeys).
    |
    */

    'formats' => ['none'],

];
