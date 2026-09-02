# Identity Provider

Zentrale Anmeldung für interne Anwendungen. Eine Laravel Anwendung, die lokale
Konten, Active Directory und Windows SSO als Anmeldequellen zusammenführt und
nach außen als OAuth 2.0 / OpenID Connect Provider und als SAML 2.0 Identity
Provider auftritt.

Kein Node, kein npm, kein Build Schritt. Die Oberfläche besteht aus Blade
Templates mit Tailwind und Alpine.js, die lokal aus `public/vendor/`
ausgeliefert werden. Keine externen CDN.

## Inhalt

* [Was das System kann](#was-das-system-kann)
* [Schnell deployen mit Docker](#schnell-deployen-mit-docker)
* [Lokal ausprobieren](#lokal-ausprobieren)
* [Einrichtungs Assistent](#einrichtungs-assistent)
* [Datensicherung](#datensicherung)
* [Active Directory und LDAP](#active-directory-und-ldap)
* [Windows SSO](#windows-sso)
* [OAuth 2.0 und OpenID Connect](#oauth-20-und-openid-connect)
* [SAML 2.0](#saml-20)
* [Sicherheit und Betrieb](#sicherheit-und-betrieb)
* [Weitere Dokumentation](#weitere-dokumentation)

## Was das System kann

* Lokale Benutzerkonten, optional mit Zwei Faktor Anmeldung (TOTP plus Recovery Codes)
* Active Directory und LDAP, mehrere Verzeichnisse gleichzeitig, verschachtelte Gruppen werden rekursiv aufgelöst
* Windows SSO über Kerberos. Den Handshake macht der Webserver, die App verarbeitet die durchgereichte Identität
* OAuth 2.0 und OpenID Connect: Authorization Code mit PKCE, Client Credentials, Refresh Token, Discovery, JWKS, UserInfo, Token Revocation
* SAML 2.0 Identity Provider mit signierten Assertions, Metadata pro Anwendung und Attribut Mapping
* Rollen Mapping von AD Gruppen auf interne Rollen. Auch AD Konten können Administrator werden
* Getrennter Administrationsbereich unter `/admin`, das persönliche Portal mit den freigegebenen Anwendungen liegt unter `/`
* Datensicherung: das ganze System als eine verschlüsselte Datei sichern und wiederherstellen
* Audit Log, Sitzungsübersicht, Systemstatus Seite, Rate Limiting auf den Anmelde und Token Endpunkten
* Versionsanzeige im Footer und automatische Prüfung auf neue Releases

## Schnell deployen mit Docker

Der einfachste Weg. Sie brauchen nur zwei Dateien, nicht den ganzen Quellcode.
Voraussetzung: ein Server mit Docker und ein Reverse Proxy mit HTTPS davor
(zum Beispiel nginx, Traefik oder Caddy).

```bash
mkdir auth && cd auth

# Die beiden nötigen Dateien holen
curl -O https://raw.githubusercontent.com/kzg-software/identity-provider/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/kzg-software/identity-provider/main/.env.docker.example
```

In der `.env` mindestens diese Werte setzen:

```dotenv
APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest
APP_URL=https://auth.firma.de
DB_PASSWORD=ein-langes-passwort
DB_ROOT_PASSWORD=ein-anderes-langes-passwort
TRUSTED_PROXIES=*
```

Starten:

```bash
docker compose up -d
```

Den Reverse Proxy auf Port 8080 des Servers zeigen lassen. Dann
`https://auth.firma.de` im Browser öffnen. Der Einrichtungs Assistent führt
durch den Rest.

### Update

```bash
docker compose pull
docker compose up -d
```

Die Datenbank wird beim Start automatisch aktualisiert. Vor größeren Updates
vorher eine Datensicherung erstellen (siehe unten).

### Ohne separaten Datenbankserver

Für kleine Installationen oder Tests reicht SQLite. Statt der `docker-compose.yml`
die `docker-compose.sqlite.yml` holen, in der `.env` `DB_CONNECTION=sqlite`
setzen und mit `docker compose -f docker-compose.sqlite.yml up -d --build`
starten.

Alle Docker Varianten und ein Setup ohne Docker stehen in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Lokal ausprobieren

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Beim ersten Aufruf der Startseite startet der Einrichtungs Assistent.

## Einrichtungs Assistent

Beim ersten Aufruf erkennt das System, dass es noch nicht eingerichtet ist, und
fragt: **neu einrichten** oder **aus einer Sicherung wiederherstellen**.

Die Neueinrichtung führt durch sieben Schritte:

1. Systemprüfung: PHP Version, Erweiterungen, Schreibrechte
2. Datenbank: Verbindung testen, Tabellen werden angelegt
3. System: Name, Adresse, Zeitzone, Sprache, Abmeldezeit, Logo, Favicon, E-Mail
4. Administrator: ein lokales Konto, unabhängig von Active Directory, für den Notfall
5. Active Directory: freiwillig, kann übersprungen und später nachgeholt werden
6. Windows SSO: freiwillig, mit Anleitung für IIS, Apache und nginx
7. Abschluss: sperrt den Assistenten dauerhaft

Danach ist `/install` nicht mehr erreichbar. Ein erneuter Aufruf leitet zur
Anmeldung.

## Datensicherung

In der Administration unter **Datensicherung** lässt sich das gesamte System in
eine einzige verschlüsselte Datei sichern (Datenbank, Konfiguration, alle
hochgeladenen Dateien) und daraus wiederherstellen. Aus so einer Sicherung
lässt sich das System auch auf einem frischen Server 1:1 wieder aufbauen, direkt
im Einrichtungs Assistenten.

Details: [`docs/BACKUP.md`](docs/BACKUP.md).
Umbenennen und mehrere Instanzen (zum Beispiel Produktiv und Test):
[`docs/INSTANZEN.md`](docs/INSTANZEN.md).

## Active Directory und LDAP

Verzeichnisse werden unter **Administration, Verzeichnisse**
(`/admin/directories`) gepflegt. Nötig sind ein Servicekonto mit Lesezugriff
auf Benutzer und Gruppen (kein Domain Admin) und die Base DN, etwa
`DC=firma,DC=local`. Bind Passwörter werden nur verschlüsselt gespeichert.

* LDAPS auf Port 636 wird empfohlen. Unverschlüsseltes LDAP auf Port 389 nur in vertrauenswürdigen Netzen. Pro Verzeichnis einstellbar.
* In der Detailansicht: Verbindung testen, Benutzer suchen, Gruppe suchen, Testanmeldung, rohe LDAP Abfrage.
* Synchronisiert wird manuell per Knopf, bei jeder Windows SSO Anmeldung und regelmäßig über den Scheduler.
* Pro Verzeichnis lässt sich Synchronisierung und Anmeldung auf Mitglieder bestimmter Gruppen beschränken. Verschachtelte Mitgliedschaft wird berücksichtigt.
* Pro Verzeichnis einstellbar, was mit Benutzern passiert, die bei einer vollen Synchronisierung nicht mehr gefunden werden: behalten, sperren oder löschen. Liefert die Suche gar nichts, wird nichts angetastet.
* Unter `/admin/group-role-mappings` lassen sich AD Gruppen auf interne Rollen abbilden, zum Beispiel auf `admin`.
* Die Rolle `admin` macht auch AD und LDAP Konten zu Administratoren. Der lokale Administrator bleibt als Absicherung erhalten und lässt sich nicht als letzter herabstufen oder löschen.

## Windows SSO

Den Kerberos Handshake macht der Webserver, nicht Laravel. Der Webserver prüft
das Ticket des Browsers und übergibt die Identität als `REMOTE_USER`, zum
Beispiel `FIRMA\mmuster`. Ab da übernimmt die Middleware
`WindowsSsoAuthenticate`: sie zerlegt den Namen, schlägt den Benutzer im AD
nach, synchronisiert ihn und meldet ihn an. Ohne `REMOTE_USER` passiert nichts,
es erscheint die normale Anmeldeseite.

Die ausführliche Anleitung steht im Assistenten unter Schritt 6. In Kürze:

**IIS:** Windows Authentifizierung aktivieren, anonyme Authentifizierung aus,
Anbieter Reihenfolge `Negotiate` vor `NTLM`, SPN registrieren
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

**nginx** hat kein eigenes Kerberos Modul. Entweder Apache oder IIS als Reverse
Proxy davorstellen, die `REMOTE_USER` als Header weitergeben, oder ein
Zusatzmodul einsetzen. Alternativ gibt es den eingebauten Endpunkt
`/auth/negotiate`, der ohne vorgeschalteten Kerberos Server auskommt. Er prüft
aber nur den gemeldeten Namen, nicht dessen Echtheit gegen den Domain
Controller. Das ist eine bewusste Entscheidung für abgeschottete interne Netze,
siehe Kommentar in `app/Http/Controllers/Auth/NegotiateController.php`.

## OAuth 2.0 und OpenID Connect

| Zweck | Route |
|---|---|
| Discovery | `GET /.well-known/openid-configuration` |
| JWKS | `GET /.well-known/jwks.json` |
| Authorization | `GET /oauth/authorize` |
| Token | `POST /oauth/token` |
| UserInfo | `GET` oder `POST /oauth/userinfo` |
| Revocation | `POST /oauth/revoke` |

Clients werden unter `/admin/applications` angelegt: Redirect URIs, Scopes,
Grant Types, Token Laufzeiten, PKCE Pflicht. Das Client Secret ist nur direkt
nach dem Anlegen im Klartext sichtbar. Die Signaturschlüssel werden unter
`/admin/oidc-keys` rotiert.

Ablauf für einen Client mit Authorization Code und PKCE:

```
1. code_verifier und code_challenge (S256) erzeugen, dazu einen zufaelligen state.
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

Das System ist ein SAML 2.0 Identity Provider. Service Provider werden unter
`/admin/saml-service-providers` eingetragen: Entity ID, ACS URL, SLO URL,
NameID Format, Signaturanforderungen, Attribut Mapping.

| Zweck | Route |
|---|---|
| Metadata global | `GET /saml/metadata` |
| Metadata pro Anwendung | `GET /saml/{application}/metadata` |
| SSO | `GET` oder `POST /saml/sso` |
| Single Logout | `GET` oder `POST /saml/slo` |

Einen SP anbinden:

1. Im SP die IdP Metadata von `/saml/{application}/metadata` einlesen.
2. Im Adminbereich einen Eintrag mit Entity ID und ACS URL des SP anlegen.
3. Attribut Mapping prüfen. Standard: `uid` auf `username`, `mail` auf `email`, `displayName` auf `display_name`, `department` auf `department`, `groups` auf die AD Gruppen.
4. Der SP schickt eine AuthnRequest an `/saml/sso`. Nach der Anmeldung geht eine signierte Assertion per Auto Submit Formular an die ACS URL.

Zertifikate liegen unter `/admin/saml-certificates`: Selbstsignierung, Rotation,
Ablaufwarnung. Signierte AuthnRequests müssen über das POST Binding kommen.

## Sicherheit und Betrieb

* Zwei Faktor Anmeldung (TOTP und Recovery Codes) für lokale Konten unter **Profil, Zwei Faktor**
* Eigene Sitzungen unter **Profil, Meine Sitzungen**, alle Sitzungen unter **Administration, Alle Sessions**, jeweils einsehbar und widerrufbar
* Audit Log unter **Administration, Audit Log**: Anmeldungen, Token Ereignisse, Zustimmungen, SAML, Admin Aktionen, Datensicherungen
* Systemstatus unter **Administration, Systemstatus**: Datenbank, Cache, Dateisystem, Queue, Scheduler, AD Verbindungen, Ablauf von OIDC Schlüsseln und SAML Zertifikaten
* Rate Limiting auf Anmeldung, `/oauth/token`, `/oauth/revoke` und `/saml/sso`
* Versionsnummer im Footer. Die Administration prüft regelmäßig auf neue Releases und zeigt Changelog und Hinweise unter **Administration, Aktualisierungen**

Der Scheduler muss laufen. Er betreibt die AD Synchronisierung, den Heartbeat
für die Statusseite und die Update Prüfung. Bei Docker läuft er als eigener
Container. Ohne Docker genügt ein Eintrag in Cron:

```
* * * * * php artisan schedule:run
```

Die Anwendung terminiert kein TLS. Davor gehört ein Reverse Proxy mit gültigem
Zertifikat. SAML, OIDC und Kerberos setzen HTTPS voraus.

## Tests

```bash
php artisan test
```

## Weitere Dokumentation

| Datei | Inhalt |
|---|---|
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Alle Wege in den Produktivbetrieb: Docker, Skript, von Hand |
| [`docs/BACKUP.md`](docs/BACKUP.md) | Datensicherung erstellen und wiederherstellen |
| [`docs/INSTANZEN.md`](docs/INSTANZEN.md) | Umbenennen, Adresse ändern, mehrere Instanzen (Produktiv und Test) |
| [`deploy/README.md`](deploy/README.md) | Die Skripte für eine dedizierte Linux VM (Installation und Update ohne Downtime) |

## Lizenz

Siehe [`LICENSE`](LICENSE). Nutzung, Änderung und Weitergabe sind erlaubt,
solange der Urheberhinweis erhalten bleibt und bei einem sichtbaren Betrieb ein
Link auf das Ursprungsprojekt gesetzt wird.
