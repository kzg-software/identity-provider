# Produktiv-Deployment (Linux-VM)

Drei Skripte für eine eigene, dedizierte Linux-VM (getestet für Debian/Ubuntu):

| Skript | Zweck |
|---|---|
| `install.sh` | Einmalige Erstinstallation: nginx, PHP-FPM, Zertifikat, Verzeichnisstruktur, erste Code-Auschecke, Scheduler |
| `update.sh` | Neue Version ohne Downtime ausrollen |
| `rollback.sh` | Zurück auf die vorherige (oder eine beliebige ältere) Release |

## Zero-Downtime-Prinzip

Jede Version des Codes liegt in einem eigenen Verzeichnis unter
`/srv/auth-system/releases/<zeitstempel>/`. `/srv/auth-system/current` ist ein
Symlink auf die gerade aktive Release. nginx/PHP-FPM lesen `current/public`
bei **jedem** Request neu auf. Ein Update baut die neue Release komplett fertig
(Code, Composer, Migrationen, Caches, Health-Check), **während die alte
Release weiter live bedient**, und biegt erst danach `current` atomar
(`mv -T`, ein einzelner Dateisystem-Rename) auf die neue Release um. Es gibt
keinen Zeitpunkt, an dem der Webserver neu startet oder Requests fehlschlagen.
Schlägt der Health-Check vor dem Umschalten fehl, bricht das Skript ab und die
alte Release bleibt unverändert aktiv.

Persistente Daten (`.env`, Uploads, Logs, SQLite-Datei) liegen **nicht** in
den Releases, sondern in `/srv/auth-system/shared/` und werden in jede neue
Release symlinkt — jede Release selbst ist zustandslos und beliebig
austauschbar.

## Erstinstallation

```bash
sudo DOMAIN=auth.example.local \
     GIT_REF=main \
     DB_CONNECTION=mysql \
     DB_PASSWORD='ein-sicheres-passwort' \
     bash deploy/install.sh
```

Wichtige Umgebungsvariablen (alle optional, siehe Defaults im Skriptkopf):

| Variable | Default | Bedeutung |
|---|---|---|
| `DOMAIN` | `auth.example.local` | Domain, für die das Zertifikat/vhost erstellt wird |
| `GIT_URL` | das Auth-Repo | Git-Remote |
| `GIT_REF` | `main` | Branch/Tag, der ausgecheckt wird |
| `APP_ROOT` | `/srv/auth-system` | Basisverzeichnis auf der VM |
| `DB_CONNECTION` | `sqlite` | `sqlite` oder `mysql` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | - | nur bei `mysql` relevant; MariaDB wird bei Bedarf automatisch installiert und die DB/der Benutzer angelegt |
| `CERT_CHAIN_PATH`, `CERT_KEY_PATH` | leer | Pfade zu einem **bereits vorhandenen** Zertifikat/Key (z.B. von der internen CA). Wenn gesetzt, wird **kein** selbstsigniertes Zertifikat erzeugt, sondern dieses verwendet. |
| `GIT_USERNAME`, `GIT_TOKEN` | - | optional vorab setzen, um die interaktive Abfrage der Git-Zugangsdaten zu überspringen (z.B. beim automatisierten Aufsetzen) |

Nach dem Lauf: Domain im Browser öffnen — der **fachliche Web-Installer**
(Datenbank-Bestätigung, Systemname, Administrator-Konto, Active-Directory-
Verbindung) läuft wie gewohnt im Browser weiter (siehe `/install`-Routen im
Code), das Shell-Skript kümmert sich nur um die Infrastruktur.

## Update (ohne Downtime)

```bash
sudo bash deploy/update.sh          # rollt GIT_REF (Default main) aus
sudo bash deploy/update.sh v1.4.0   # oder einen bestimmten Branch/Tag
```

Ablauf: Code auschecken → Composer → Migrationen → Caches → Health-Check
(`php artisan about` + echter HTTP-Request gegen `/up` über einen kurz
gestarteten, isolierten PHP-Testserver) → **erst dann** `current` umschalten
→ PHP-FPM reload → alte Releases aufräumen (Standard: die letzten 5 bleiben
erhalten, mit `KEEP_RELEASES=10` anpassbar).

## Rollback

```bash
sudo bash deploy/rollback.sh previous       # letzte Release vor dem letzten Update
sudo bash deploy/rollback.sh 20260825120000 # konkreter Zeitstempel aus releases/
```

## Git-Zugangsdaten

Der Zugangstoken für den Git-Server (Default: `https://github.com/kzg-software/identity-provider.git`
— per `GIT_URL` überschreibbar) wird **niemals** in eine
Datei geschrieben, die Teil des Repos ist. `install.sh` fragt ihn beim
ersten Lauf interaktiv ab (oder nimmt ihn aus `GIT_USERNAME`/`GIT_TOKEN`) und
legt ihn in `/etc/auth-system/git-credentials` ab — root-only lesbar
(`chmod 600`), außerhalb von `/srv/auth-system` und damit außerhalb jeder
Release. Git nutzt diese Datei danach automatisch über
`credential.helper store` bei jedem `git clone`/`fetch` in `update.sh`, ohne
dass der Token erneut eingegeben oder irgendwo im Klartext im Code landen
muss.

## Logs & Status prüfen

```bash
tail -f /srv/auth-system/shared/storage/logs/laravel.log
journalctl -u auth-system-scheduler.service -f    # Scheduler-Läufe (jede Minute)
systemctl list-timers auth-system-scheduler.timer
tail -f /var/log/nginx/error.log
```

## Windows SSO (Kerberos/SPNEGO) auf dieser Linux-VM

nginx kann Kerberos/SPNEGO nicht selbst terminieren. Zwei Optionen:

1. **Apache mit `mod_auth_gssapi`** als Reverse-Proxy vor diesem nginx-Setup
   stellen (Apache übernimmt die Kerberos-Validierung und reicht
   `REMOTE_USER` durch — die bereits vorhandene `WindowsSsoAuthenticate`-
   Middleware im Code verarbeitet das automatisch).
2. **`/auth/negotiate`** nutzen — der bereits im Code vorhandene In-App-
   NTLM-Endpoint, der genau für ein reines nginx-Setup ohne vorgeschalteten
   Kerberos-fähigen Webserver gebaut wurde. **Wichtig:** Dieses Verfahren
   validiert nur den vom Browser gemeldeten Windows-Benutzernamen, nicht
   kryptografisch dessen Authentizität gegen den Domain Controller — bewusst
   akzeptiert für dieses interne Netz, siehe Kommentar in
   `app/Http/Controllers/Auth/NegotiateController.php`.
