# Deployment

Es gibt drei Wege, die Anwendung zu betreiben. Alle drei sind vollständig
unterstützt. Wähle nach Umgebung und Vorlieben.

| Weg | Wann | Aufwand |
|---|---|---|
| A, manuell ohne Docker und ohne Skript | volle Kontrolle, bestehende Serverlandschaft, kein Docker | hoch |
| B, mit Skript (`deploy/install.sh`) | frische Debian- oder Ubuntu-VM, Updates ohne Downtime | niedrig |
| C, mit Docker (`docker compose`) | Container-Infrastruktur vorhanden, reproduzierbar | niedrig |

## Was für alle Wege gilt

* PHP 8.3 oder neuer, entwickelt und getestet mit 8.4. Extensions: `openssl`, `pdo`, `pdo_mysql` oder `pdo_sqlite`, `ldap`, `xml` und `dom`, `curl`, `mbstring`, `intl`, `sodium`, `gd`, `zip`, `bcmath`, `opcache`.
* Kein Node, npm oder Vite. Das Frontend läuft über Blade und CDN.
* Die App terminiert kein TLS. Davor gehört ein Reverse Proxy mit gültigem Zertifikat (nginx, Apache, Traefik, IIS-ARR, Caddy). SAML, OIDC und Kerberos brauchen HTTPS.
* Nach der Infrastruktur läuft der fachliche Web-Installer im Browser (`/install`): Datenbank bestätigen, Systemname, lokales Administrator-Konto, optional Active Directory. Danach sperrt er sich selbst (`system_settings.installed = 1`).
* Der Scheduler muss laufen (`php artisan schedule:run` jede Minute). Er betreibt AD-Sync, den Status-Heartbeat und die Update-Prüfung.
* Ein Queue-Worker (`php artisan queue:work`) ist aktuell optional, aber empfohlen.

# Weg A: manuell

Für ein Setup, das du selbst Schritt für Schritt kontrollierst. Beispiel:
Debian oder Ubuntu mit nginx und PHP-FPM. Für Apache oder IIS sinngemäß.

## A.1 Pakete

```bash
sudo apt update
sudo apt install -y nginx git unzip \
  php8.4-fpm php8.4-cli php8.4-xml php8.4-curl php8.4-mbstring php8.4-intl \
  php8.4-sqlite3 php8.4-mysql php8.4-ldap php8.4-bcmath php8.4-gd php8.4-zip \
  php8.4-opcache
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Prüfen, dass alle Extensions geladen sind:

```bash
php -m | grep -Ei 'openssl|pdo|ldap|dom|curl|mbstring|intl|sodium|gd|zip|bcmath|opcache'
```

## A.2 Datenbank

MariaDB oder MySQL, für den Produktivbetrieb empfohlen:

```bash
sudo apt install -y mariadb-server
sudo mysql <<'SQL'
CREATE DATABASE auth_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'auth_system'@'localhost' IDENTIFIED BY 'EIN-SICHERES-PASSWORT';
GRANT ALL PRIVILEGES ON auth_system.* TO 'auth_system'@'localhost';
FLUSH PRIVILEGES;
SQL
```

SQLite, klein oder für Tests: nichts zu installieren, nur später eine Datei mit
Schreibrechten für den Webserver-Benutzer.

## A.3 Code und Konfiguration

```bash
sudo mkdir -p /var/www/auth-system
sudo chown "$USER" /var/www/auth-system
git clone https://github.com/kzg-software/identity-provider.git /var/www/auth-system
cd /var/www/auth-system

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

`.env` anpassen, mindestens:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://auth.example.com

# MariaDB:
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=auth_system
DB_USERNAME=auth_system
DB_PASSWORD=EIN-SICHERES-PASSWORT
# ODER SQLite:
# DB_CONNECTION=sqlite
# DB_DATABASE=/var/www/auth-system/database/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# Nur falls ein Reverse Proxy davor steht, praktisch immer:
TRUSTED_PROXIES=127.0.0.1
```

Hinweis zu `TRUSTED_PROXIES` und Config-Cache: sobald du unten
`php artisan config:cache` nutzt, wird die `.env` zur Laufzeit nicht mehr
gelesen. `TRUSTED_PROXIES` dann entweder als echte Umgebungsvariable setzen
(`env[TRUSTED_PROXIES]='127.0.0.1'` im FPM-Pool, siehe A.5) oder auf den
Config-Cache verzichten.

## A.4 Initialisieren

```bash
# SQLite: Datei anlegen
# touch database/database.sqlite

