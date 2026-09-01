# Identity Provider

Zentrale Anmeldung für interne Anwendungen. Eine Laravel-Anwendung, die lokale
Konten, Active Directory und Windows-SSO als Anmeldequellen zusammenführt und
nach außen als OAuth 2.0 / OpenID Connect Provider und als SAML 2.0 Identity
Provider auftritt.

Kein Node, kein npm, kein Build-Schritt. Das Frontend besteht aus
Blade-Templates mit Tailwind und Alpine.js, die lokal aus `public/vendor/` ausgeliefert werden (keine externen CDN).

## Was drin ist

* Lokale Benutzerkonten, optional mit Zwei-Faktor-Anmeldung (TOTP plus Recovery-Codes)
* Active Directory und LDAP, mehrere Verzeichnisse gleichzeitig, verschachtelte Gruppen werden rekursiv aufgelöst
* Windows-SSO über Kerberos/SPNEGO. Den Handshake macht der Webserver, die App verarbeitet `REMOTE_USER`
* OAuth 2.0 und OpenID Connect: Authorization Code mit PKCE, Client Credentials, Refresh Token, Discovery, JWKS, UserInfo, Token-Revocation
* SAML 2.0 Identity Provider mit signierten Assertions, Metadata pro Anwendung und Attribut-Mapping
* Rollen-Mapping von AD-Gruppen auf interne Rollen (auch AD-Konten können Administrator werden)
* Getrennter Administrationsbereich unter `/admin`, das persönliche Portal (freigegebene Anwendungen) liegt unter `/`
* Audit-Log, Sitzungsübersicht, Systemstatus-Seite, Rate-Limiting auf den Anmelde- und Token-Endpunkten
* Versionsanzeige im Footer und automatische Prüfung auf neue Releases in der Administration

## Schnellstart (lokal)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Beim ersten Aufruf der Startseite erkennt die Anwendung, dass sie noch nicht
eingerichtet ist, und startet den Web-Installer.

Für den Produktivbetrieb gibt es drei Wege (manuell, Skript, Docker). Sie
stehen in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Einrichtung über den Web-Installer

Der Installer führt durch sieben Schritte:

1. Systemprüfung: PHP-Version, Extensions, Schreibrechte
2. Datenbank: Verbindung testen, Migrationen laufen automatisch
3. System: Name, Basis-URL, Zeitzone, Sprache, Sitzungsdauer, Logo, Favicon, E-Mail
4. Lokaler Administrator: ein Konto unabhängig von Active Directory, für den Notfall
5. Active Directory: optional, lässt sich überspringen und später unter `/admin/directories` nachholen
6. Windows-SSO: Hilfeseite mit den konkreten Schritten für IIS, Apache und Nginx
7. Abschluss: sperrt den Installer dauerhaft

Danach ist `/install` nicht mehr erreichbar (`system_settings.installed = 1`),
ein erneuter Aufruf leitet auf `/login`.

## Produktivbetrieb

Voraussetzungen:

* PHP 8.3 oder neuer, entwickelt und getestet mit 8.4
* Composer 2
* PHP-Extensions: `openssl`, `ldap`, `xml`, `curl`, `mbstring`, `intl`, `session`, `sodium`, `pdo` samt Treiber für MariaDB/MySQL oder SQLite
* Webserver mit PHP-FPM (Apache, Nginx, IIS)
* MariaDB oder MySQL für den Produktivbetrieb, SQLite reicht für Tests
* Schreibrechte für den Webserver-Benutzer auf `storage/` und `bootstrap/cache/`

In der `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
```

Die Anwendung terminiert kein TLS. Davor gehört ein Reverse Proxy mit gültigem
Zertifikat. SAML, OIDC und Kerberos setzen HTTPS voraus.

Nach dem Deploy:

```bash
php artisan config:cache
php artisan route:cache
```

Der Scheduler muss laufen. Ein Eintrag in Cron oder Aufgabenplanung genügt:

```
* * * * * php artisan schedule:run
```

Er betreibt die AD-Synchronisierung (`directory:sync-groups` alle 15 Minuten,
`directory:sync-users` täglich), den Heartbeat für die Statusseite und die
Prüfung auf neue Releases.

Werden Hintergrundjobs genutzt, zusätzlich einen Worker starten:

```bash
php artisan queue:work
```

## Active Directory und LDAP

Verzeichnisse werden unter `Administration → Verzeichnisse` (`/admin/directories`)
gepflegt. Nötig sind ein Service-Konto mit Lesezugriff auf Benutzer und Gruppen
(kein Domain-Admin) und die Base DN, etwa `DC=firma,DC=local`. Bind-Passwörter
werden nur verschlüsselt gespeichert.

