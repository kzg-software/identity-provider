<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Verständliche Bezeichnungen für die Ereignisnamen im Audit-Log.
 * Unbekannte Namen werden aus dem technischen Schlüssel abgeleitet.
 */
class AuditEventLabels
{
    private const MAP = [
        'login.success' => 'Anmeldung erfolgreich',
        'login.failed' => 'Anmeldung fehlgeschlagen',
        'login.2fa_required' => 'Zweiter Faktor angefordert',
        'login.2fa_failed' => 'Zweiter Faktor fehlgeschlagen',
        'login.windows_sso' => 'Anmeldung über Windows',
        'logout' => 'Abmeldung',

        'password.changed' => 'Passwort geändert',

        'webauthn.login' => 'Anmeldung mit Passkey',
        'webauthn.registered' => 'Passkey hinzugefügt',
        'webauthn.removed' => 'Passkey entfernt',
        'webauthn.clone_detected' => 'Möglicher Passkey-Klon erkannt',

        'two_factor.enabled' => 'Authenticator-App aktiviert',
        'two_factor.disabled' => 'Authenticator-App deaktiviert',
        'two_factor.recovery_used' => 'Wiederherstellungscode verwendet',
        'two_factor.recovery_regenerated' => 'Wiederherstellungscodes neu erzeugt',

        'oauth.application_created' => 'Anwendung angelegt',
        'oauth.application_updated' => 'Anwendung geändert',
        'oauth.application_deleted' => 'Anwendung gelöscht',
        'oauth.application_provider_attached' => 'Provider mit Anwendung verknüpft',
        'oauth.application_provider_detached' => 'Provider von Anwendung getrennt',
        'oauth.access_policy_created' => 'Zugriffsregel angelegt',
        'oauth.access_policy_deleted' => 'Zugriffsregel gelöscht',
        'oauth.provider_created' => 'Provider angelegt',
        'oauth.provider_updated' => 'Provider geändert',
        'oauth.provider_deleted' => 'Provider gelöscht',
        'oauth.client_secret_regenerated' => 'Client-Secret neu erzeugt',
        'oauth.authorize.success' => 'Autorisierung erteilt',
        'oauth.authorize.denied' => 'Autorisierung abgelehnt',
        'oauth.authorize.maintenance' => 'Autorisierung: Anwendung in Wartung',
        'oauth.consent.granted' => 'Zustimmung erteilt',
        'oauth.consent.denied' => 'Zustimmung abgelehnt',
        'oauth.consent.revoked_by_user' => 'Zugriff durch Nutzer entzogen',
        'oauth.token.issued' => 'Token ausgestellt',
        'oauth.token.failed' => 'Token-Anfrage fehlgeschlagen',
        'oauth.token.revoked' => 'Token widerrufen',
        'oauth.logout' => 'OIDC-Abmeldung',

        'oidc.key_rotated' => 'OIDC-Schlüssel rotiert',
        'saml.certificate_rotated' => 'SAML-Zertifikat rotiert',
        'saml.provider_created' => 'SAML-Provider angelegt',
        'saml.provider_updated' => 'SAML-Provider geändert',
        'saml.sso.success' => 'SAML-Anmeldung erfolgreich',
        'saml.sso.denied' => 'SAML-Anmeldung abgelehnt',
        'saml.sso.maintenance' => 'SAML-Anmeldung: Anwendung in Wartung',
        'saml.slo.request' => 'SAML-Abmeldung angefordert',
        'saml.authn_request.invalid_signature' => 'SAML-Anfrage: ungültige Signatur',
        'saml.authn_request.acs_mismatch' => 'SAML-Anfrage: ACS-URL stimmt nicht',
        'saml.slo.invalid_signature' => 'SAML-Abmeldung: ungültige Signatur',

        'admin.user_created' => 'Benutzer angelegt',
        'admin.user_deleted' => 'Benutzer gelöscht',
        'admin.user_admin_toggled' => 'Administrator-Rechte geändert',
        'admin.password_reset' => 'Passwort zurückgesetzt (Admin)',
        'admin.two_factor_reset' => 'Zwei-Faktor zurückgesetzt (Admin)',
        'admin.webauthn_removed' => 'Passkey entfernt (Admin)',
        'admin.avatar_reset' => 'Profilbild zurückgesetzt (Admin)',
        'admin.users_imported' => 'Benutzer importiert',
        'admin.users_bulk_action' => 'Massenaktion auf Benutzer',
        'admin.settings_updated' => 'Systemeinstellungen geändert',
        'admin.mail_test_sent' => 'Test-E-Mail gesendet',

        'system.installed' => 'System installiert',
        'system.restored_from_backup' => 'Aus Sicherung wiederhergestellt',

        'user.session_revoked' => 'Sitzung beendet',
        'user.sessions_revoked_others' => 'Andere Sitzungen beendet',
        'impersonate.start' => 'Als Benutzer angemeldet',
        'impersonate.stop' => 'Zurück zum Admin-Konto',
    ];

    public static function label(string $event): string
    {
        if (isset(self::MAP[$event])) {
            return self::MAP[$event];
        }

        return Str::of($event)->replace(['.', '_'], ' ')->squish()->ucfirst()->toString();
    }
}
