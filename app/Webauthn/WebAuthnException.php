<?php

namespace App\Webauthn;

use RuntimeException;

/**
 * Wird geworfen, wenn eine WebAuthn-Registrierung oder -Anmeldung fehlschlägt.
 * Die Nachricht ist für die Anzeige an den Nutzer gedacht.
 */
class WebAuthnException extends RuntimeException {}