* LDAPS auf Port 636 wird empfohlen. Unverschlüsseltes LDAP auf Port 389 nur in vertrauenswürdigen Netzen. Der Typ ist pro Verzeichnis einstellbar.
* In der Detailansicht eines Verzeichnisses: Verbindung testen, Benutzer suchen, Gruppe suchen, Testbenutzer anmelden, rohe LDAP-Abfrage.
* Synchronisiert wird manuell per Knopf, bei jeder Windows-SSO-Anmeldung und regelmäßig über den Scheduler.
* Pro Verzeichnis lässt sich Synchronisierung und Anmeldung auf Mitglieder bestimmter Gruppen beschränken (Feld „Anmeldung auf Gruppen beschränken", ein Gruppen-DN oder CN pro Zeile, verschachtelte Mitgliedschaft wird berücksichtigt). Der „Group DN" ist davon getrennt und nur der Suchpfad für Gruppenobjekte.
* Pro Verzeichnis einstellbar, was mit Benutzern passiert, die bei einer vollen Synchronisierung nicht mehr im Suchbereich liegen (aus der OU verschoben, im Verzeichnis gelöscht oder nicht mehr in der Gruppe): behalten, sperren oder löschen. Liefert die Suche gar nichts, wird nichts angetastet.
* Einzelne Benutzer lassen sich auch von Hand entfernen (`/admin/users`), auch solche aus einem Verzeichnis.
* Unter `/admin/group-role-mappings` lassen sich AD-Gruppen auf interne Rollen abbilden, zum Beispiel auf `admin`. Der Gruppenname kann direkt eingetragen werden (auch bevor synchronisiert wurde); bekannte Gruppen werden vorgeschlagen. Optional auf ein Verzeichnis begrenzen.
* Die Rolle `admin` (aus dem Gruppen-Mapping oder manuell pro Benutzer unter `/admin/users`) macht auch AD- und LDAP-Konten zu Administratoren. Der lokale Break-Glass-Administrator bleibt als Absicherung erhalten und lässt sich nicht als letzter herabstufen oder löschen.

## Windows-SSO

Den Kerberos-Handshake macht der Webserver, nicht Laravel. Der Webserver prüft
das Ticket des Browsers und übergibt die Identität als `REMOTE_USER`, zum
Beispiel `FIRMA\mmuster`. Ab da übernimmt die Middleware
`WindowsSsoAuthenticate`: sie zerlegt den Namen, schlägt den Benutzer im AD
nach, synchronisiert ihn und meldet ihn an. Ohne `REMOTE_USER` passiert nichts,
einen Ersatz-Login gibt es nicht.

Die ausführliche Anleitung steht im Installer unter Schritt 6
(`/install/windows-sso`). In Kürze:

**IIS:** Windows-Authentifizierung aktivieren, anonyme Authentifizierung aus,
Anbieter-Reihenfolge `Negotiate` vor `NTLM`, SPN registrieren
(`setspn -S HTTP/auth.firma.local FIRMA\svc-auth`). IIS setzt `REMOTE_USER`,
FastCGI reicht es durch.

**Apache mit `mod_auth_gssapi`:** Keytab erzeugen (`ktpass` oder `msktutil`),
dann:

```apache
<Location />
    AuthType GSSAPI
    AuthName "Windows SSO"
    GssapiCredStore keytab:/etc/apache2/auth.keytab
    GssapiLocalName On
    Require valid-user
</Location>
```

**Nginx** hat kein eigenes SPNEGO-Modul. Entweder Apache oder IIS als Reverse
Proxy davorstellen, die `REMOTE_USER` als Header weitergeben, oder ein
Drittanbieter-Modul einsetzen. Alternativ gibt es den eingebauten Endpunkt
`/auth/negotiate`, der ohne vorgeschalteten Kerberos-Server auskommt. Er prüft
aber nur den gemeldeten Namen, nicht dessen Echtheit gegen den Domain
Controller. Das ist eine bewusste Entscheidung für abgeschottete interne Netze,
siehe Kommentar in `app/Http/Controllers/Auth/NegotiateController.php`.

Benötigte Werte, unabhängig vom Webserver: SPN (`HTTP/auth.firma.local`),
Kerberos-Realm (`FIRMA.LOCAL`), Hostname, Keytab (Apache, Linux) oder der auf
dem Dienstkonto registrierte SPN (IIS), dazu der HTTP-Principal.

## OAuth 2.0 und OpenID Connect

| Zweck | Route |
|---|---|
| Discovery | `GET /.well-known/openid-configuration` |
| JWKS | `GET /.well-known/jwks.json` |
| Authorization | `GET /oauth/authorize` |
| Token | `POST /oauth/token` |
| UserInfo | `GET` oder `POST /oauth/userinfo` |
| Revocation | `POST /oauth/revoke` |

Clients werden unter `/admin/applications` angelegt: Redirect-URIs, Scopes,
Grant-Types, Token-Laufzeiten, PKCE-Pflicht. Das Client-Secret ist nur direkt
nach dem Anlegen im Klartext sichtbar. Die Signaturschlüssel werden unter
`/admin/oidc-keys` rotiert.

Ablauf für einen Client mit Authorization Code und PKCE:

```
1. code_verifier und code_challenge (S256) erzeugen, dazu einen zufälligen state.
2. Weiterleitung auf:
   GET /oauth/authorize
       ?response_type=code
       &client_id=<client_id>
       &redirect_uri=https://app.firma.de/callback
       &scope=openid profile email groups
       &state=<state>
       &code_challenge=<code_challenge>
       &code_challenge_method=S256
3. Nach Anmeldung und Zustimmung: Ruecksprung mit ?code=...&state=...
4. Code gegen Token tauschen:
   POST /oauth/token
   grant_type=authorization_code&code=...&redirect_uri=...&client_id=...&code_verifier=...
   Antwort: access_token, id_token, refresh_token, token_type, expires_in
5. id_token gegen /.well-known/jwks.json pruefen (RS256, kid im Header beachten).
```

## SAML 2.0

Die Anwendung ist ein SAML-2.0-Identity-Provider. Service Provider werden unter
`/admin/saml-service-providers` eingetragen: Entity ID, ACS-URL, SLO-URL,
NameID-Format, Signaturanforderungen, Attribut-Mapping.

| Zweck | Route |
|---|---|
| Metadata global | `GET /saml/metadata` |
| Metadata pro Anwendung | `GET /saml/{application}/metadata` |
| SSO | `GET` oder `POST /saml/sso` |
| Single Logout | `GET` oder `POST /saml/slo` |

Einen SP anbinden:

1. Im SP die IdP-Metadata von `/saml/{application}/metadata` einlesen. Sie enthält Entity ID, SSO- und SLO-URL und das öffentliche Signaturzertifikat.
2. Im Adminbereich einen Eintrag mit Entity ID und ACS-URL des SP anlegen.
3. Attribut-Mapping prüfen. Standard: `uid` auf `username`, `mail` auf `email`, `displayName` auf `display_name`, `department` auf `department`, `groups` auf die AD-Gruppen.
4. Der SP schickt eine AuthnRequest an `/saml/sso`. Nach der Anmeldung geht eine signierte Assertion per Auto-Submit-Formular an die ACS-URL.

Zertifikate liegen unter `/admin/saml-certificates`: Selbstsignierung, Rotation,
Ablaufwarnung. Signierte AuthnRequests müssen über das POST-Binding kommen. Die
Signaturprüfung für das Redirect-Binding fehlt noch.

## Sicherheit und Betrieb

* Zwei-Faktor-Anmeldung (TOTP und Recovery-Codes) für lokale Konten unter `Profil → Zwei-Faktor`
* Eigene Sitzungen unter `Profil → Meine Sitzungen`, alle Sitzungen unter `Administration → Alle Sessions`, jeweils einsehbar und widerrufbar
* Audit-Log unter `Administration → Audit-Log`: Anmeldungen, Token-Ereignisse, Zustimmungen, SAML, Admin-Aktionen
* Systemstatus unter `Administration → Systemstatus`: Datenbank, Cache, Dateisystem, Queue, Scheduler, AD-Verbindungen, Ablauf von OIDC-Schlüsseln und SAML-Zertifikaten
* Rate-Limiting auf Anmeldung, `/oauth/token`, `/oauth/revoke` und `/saml/sso`
* Versionsnummer im Footer, verlinkt auf die passende Release-Seite. Die Administration prüft regelmäßig auf neue Releases und zeigt Changelog und Update-Hinweise unter `Administration → Aktualisierungen`.

## Was noch fehlt

Die Kernprotokolle funktionieren und sind mit Tests abgedeckt
(`php artisan test`). Vor einem echten Produktiveinsatz fehlt noch:

* Ein erprobtes HTTPS- und Reverse-Proxy-Setup mit gültigen Zertifikaten
* Echter Mailversand für Passwort-Reset, aktuell nicht an einen SMTP-Dienst angebunden
* Ein externer Security-Review, vor allem der SAML-XML- und OAuth-Token-Pfade
* Ein Lasttest mit realistischer Nutzerzahl, besonders für LDAP-Sync und Token-Endpunkt
* IdP-initiierter SAML-Logout, bisher nur SP-initiiert
* SAML-Assertion-Verschlüsselung, vorbereitet aber nicht fertig
* Signaturprüfung für das SAML-Redirect-Binding
* Anbindung externer Identity Provider (Entra ID, Keycloak, Google), bisher nur als Konfigurationsfeld vorhanden
* Automatische Home-Realm-Discovery
* Ein Benutzername/Passwort-Login für AD-Konten zusätzlich zu Windows-SSO
* Bei rotierten OAuth-Schlüsseln nutzt die Access-Token-Prüfung nur den zuletzt aktiven Schlüssel. Die ID-Token-Prüfung über JWKS deckt alle bisherigen Schlüssel ab

## Tests

```bash
php artisan test
```

## Lizenz

Siehe [`LICENSE`](LICENSE). Nutzung, Änderung und Weitergabe sind erlaubt,
solange der Urheberhinweis erhalten bleibt und bei einem sichtbaren Betrieb ein
Link auf das Ursprungsprojekt gesetzt wird.
