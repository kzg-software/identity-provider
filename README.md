# Zentrales Authentifizierungs- und SSO-System

Ein zentrales Identity & Access Management / Single-Sign-On-System auf Basis von **Laravel 13**, das lokale
Konten, **Active Directory / LDAP / Kerberos-SSO**, einen vollständigen **OAuth 2.0 / OpenID-Connect-Provider**
und einen **SAML-2.0-Identity-Provider** in einer Anwendung vereint. Kein NodeJS/npm/Vite — Frontend läuft
vollständig mit Blade + Bootstrap 5 (CDN).

## Installation

> **Deployment-Wege im Detail:** [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) —
> drei vollständig unterstützte Wege:
> **A** manuell (ohne Docker, ohne Skript) ·
> **B** mit Skript (`deploy/install.sh`, Zero-Downtime-VM) ·
> **C** mit Docker (vordefinierte `docker-compose`, automatischer Image-Build via CI).

### Voraussetzungen

- PHP **8.3+** (entwickelt/getestet mit PHP 8.4)
- Composer 2.x
- PHP-Extensions: `openssl`, `ldap`, `xml`, `curl`, `mbstring`, `intl`, `session`, `sodium`, `pdo` (+ Treiber für
  MySQL/MariaDB bzw. SQLite)
- Ein Webserver (Apache, Nginx oder IIS) mit PHP-FPM/FastCGI, oder `php artisan serve` für Entwicklung/Test
- MySQL/MariaDB (produktiv empfohlen) oder SQLite (Entwicklung/Erstinstallation)
- Schreibrechte für den Webserver-Benutzer auf `storage/` und `bootstrap/cache/`

### Schritte

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Beim ersten Aufruf der Basis-URL (z.B. `https://auth.domain.de`) erkennt das System automatisch, dass es noch
nicht installiert ist, und führt durch den **Web-Installer**:

1. **Systemprüfung** — PHP-Version, Extensions, Schreibrechte
2. **Datenbank** — Verbindungstest, führt Migrationen automatisch aus
3. **System** — Systemname, Basis-URL, Zeitzone, Sprache, Session-Dauer, Logo/Favicon, E-Mail
4. **Lokaler Administrator** — Break-Glass-Account, unabhängig von Active Directory
5. **Active Directory** (optional) — kann übersprungen und später unter `/admin/directories` eingerichtet werden
6. **Windows SSO** — Hilfeseite mit konkreten Server-Konfigurationsschritten (siehe unten)
7. **Abschluss** — sperrt den Installer dauerhaft; erneuter Aufruf von `/install` leitet danach auf `/login` um

Nach Abschluss ist der Installer nicht mehr öffentlich erreichbar (`system_settings.installed = 1`).