php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Rechte fuer den Webserver-Benutzer (www-data)
sudo chown -R www-data:www-data storage bootstrap/cache
# bei SQLite zusaetzlich:
# sudo chown www-data:www-data database database/database.sqlite
```

## A.5 PHP-FPM-Pool

`/etc/php/8.4/fpm/pool.d/auth-system.conf`:

```ini
[auth-system]
user = www-data
group = www-data
listen = /run/php/auth-system.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
; Uploads (Logo, Favicon, Login-Hintergrund). PHP-Defaults 2M/8M sind zu klein
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 24M
php_admin_value[memory_limit] = 256M
; optional, siehe Hinweis in A.3:
; env[TRUSTED_PROXIES] = 127.0.0.1
```

```bash
sudo systemctl restart php8.4-fpm
```

## A.6 nginx-Vhost

`/etc/nginx/sites-available/auth-system.conf`:

```nginx
server {
    listen 80;
    server_name auth.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name auth.example.com;

    ssl_certificate     /etc/ssl/auth-system/fullchain.pem;
    ssl_certificate_key /etc/ssl/auth-system/privkey.pem;

    root /var/www/auth-system/public;
    index index.php;
    client_max_body_size 20m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/auth-system.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/auth-system.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Zertifikat: entweder von der internen CA (`fullchain.pem` und `privkey.pem`
nach `/etc/ssl/auth-system/` legen) oder selbstsigniert:

```bash
sudo mkdir -p /etc/ssl/auth-system
sudo openssl req -x509 -nodes -newkey rsa:4096 -days 825 \
  -keyout /etc/ssl/auth-system/privkey.pem \
  -out /etc/ssl/auth-system/fullchain.pem \
  -subj "/CN=auth.example.com" -addext "subjectAltName=DNS:auth.example.com"
```

## A.7 Scheduler und Queue über systemd

`/etc/systemd/system/auth-system-scheduler.service`:

```ini
[Unit]
Description=Auth-System Scheduler
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/auth-system
ExecStart=/usr/bin/php artisan schedule:run
```

`/etc/systemd/system/auth-system-scheduler.timer`:

```ini
[Unit]
Description=Auth-System Scheduler jede Minute
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
[Install]
WantedBy=timers.target
```

`/etc/systemd/system/auth-system-queue.service`:

```ini
[Unit]
Description=Auth-System Queue Worker
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/auth-system
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now auth-system-scheduler.timer auth-system-queue.service
```

## A.8 Web-Installer

`https://auth.example.com` im Browser öffnen und durch die Schritte klicken.
Bei der Datenbank die gleichen Werte wie in `.env` eintragen.

## A.9 Updates von Hand

```bash
cd /var/www/auth-system
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan storage:link
sudo systemctl reload php8.4-fpm
sudo systemctl restart auth-system-queue.service
```

Bei diesem Weg gibt es einen kurzen Moment, in dem neuer Code und alter OPcache
aufeinandertreffen. Für echtes Zero-Downtime siehe Weg B.

# Weg B: mit Skript

Für eine dedizierte Debian- oder Ubuntu-VM. Das Skript baut die komplette
Infrastruktur auf (nginx, PHP-FPM, Zertifikat, systemd-Scheduler) und legt eine
Verzeichnisstruktur für Updates ohne Downtime an (`releases/`, `shared/`,
`current`-Symlink). Details stehen in [`../deploy/README.md`](../deploy/README.md).

## B.1 Erstinstallation

```bash
git clone https://github.com/kzg-software/identity-provider.git /opt/auth-src && cd /opt/auth-src

sudo DOMAIN=auth.example.com \
     GIT_REF=main \
     DB_CONNECTION=mysql \
     DB_PASSWORD='EIN-SICHERES-PASSWORT' \
     bash deploy/install.sh
```

Wichtige Variablen, alle optional, Defaults im Skriptkopf:

| Variable | Bedeutung |
|---|---|
| `DOMAIN` | Domain für Zertifikat und vhost |
| `GIT_URL`, `GIT_REF` | Remote und Branch oder Tag |
| `DB_CONNECTION` | `sqlite` oder `mysql`, MariaDB wird bei Bedarf mitinstalliert |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | nur bei `mysql` |
| `CERT_CHAIN_PATH`, `CERT_KEY_PATH` | vorhandenes Zertifikat statt selbstsigniert |
| `GIT_USERNAME`, `GIT_TOKEN` | Git-Zugang vorab, sonst interaktive Abfrage |

Danach `https://auth.example.com` öffnen und den Web-Installer durchlaufen.

## B.2 Update ohne Downtime

```bash
sudo bash deploy/update.sh            # rollt GIT_REF aus, Default main
sudo bash deploy/update.sh v1.4.0     # oder einen bestimmten Tag
```

Ablauf: neue Release komplett vorbereiten, Health-Check (`about` plus echter
HTTP-Request gegen `/up`), erst dann `current` atomar umschalten, dann PHP-FPM
neu laden. Schlägt der Health-Check fehl, bleibt die alte Release aktiv.

## B.3 Rollback

```bash
sudo bash deploy/rollback.sh previous
sudo bash deploy/rollback.sh 20260825120000
```

## B.4 Anbindung an eine Pipeline

`deploy/update.sh` lässt sich per SSH aus einer Pipeline aufrufen, etwa nach
einem erfolgreichen Test-Workflow:

```yaml
# .github/workflows/deploy.yml (Beispiel, nicht mitgeliefert)
- name: Deploy
  uses: appleboy/ssh-action@v1
  with:
    host: ${{ secrets.DEPLOY_HOST }}
    username: root
    key: ${{ secrets.DEPLOY_SSH_KEY }}
    script: cd /opt/auth-src && git pull && bash deploy/update.sh main
```

# Weg C: mit Docker

Ein einziges Image (`Dockerfile`) enthält PHP 8.4 (FPM), nginx und supervisor.
Über `CONTAINER_ROLE` übernimmt derselbe Container eine Rolle:

| `CONTAINER_ROLE` | Prozess |
|---|---|
| `app` (Default) | nginx und php-fpm, Weboberfläche auf Port 8080 |
| `scheduler` | `php artisan schedule:work` |
| `queue` | `php artisan queue:work` |

Mitgeliefert:

| Datei | Zweck |
|---|---|
| `Dockerfile`, `docker/` | Image-Definition plus nginx, php, supervisor, entrypoint |
| `docker-compose.yml` | Produktions-Stack: App, Scheduler, Queue, MariaDB, zieht ein fertiges Image |
| `docker-compose.build.yml` | Override: Image lokal bauen statt ziehen |
| `docker-compose.sqlite.yml` | Minimal-Stack: nur die App-Container plus SQLite, baut lokal |
| `.env.docker.example` | Vorlage für `.env` im Docker-Betrieb |
| `.github/workflows/docker-image.yml` | CI baut und veröffentlicht das Image |

## C.1 Voraussetzungen

* Docker Engine 24 oder neuer mit Compose v2 (`docker compose`, nicht `docker-compose`).
* Ein Reverse Proxy mit TLS vor dem Container-Port, siehe C.7. Für reine lokale Tests siehe C.6.

## C.2 Schnellstart mit SQLite

Baut lokal, keine Registry nötig.

```bash
git clone https://github.com/kzg-software/identity-provider.git auth-system && cd auth-system

cp .env.docker.example .env
```

In `.env` anpassen:

```dotenv
APP_URL=https://auth.example.com     # bzw. http://localhost:8080 fuer lokalen Test
DB_CONNECTION=sqlite
SESSION_SECURE_COOKIE=true            # false, wenn ohne HTTPS getestet wird
TRUSTED_PROXIES=*
AUTO_MIGRATE=true
```

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
docker compose -f docker-compose.sqlite.yml logs -f app
```

Der Entrypoint erledigt automatisch: `.env` prüfen, `APP_KEY` erzeugen,
SQLite-Datei anlegen, `migrate --force` (wegen `AUTO_MIGRATE=true`),
`storage:link`, Caches. Danach `http://<host>:8080` oder über den Proxy zum
Web-Installer.

Persistenz: `./.env` als Bind-Mount plus die Named Volumes `app-storage` und
`app-database`.

## C.3 Produktions-Stack mit MariaDB und fertigem Image

```bash
cp .env.docker.example .env
```

In `.env` alle Platzhalter setzen:

```dotenv
APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest   # Ergebnis-Image der CI
APP_PORT=8080
APP_URL=https://auth.example.com
TRUSTED_PROXIES=*

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=auth_system
DB_USERNAME=auth_system
DB_PASSWORD=LANGES-PASSWORT-1
DB_ROOT_PASSWORD=LANGES-PASSWORT-2
AUTO_MIGRATE=true

SESSION_SECURE_COOKIE=true
```

```bash
docker login ghcr.io                 # einmalig, fuer private Images
docker compose up -d
docker compose logs -f app
```

Compose startet der Reihe nach: `db` (MariaDB mit Healthcheck), dann `app`
(wartet auf `db`, migriert, serviert), dann `scheduler` und `queue` (warten auf
`app`).

Update auf ein neueres Image:

```bash
docker compose pull
docker compose up -d
```

## C.4 Produktions-Stack selbst bauen

Wenn kein Zugriff auf die Registry gewünscht ist:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

Danach genügt wieder `docker compose up -d`. Das Image ist als
`auth-system:local` getaggt, `APP_IMAGE` in `.env` entsprechend leer lassen
oder auf `auth-system:local` setzen.

## C.5 Betrieb

```bash
docker compose ps
docker compose logs -f app scheduler queue
docker compose exec app php artisan about
docker compose exec app php artisan tinker

# nach jeder .env-Aenderung:
docker compose restart app scheduler queue

# manuell migrieren, falls AUTO_MIGRATE=false:
docker compose exec app php artisan migrate --force

# Backup MariaDB:
docker compose exec db mariadb-dump -u root -p"$DB_ROOT_PASSWORD" auth_system > backup.sql

# Backup Uploads und Logs:
docker run --rm -v auth-system_app-storage:/s -v "$PWD":/b alpine \
  tar czf /b/storage-backup.tar.gz -C /s .
```

Performance, optional: nach abgeschlossener Installation kann in der `app`-Rolle
einmalig `php artisan config:cache` laufen. Danach bei jeder `.env`-Änderung
`php artisan config:clear` nicht vergessen. Der Entrypoint macht das beim
Neustart ohnehin.

## C.6 Rein lokaler Test ohne Proxy und HTTPS

In `.env`:

```dotenv
APP_URL=http://localhost:8080
SESSION_SECURE_COOKIE=false
TRUSTED_PROXIES=
```

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
# http://localhost:8080
```

## C.7 Reverse Proxy davorstellen

Der Container spricht HTTP auf Port 8080. Der Proxy terminiert TLS und muss
`X-Forwarded-Proto`, `X-Forwarded-Host` und `X-Forwarded-For` setzen. In der App
über `TRUSTED_PROXIES` freigeben. `*` ist in Ordnung, wenn der Container nur
über den Proxy erreichbar ist, dann Port 8080 nicht nach außen mappen, sondern
nur ins Proxy-Netz.

nginx:

```nginx
server {
    listen 443 ssl;
    server_name auth.example.com;
    ssl_certificate     /etc/letsencrypt/live/auth.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/auth.example.com/privkey.pem;
    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
    }
}
```

Traefik, Labels am `app`-Service in einer Compose-Override-Datei:

```yaml
services:
  app:
    labels:
      - traefik.enable=true
      - traefik.http.routers.auth.rule=Host(`auth.example.com`)
      - traefik.http.routers.auth.entrypoints=websecure
      - traefik.http.routers.auth.tls.certresolver=le
      - traefik.http.services.auth.loadbalancer.server.port=8080
