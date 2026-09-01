# Produktiv-Deployment auf einer Linux-VM

Drei Skripte für eine eigene, dedizierte Linux-VM. Getestet auf Debian und
Ubuntu.

| Skript | Zweck |
|---|---|
| `install.sh` | Einmalige Erstinstallation: nginx, PHP-FPM, Zertifikat, Verzeichnisstruktur, erster Checkout, Scheduler |
| `update.sh` | Neue Version ohne Downtime ausrollen |
| `rollback.sh` | Zurück auf die vorherige oder eine beliebige ältere Release |

## Wie Zero-Downtime funktioniert

Jede Version des Codes liegt in einem eigenen Verzeichnis unter
`/srv/auth-system/releases/<zeitstempel>/`. `/srv/auth-system/current` ist ein
Symlink auf die gerade aktive Release, und nginx und PHP-FPM lesen
`current/public` bei jedem Request neu auf.

Ein Update baut die neue Release komplett fertig (Code, Composer, Migrationen,
Caches, Health-Check), während die alte Release weiter live bedient. Erst danach
biegt es `current` mit einem einzigen atomaren Rename (`mv -T`) auf die neue
Release um. Es gibt keinen Moment, in dem der Webserver neu startet oder
Requests scheitern. Schlägt der Health-Check vor dem Umschalten fehl, bricht das
Skript ab und die alte Release bleibt aktiv.

Persistente Daten (`.env`, Uploads, Logs, SQLite-Datei) liegen nicht in den
Releases, sondern in `/srv/auth-system/shared/` und werden in jede neue Release
symlinkt. Jede Release selbst ist zustandslos und beliebig austauschbar.

## Erstinstallation

```bash
sudo DOMAIN=auth.example.local \
     GIT_REF=main \
     DB_CONNECTION=mysql \
     DB_PASSWORD='ein-sicheres-passwort' \
     bash deploy/install.sh
```

Alle Umgebungsvariablen sind optional, die Defaults stehen im Skriptkopf.

| Variable | Default | Bedeutung |
|---|---|---|
| `DOMAIN` | `auth.example.local` | Domain für Zertifikat und vhost |
| `GIT_URL` | das Projekt-Repo | Git-Remote |
| `GIT_REF` | `main` | Branch oder Tag, der ausgecheckt wird |
| `APP_ROOT` | `/srv/auth-system` | Basisverzeichnis auf der VM |
| `DB_CONNECTION` | `sqlite` | `sqlite` oder `mysql` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | leer | Nur bei `mysql`. MariaDB wird bei Bedarf mitinstalliert, die DB und der Benutzer werden angelegt |
| `CERT_CHAIN_PATH`, `CERT_KEY_PATH` | leer | Pfade zu einem vorhandenen Zertifikat und Key, etwa von der internen CA. Wenn gesetzt, wird kein selbstsigniertes Zertifikat erzeugt |
| `GIT_USERNAME`, `GIT_TOKEN` | leer | Vorab setzen, um die interaktive Abfrage der Git-Zugangsdaten zu überspringen |

Nach dem Lauf die Domain im Browser öffnen. Der fachliche Web-Installer
(Datenbank bestätigen, Systemname, Administrator-Konto, Active Directory) läuft
dann wie gewohnt weiter. Das Shell-Skript kümmert sich nur um die
Infrastruktur.

## Update ohne Downtime

```bash
sudo bash deploy/update.sh          # rollt GIT_REF aus, Default main
sudo bash deploy/update.sh v1.4.0   # oder einen bestimmten Branch oder Tag
```

Ablauf: Code auschecken, Composer, Migrationen, Caches, Health-Check
(`php artisan about` plus echter HTTP-Request gegen `/up` über einen kurz
gestarteten, isolierten PHP-Testserver). Erst danach wird `current`
umgeschaltet, dann PHP-FPM neu geladen und alte Releases aufgeräumt. Standard
sind die letzten fünf, anpassbar über `KEEP_RELEASES=10`.

## Rollback

```bash
sudo bash deploy/rollback.sh previous       # Release vor dem letzten Update
sudo bash deploy/rollback.sh 20260825120000 # konkreter Zeitstempel aus releases/
```

## Git-Zugangsdaten

Der Zugangstoken für den Git-Server (Default
`https://github.com/kzg-software/identity-provider.git`, über `GIT_URL`
überschreibbar) wird nie in eine Datei geschrieben, die Teil des Repos ist.
`install.sh` fragt ihn beim ersten Lauf interaktiv ab oder nimmt ihn aus
`GIT_USERNAME` und `GIT_TOKEN` und legt ihn in
`/etc/auth-system/git-credentials` ab: nur für root lesbar (`chmod 600`),
außerhalb von `/srv/auth-system` und damit außerhalb jeder Release. Git nutzt
diese Datei danach automatisch über `credential.helper store` bei jedem
`git clone` und `fetch` in `update.sh`.

## Logs und Status prüfen

```bash
tail -f /srv/auth-system/shared/storage/logs/laravel.log
journalctl -u auth-system-scheduler.service -f
systemctl list-timers auth-system-scheduler.timer
tail -f /var/log/nginx/error.log
```

## Windows-SSO auf dieser Linux-VM

nginx kann Kerberos und SPNEGO nicht selbst terminieren. Zwei Optionen:

1. Einen Apache mit `mod_auth_gssapi` als Reverse Proxy vor dieses nginx-Setup stellen. Apache übernimmt die Kerberos-Validierung und reicht `REMOTE_USER` durch, die vorhandene Middleware `WindowsSsoAuthenticate` verarbeitet das automatisch.
2. Den eingebauten Endpunkt `/auth/negotiate` nutzen. Er ist für ein reines nginx-Setup ohne vorgeschalteten Kerberos-Server gebaut. Wichtig: dieses Verfahren validiert nur den vom Browser gemeldeten Windows-Benutzernamen, nicht kryptografisch dessen Echtheit gegen den Domain Controller. Bewusst akzeptiert für dieses interne Netz, siehe Kommentar in `app/Http/Controllers/Auth/NegotiateController.php`.