### Produktivbetrieb

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true` (nur
  über HTTPS betreiben — SAML/OIDC/Kerberos-SSO setzen einen vertrauenswürdigen Transport voraus)
  - dieses Projekt terminiert TLS nicht selbst; produktiv gehört ein Reverse Proxy (Nginx/IIS/Apache) mit
    gültigem Zertifikat davor
- `php artisan config:cache && php artisan route:cache`
- Laravel Scheduler per Cron/Aufgabenplanung: `* * * * * php artisan schedule:run` — betreibt u.a. die
  periodische AD-Synchronisierung (`directory:sync-groups` alle 15 Min, `directory:sync-users` täglich) und den
  Heartbeat für die Systemstatus-Seite (`/admin/status`)
- Queue-Worker, falls Jobs eingesetzt werden: `php artisan queue:work`

## Active Directory / LDAP

Directories werden unter `Administration → Verzeichnisse` (`/admin/directories`) verwaltet — mehrere gleichzeitig
möglich. Benötigt wird ein **Bind-Service-Account** (Lesezugriff auf Benutzer/Gruppen, kein Domain-Admin
erforderlich) sowie die **Base DN** (z.B. `DC=domain,DC=local`). Bind-Passwörter werden ausschließlich
verschlüsselt gespeichert (Laravel `encrypted` Cast).

- **LDAP vs. LDAPS**: LDAPS (Port 636) wird empfohlen; unverschlüsseltes LDAP (Port 389) nur in vertrauenswürdigen
  Netzen. Der Verbindungstyp ist pro Directory konfigurierbar.
- Direkt in der Directory-Detailansicht: **Verbindung testen**, **Benutzer suchen**, **Gruppe suchen**,
  **Testbenutzer authentifizieren**, **rohe LDAP-Abfrage**.
- Synchronisierung: manuell per Button, bei jedem Windows-SSO-Login, sowie periodisch über den Scheduler.
  Verschachtelte Gruppen (nested groups) werden rekursiv aufgelöst.
- AD-Gruppen können unter `/admin/group-role-mappings` auf interne Rollen (z.B. `admin`) abgebildet werden.

## Windows SSO (Integrated Windows Authentication / Kerberos)

Der Kerberos/SPNEGO-Handshake findet auf **Webserver-Ebene** statt — Laravel selbst spricht kein Kerberos. Der
Webserver validiert das vom Browser übermittelte Ticket und reicht die authentifizierte Identität als
`REMOTE_USER` (z.B. `DOMAIN\jkinzig`) durch; die `WindowsSsoAuthenticate`-Middleware übernimmt ab dort Parsing,
AD-Lookup/Sync und Login. **Ohne gesetztes `REMOTE_USER` erfolgt kein automatischer Login** — es gibt keinen
Fallback-/Fake-Login.

Eine ausführliche, interaktive Anleitung mit den konkreten Konfigurationsschritten steht auch im Installer
(Schritt 6, `/install/windows-sso`). Kurzfassung:

**IIS**: Windows-Authentifizierung-Feature aktivieren, anonyme Authentifizierung deaktivieren, Provider-Reihenfolge
`Negotiate` vor `NTLM`, SPN registrieren (`setspn -S HTTP/auth.domain.local DOMAIN\svc-auth`). IIS setzt
`REMOTE_USER` automatisch, FastCGI reicht es durch.

**Apache** (`mod_auth_gssapi`): Keytab erzeugen (`ktpass` bzw. `msktutil`), dann:

```apache
<Location />
    AuthType GSSAPI
    AuthName "Windows SSO"
    GssapiCredStore keytab:/etc/apache2/auth.keytab
    GssapiLocalName On
    Require valid-user
</Location>
```

**Nginx** hat kein natives SPNEGO-Modul — entweder als Reverse-Proxy vor Apache/IIS betreiben (die dann
`REMOTE_USER` per Header durchreichen) oder ein Drittanbieter-Modul (`nginx-http-auth-spnego`) einsetzen.

Benötigte Werte, unabhängig vom Webserver: **SPN** (`HTTP/auth.domain.local`), **Kerberos-Realm**
(`DOMAIN.LOCAL`), **Hostname**, **Keytab** (Apache/Linux) bzw. registrierter SPN auf dem Dienstkonto (IIS), sowie
der **HTTP Principal**.

## OAuth 2.0 / OpenID Connect

Das System ist selbst ein vollständiger OIDC-Provider:

- Discovery: `GET /.well-known/openid-configuration`
- JWKS: `GET /.well-known/jwks.json` (Key-Rotation über `/admin/oidc-keys`)
- Authorization: `GET /oauth/authorize`
- Token: `POST /oauth/token` (Authorization Code + PKCE, Client Credentials, Refresh Token)
- UserInfo: `GET|POST /oauth/userinfo`
- Revocation: `POST /oauth/revoke`

Anwendungen/Clients werden unter `/admin/applications` angelegt (Redirect-URIs, Scopes, Grant-Types,
Token-Lifetimes, PKCE-Pflicht). Das Client-Secret wird nur direkt nach der Erstellung im Klartext angezeigt.

### Beispiel-Client-Integration (Authorization Code + PKCE)

```
1. Client generiert code_verifier + code_challenge (S256) und einen zufälligen state.
2. Redirect zu:
   GET https://auth.domain.de/oauth/authorize
       ?response_type=code
       &client_id=<client_id>
       &redirect_uri=https://app1.domain.de/callback
       &scope=openid profile email groups
       &state=<state>
       &code_challenge=<code_challenge>
       &code_challenge_method=S256
3. Nach Login/Consent: Redirect zurück mit ?code=...&state=...
4. Token-Tausch:
   POST /oauth/token
   grant_type=authorization_code&code=...&redirect_uri=...&client_id=...&code_verifier=...
   → { access_token, id_token, refresh_token, token_type, expires_in }