```

## C.8 Windows-SSO im Docker-Betrieb

nginx im Container kann Kerberos und SPNEGO nicht terminieren, genau wie im
bare-metal-Setup. Zwei Optionen, identisch zum VM-Betrieb:

1. Einen Apache mit `mod_auth_gssapi` oder IIS-ARR als Proxy vor den Container stellen. Er macht die Kerberos-Validierung und reicht `REMOTE_USER` als Header durch, die Middleware `WindowsSsoAuthenticate` verarbeitet das. `REMOTE_USER` per FastCGI oder Proxy-Header übergeben.
2. Den eingebauten Endpunkt `/auth/negotiate` nutzen. Kein vorgeschalteter Kerberos-Server nötig, er validiert nur den gemeldeten Namen, nicht kryptografisch. Bewusste Entscheidung, siehe `README.md` und `NegotiateController`.

# Automatischer Image-Build

`.github/workflows/docker-image.yml` baut das Image und veröffentlicht es.

## Auf GitHub

Ohne weitere Konfiguration:

* Git-Tag `v1.2.3`, also ein Release, ergibt `:1.2.3`, `:1.2` und `:latest`
* Commit auf `main` ergibt `:dev`, `:main` und `:sha-<kurz>`
* Commit auf jedem anderen Branch ergibt `:<branchname>` und `:sha-<kurz>`
* Pull Request wird nur gebaut, nicht gepusht

`:latest` entsteht also nur bei einem Versions-Tag, `:dev` bei jedem Commit auf
dem Default-Branch. Produktionsserver ziehen `:latest` oder ein festes `:1.2.3`,
Staging und Dev ziehen `:dev`. Das Image wird nur für `linux/amd64` gebaut,
ARM und Apple Silicon werden nicht unterstützt.

Die Registry-Anmeldung läuft über das automatische `GITHUB_TOKEN`
(`permissions: packages: write` ist im Workflow gesetzt). Nach dem ersten Lauf
das Package unter GitHub, Packages bei Bedarf auf public stellen oder auf den
Servern mit einem Read-Token `docker login ghcr.io` ausführen.

`.env`: `APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest`.

## Auf Gitea oder einer anderen Forge

Gitea Actions ist mit GitHub Actions kompatibel und liest denselben Workflow.
Nur das Registry-Ziel umstellen, im Repo unter Settings, Actions, Variables und
Secrets:

| Typ | Name | Beispiel |
|---|---|---|
| Variable | `REGISTRY` | `git.example.org` |
| Variable | `IMAGE_NAME` | `kzg-software/identity-provider` |
| Variable | `REGISTRY_USER` | `ci-bot` |
| Secret | `REGISTRY_TOKEN` | Access-Token mit `write:package` |

Voraussetzung: ein Act-Runner ist für das Repo registriert und die
Container-Registry ist in Gitea aktiviert. `.env`:
`APP_IMAGE=git.example.org/kzg-software/identity-provider:latest`.

## Tests-Workflow

`.github/workflows/tests.yml` läuft bei jedem Push und PR: PHP 8.4,
`composer install`, `php artisan test` (SQLite in-memory).

# Version und Update-Prüfung

Der aktuelle Release-Stand steht im Footer jeder Seite, verlinkt auf die
passende Release-Seite im Repo, und wird in der Administration unter
Aktualisierungen ausgewertet.

Woher die Versionsnummer kommt:

| Betrieb | Quelle |
|---|---|
| Docker | Build-Arg `APP_VERSION`. Das CI-Image setzt es auf den Git-Tag (`v1.4.2`), Dev-Builds auf `dev` |
| Weg B | `deploy/install.sh` und `deploy/update.sh` schreiben eine Datei `VERSION` aus `git describe --tags` |
| sonst | `dev` |

Die Update-Prüfung läuft zweimal täglich über den Scheduler-Job
`updates:check` und zusätzlich beim Öffnen der Admin-Seiten. Sie fragt die
GitHub-Releases-API ab. Ergebnis und Changelog landen im Cache, bei einer
neueren Version erscheint ein Hinweis auf dem Dashboard und im Footer. Ohne
Scheduler wird die Prüfung nach dem Ausliefern der Antwort nachgeholt. Sie ist
fest eingebaut und lässt sich nicht abschalten.

| Variable | Default | Zweck |
|---|---|---|
| `UPDATE_REPOSITORY` | `kzg-software/identity-provider` | `owner/repo` für die Prüfung |
| `UPDATE_REPOSITORY_URL` | `https://github.com/kzg-software/identity-provider` | Links im Footer und Changelog |
| `UPDATE_GITHUB_TOKEN` | leer | Nur bei privatem Repo oder gegen Rate-Limits, Recht `Contents: read` |