5. ID-Token gegen /.well-known/jwks.json verifizieren (RS256, "kid" im Header beachten).
```

## SAML 2.0

Das System ist ein SAML-2.0-**Identity Provider**. Service Provider werden unter
`/admin/saml-service-providers` angelegt (Entity ID, ACS-/SLO-URL, NameID-Format, Signaturanforderungen,
Attribut-Mappings).

- Metadata (global): `GET /saml/metadata`
- Metadata (pro Anwendung): `GET /saml/{application}/metadata`
- SSO-Endpoint (nimmt AuthnRequests entgegen): `GET|POST /saml/sso`
- Single Logout: `GET|POST /saml/slo`

### Beispiel-SP-Integration

1. Im SP die IdP-Metadata unter `https://auth.domain.de/saml/{application}/metadata` einbinden (enthält
   Entity ID, SSO-/SLO-URL und das öffentliche Signing-Zertifikat).
2. Im Admin-Bereich einen `SamlServiceProvider`-Eintrag mit der Entity ID und ACS-URL des SP anlegen.
3. Attribut-Mapping prüfen/anpassen (Default: `uid`→`username`, `mail`→`email`, `displayName`→`display_name`,
   `department`→`department`, `groups`→AD-Gruppen).
4. Der SP schickt eine AuthnRequest an `/saml/sso`; nach Login (lokal oder Windows-SSO) wird eine signierte
   Assertion per Auto-Submit-POST an die ACS-URL gesendet.

Zertifikate werden unter `/admin/saml-certificates` verwaltet (automatische Selbstsignierung möglich, Rotation,
Ablaufwarnung). Signaturpflichtige AuthnRequests müssen über POST-Binding gesendet werden (Signaturprüfung für
das Redirect-Binding ist aktuell nicht implementiert).

## Sicherheit & Betrieb

- Zwei-Faktor-Authentifizierung (TOTP + Recovery-Codes) für lokale Konten unter `Profil → Zwei-Faktor`
- Aktive Sessions einsehbar/widerrufbar unter `Profil → Meine Sitzungen` bzw. `Administration → Alle Sessions`
- Vollständiges Audit-Log unter `Administration → Audit-Log` (Login, Token-Ereignisse, Consent, SAML,
  Admin-Aktionen, …)
- Systemstatus/Health-Checks unter `Administration → Systemstatus` (DB, Cache, Dateisystem, Queue, Scheduler,
  AD/LDAP, OIDC-Signing-Key- und SAML-Zertifikatsablauf)
- Rate-Limiting auf Login, `/oauth/token`, `/oauth/revoke` und `/saml/sso`

## Bekannte Einschränkungen / was für eine echte Produktivumgebung zusätzlich nötig ist

Dieses Projekt implementiert alle Kernprotokolle funktional und mit echten Tests (`php artisan test`), ist aber
kein fertig abgenommenes Produkt. Vor einem produktiven Einsatz zusätzlich einplanen:

- **HTTPS/Reverse-Proxy-Setup** inkl. gültiger Zertifikate — SAML/OIDC/Kerberos sind ohne TLS nicht sicher zu
  betreiben
- **Echter Mailversand** (Passwort-Reset o.ä.) ist noch nicht an einen SMTP-Provider angebunden/getestet
- **Penetrationstest / Security-Review** durch Dritte, insbesondere der SAML-XML- und OAuth-Token-Pfade
- **Lasttest** unter realistischer Nutzerzahl (insbesondere LDAP-Sync-Läufe und Token-Endpoint)
- **IdP-initiierter SAML-Logout** ist nicht implementiert (nur SP-initiiert)
- **SAML-Assertion-Verschlüsselung** (Encryption-Zertifikat-Handling) ist vorbereitet, aber nicht fertig
- **SAML-Redirect-Binding-Signaturprüfung** fehlt (POST-Binding wird korrekt geprüft und ist der empfohlene Weg)
- **Externe Identity Provider** (Microsoft Entra ID, Keycloak, Google, externe SAML-IdPs) sind als
  Konfigurationsfeld vorbereitet, aber nicht angebunden
- **Home Realm Discovery** ist nicht vollautomatisiert (E-Mail-Domain-/IP-Netz-/Policy-basiert)
- Ein **AD-Benutzername/Passwort-Login** (zusätzlich zu Windows-SSO) existiert noch nicht — AD-Benutzer melden
  sich aktuell ausschließlich über Windows-SSO an
- OAuth-Access-Token-Validierung nutzt bei Key-Rotation aktuell nur den zuletzt aktiven Signing-Key (ID-Token-
  Prüfung über JWKS funktioniert dagegen mit allen historischen Keys)

## Tests

```bash
php artisan test
```