Manuell prüfen: `php artisan updates:check --force` oder
`docker compose exec app php artisan updates:check --force`.

# Fehlersuche

| Symptom | Ursache und Lösung |
|---|---|
| `/install` endet mit 500, `system_settings` fehlt | Migrationen liefen nicht. Weg C: `AUTO_MIGRATE=true` setzen oder `docker compose exec app php artisan migrate --force`. |
| Login-Schleife, keine Session | `SESSION_SECURE_COOKIE=true` ohne HTTPS. Proxy davorsetzen oder für den Test `false`. |
| Falsche Redirect-URLs, `http` statt `https` oder falscher Host | `APP_URL` korrekt setzen und `TRUSTED_PROXIES` für den Proxy freigeben. |
| Upload meldet "Datei ist zu groß" bei Logo, Favicon, Hintergrund | PHP-Limits. Weg A und B: FPM-Pool (`upload_max_filesize`, `post_max_size`). Weg C: im Image bereits auf 20M/24M. nginx davor braucht `client_max_body_size 20m`. |
| Hochgeladenes Logo oder Hintergrund liefert 404 | `php artisan storage:link` fehlt. Weg C: erledigt der Entrypoint, das Volume `app-storage` muss gemountet sein. |
| Statusseite meldet "Kein Scheduler-Heartbeat" | Der Scheduler-Dienst oder -Container läuft nicht. |
| `ldap`-Funktionen tot | `php -m \| grep ldap`, die Extension fehlt (Weg A). Im Docker-Image enthalten. |
| Container startet, `/up` bleibt unhealthy | `docker compose logs app`, meist ist die DB nicht erreichbar oder `APP_KEY` leer bei nicht gemounteter `.env`. |
