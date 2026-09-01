# Deployment – die drei Wege

Das Auth-System lässt sich auf drei Arten betreiben. Alle drei sind
vollständig unterstützt; wähle nach Umgebung und Vorlieben:

| Weg | Wann | Aufwand | Kapitel |
|---|---|---|---|
| **A – manuell, ohne Docker, ohne Skript** | volle Kontrolle, bestehende Server-Landschaft, kein Docker erwünscht | hoch | [→ A](#a--manuell-ohne-docker-ohne-skript) |
| **B – mit Skript** (`deploy/install.sh`) | frische Debian/Ubuntu-VM, Zero-Downtime-Updates gewünscht | niedrig | [→ B](#b--mit-skript-deployinstallsh) |
| **C – mit Docker** (vordefinierte `docker-compose`) | Container-Infrastruktur vorhanden, reproduzierbar, schnell | niedrig | [→ C](#c--mit-docker-compose) |

Gemeinsame Grundlagen (gelten für **alle** Wege):

- **PHP 8.3+** (entwickelt/getestet mit 8.4). Extensions: `openssl`, `pdo`,
  `pdo_mysql`/`pdo_sqlite`, `ldap`, `xml`/`dom`, `curl`, `mbstring`, `intl`,
  `sodium`, `gd`, `zip`, `bcmath`, `opcache`.
- **Kein Node/npm/Vite** – das Frontend läuft über Blade + CDN.
- **TLS wird nicht von der App terminiert.** Produktiv gehört ein Reverse
  Proxy mit gültigem Zertifikat davor (nginx, Apache, Traefik, IIS-ARR,
  nginx-proxy-manager, Caddy …). SAML/OIDC/Kerberos brauchen HTTPS.
- Nach der Infrastruktur-Einrichtung läuft der **fachliche Web-Installer**
  im Browser (`/install`): Datenbank bestätigen, Systemname, lokales
  Administrator-Konto, optional Active Directory. Danach sperrt er sich
  selbst (`system_settings.installed = 1`).
- **Scheduler** muss laufen (`php artisan schedule:run` jede Minute) – er
  betreibt AD-Sync und den Status-Heartbeat.
- **Queue-Worker** (`php artisan queue:work`) – aktuell optional, aber
  empfohlen, damit künftige Hintergrundjobs greifen.

---

## A – manuell, ohne Docker, ohne Skript

Für ein Setup, das du selbst Schritt für Schritt kontrollierst. Beispiel:
Debian/Ubuntu mit nginx + PHP-FPM. Für Apache/IIS sinngemäß.

### A.1 Pakete

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

Prüfen, dass wirklich alle Extensions geladen sind:

```bash
php -m | grep -Ei 'openssl|pdo|ldap|dom|curl|mbstring|intl|sodium|gd|zip|bcmath|opcache'
```

### A.2 Datenbank

**Variante MariaDB/MySQL** (produktiv empfohlen):

```bash
sudo apt install -y mariadb-server
sudo mysql <<'SQL'
CREATE DATABASE auth_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'auth_system'@'localhost' IDENTIFIED BY 'EIN-SICHERES-PASSWORT';
GRANT ALL PRIVILEGES ON auth_system.* TO 'auth_system'@'localhost';
FLUSH PRIVILEGES;
SQL
```

**Variante SQLite** (klein / Test): nichts zu installieren, nur später eine
Datei mit Schreibrechten für den Webserver-Benutzer.

### A.3 Code & Konfiguration

```bash
sudo mkdir -p /var/www/auth-system
sudo chown "$USER" /var/www/auth-system
git clone https://github.com/kzg-software/identity-provider.git /var/www/auth-system
cd /var/www/auth-system

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

`.env` anpassen – mindestens:

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

# Nur falls ein Reverse Proxy davor steht (praktisch immer):
TRUSTED_PROXIES=127.0.0.1
```

> **Hinweis zu `TRUSTED_PROXIES` + Config-Cache:** Wenn du unten
> `php artisan config:cache` nutzt, wird die `.env` zur Laufzeit **nicht**
> mehr gelesen. `TRUSTED_PROXIES` dann entweder als echte Umgebungsvariable
> setzen (`env[TRUSTED_PROXIES]='127.0.0.1'` im FPM-Pool, siehe A.5) oder
> auf den Config-Cache verzichten.

### A.4 Initialisieren

```bash
# SQLite: Datei anlegen
# touch database/database.sqlite

php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Rechte für den Webserver-Benutzer (www-data)
sudo chown -R www-data:www-data storage bootstrap/cache
# bei SQLite zusätzlich:
# sudo chown www-data:www-data database database/database.sqlite
```

### A.5 PHP-FPM-Pool

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
; Uploads (Logo/Favicon/Login-Hintergrund) – PHP-Defaults 2M/8M sind zu klein
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 24M
php_admin_value[memory_limit] = 256M
; optional, siehe Hinweis in A.3:
; env[TRUSTED_PROXIES] = 127.0.0.1
```

```bash
sudo systemctl restart php8.4-fpm
```

### A.6 nginx-Vhost

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

Zertifikat: entweder von der internen CA (`fullchain.pem` + `privkey.pem`
nach `/etc/ssl/auth-system/` legen) oder selbstsigniert:

```bash
sudo mkdir -p /etc/ssl/auth-system
sudo openssl req -x509 -nodes -newkey rsa:4096 -days 825 \
  -keyout /etc/ssl/auth-system/privkey.pem \
  -out /etc/ssl/auth-system/fullchain.pem \
  -subj "/CN=auth.example.com" -addext "subjectAltName=DNS:auth.example.com"
```

### A.7 Scheduler + Queue (systemd)

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

### A.8 Web-Installer

`https://auth.example.com` im Browser öffnen → durch die Schritte klicken.
Bei der Datenbank die gleichen Werte wie in `.env` eintragen.

### A.9 Updates (manuell)

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

> Dieser Weg hat einen kurzen Moment, in dem neuer Code + alte OPcache
> aufeinandertreffen. Für echtes Zero-Downtime → Weg B.

---

## B – mit Skript (`deploy/install.sh`)

Für eine **dedizierte Debian/Ubuntu-VM**. Das Skript baut die komplette
Infrastruktur auf (nginx, PHP-FPM, Zertifikat, systemd-Scheduler) und legt
eine **Zero-Downtime-Verzeichnisstruktur** an (`releases/` + `shared/` +
`current`-Symlink). Details: **[`deploy/README.md`](../deploy/README.md)**.

### B.1 Erstinstallation

```bash
git clone https://github.com/kzg-software/identity-provider.git /opt/auth-src && cd /opt/auth-src

sudo DOMAIN=auth.example.com \
     GIT_REF=main \
     DB_CONNECTION=mysql \
     DB_PASSWORD='EIN-SICHERES-PASSWORT' \
     bash deploy/install.sh
```

Wichtige Variablen (alle optional, Defaults im Skriptkopf):

| Variable | Bedeutung |
|---|---|
| `DOMAIN` | Domain für Zertifikat + vhost |
| `GIT_URL`, `GIT_REF` | Remote und Branch/Tag |
| `DB_CONNECTION` | `sqlite` oder `mysql` (MariaDB wird bei Bedarf mitinstalliert) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | nur bei `mysql` |
| `CERT_CHAIN_PATH`, `CERT_KEY_PATH` | vorhandenes Zertifikat statt selbstsigniert |
| `GIT_USERNAME`, `GIT_TOKEN` | Git-Zugang vorab (sonst interaktive Abfrage) |

Danach `https://auth.example.com` öffnen → Web-Installer.

### B.2 Update (ohne Downtime)

```bash
sudo bash deploy/update.sh            # rollt GIT_REF (Default main) aus
sudo bash deploy/update.sh v1.4.0     # oder ein bestimmter Tag
```

Ablauf: neue Release komplett vorbereiten → Health-Check (`about` + echter
HTTP-Request gegen `/up`) → **erst dann** `current` atomar umschalten →
PHP-FPM reload. Schlägt der Health-Check fehl, bleibt die alte Release aktiv.

### B.3 Rollback

```bash
sudo bash deploy/rollback.sh previous
sudo bash deploy/rollback.sh 20260825120000
```

### B.4 CI-Anbindung (optional)

`deploy/update.sh` lässt sich per SSH aus einer Pipeline aufrufen, z.B.
nach erfolgreichem Test-Workflow:

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

---

## C – mit Docker (compose)

Ein einziges Image (`Dockerfile`) enthält PHP 8.4 (FPM) + nginx +
supervisor. Über `CONTAINER_ROLE` übernimmt derselbe Container eine Rolle:

| `CONTAINER_ROLE` | Prozess |
|---|---|
| `app` (Default) | nginx + php-fpm (Weboberfläche, Port 8080) |
| `scheduler` | `php artisan schedule:work` |
| `queue` | `php artisan queue:work` |

Mitgeliefert:

| Datei | Zweck |
|---|---|
| `Dockerfile`, `docker/` | Image-Definition + nginx/php/supervisor/entrypoint |
| `docker-compose.yml` | **Produktions-Stack**: App + Scheduler + Queue + **MariaDB**, zieht fertiges Image |
| `docker-compose.build.yml` | Override: Image lokal bauen statt ziehen |
| `docker-compose.sqlite.yml` | **Minimal-Stack**: nur App-Container-Set + **SQLite**, baut lokal |
| `.env.docker.example` | Vorlage für `.env` im Docker-Betrieb |
| `.github/workflows/docker-image.yml` | CI baut & veröffentlicht das Image automatisch |

### C.1 Voraussetzungen

- Docker Engine 24+ mit Compose v2 (`docker compose`, nicht `docker-compose`).
- Ein **Reverse Proxy** mit TLS vor dem Container-Port (siehe C.7). Für
  reine lokale Tests siehe C.6.

### C.2 Schnellstart – SQLite (baut lokal, keine Registry nötig)

```bash
git clone https://github.com/kzg-software/identity-provider.git auth-system && cd auth-system

cp .env.docker.example .env
```

In `.env` anpassen:

```dotenv
APP_URL=https://auth.example.com     # bzw. http://localhost:8080 für lokalen Test
DB_CONNECTION=sqlite
SESSION_SECURE_COOKIE=true            # false, wenn ohne HTTPS getestet wird
TRUSTED_PROXIES=*
AUTO_MIGRATE=true
```

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
docker compose -f docker-compose.sqlite.yml logs -f app     # Start beobachten
```

Der Entrypoint erledigt automatisch: `.env` prüfen, `APP_KEY` erzeugen,
SQLite-Datei anlegen, `migrate --force` (wegen `AUTO_MIGRATE=true`),
`storage:link`, Caches. Danach `http://<host>:8080` (bzw. via Proxy) →
**Web-Installer**.

Persistenz: `./.env` (Bind-Mount) + Named Volumes `app-storage`,
`app-database`.

### C.3 Produktions-Stack – MariaDB + fertiges Image

```bash
cp .env.docker.example .env
```

In `.env` **alle** Platzhalter setzen:

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
docker login ghcr.io                 # einmalig, für private Images
docker compose up -d
docker compose logs -f app
```

Compose startet: `db` (MariaDB, mit Healthcheck) → `app` (wartet auf `db`
healthy, migriert, serviert) → `scheduler` + `queue` (warten auf `app`
healthy).

Update auf ein neueres Image:

```bash
docker compose pull
docker compose up -d
```

### C.4 Produktions-Stack – selbst bauen statt ziehen

Wenn kein Zugriff auf die Registry gewünscht ist:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

Danach genügt wieder `docker compose up -d` (Image ist als
`auth-system:local` getaggt; `APP_IMAGE` in `.env` entsprechend leer lassen
oder auf `auth-system:local` setzen).

### C.5 Betrieb

```bash
docker compose ps
docker compose logs -f app scheduler queue
docker compose exec app php artisan about
docker compose exec app php artisan tinker

# nach jeder .env-Änderung:
docker compose restart app scheduler queue

# manuell migrieren (falls AUTO_MIGRATE=false):
docker compose exec app php artisan migrate --force

# Backup MariaDB:
docker compose exec db mariadb-dump -u root -p"$DB_ROOT_PASSWORD" auth_system > backup.sql

# Backup Uploads/Logs:
docker run --rm -v auth-system_app-storage:/s -v "$PWD":/b alpine \
  tar czf /b/storage-backup.tar.gz -C /s .
```

**Performance (optional):** Nach abgeschlossener Installation kann in der
`app`-Rolle einmalig `php artisan config:cache` laufen. Danach aber bei
**jeder** `.env`-Änderung `php artisan config:clear` nicht vergessen – der
Entrypoint macht das beim Neustart ohnehin.

### C.6 Rein lokaler Test (ohne Proxy/HTTPS)

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

### C.7 Reverse Proxy davorstellen

Der Container spricht **HTTP auf Port 8080**. Der Proxy terminiert TLS und
muss `X-Forwarded-Proto`, `X-Forwarded-Host` und `X-Forwarded-For` setzen;
in der App über `TRUSTED_PROXIES` freigeben (`*` ist ok, wenn der Container
nur über den Proxy erreichbar ist – dann den Port `8080` **nicht** nach
außen mappen, sondern nur ins Proxy-Netz).

**nginx (Beispiel):**

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

**Traefik (Labels am `app`-Service in einer Compose-Override-Datei):**

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

### C.8 Windows-SSO / Kerberos im Docker-Betrieb

nginx im Container kann Kerberos/SPNEGO **nicht** terminieren (wie im
bare-metal-Setup). Zwei Optionen, identisch zum VM-Betrieb:

1. Einen **Apache mit `mod_auth_gssapi`** (oder IIS-ARR) als Proxy vor den
   Container stellen, der die Kerberos-Validierung macht und `REMOTE_USER`
   als Header durchreicht – die `WindowsSsoAuthenticate`-Middleware
   verarbeitet das. `REMOTE_USER` per FastCGI/Proxy-Header übergeben.
2. Den in der App eingebauten Endpoint **`/auth/negotiate`** nutzen (kein
   vorgeschalteter Kerberos-Server nötig; validiert nur den gemeldeten
   Namen, nicht kryptografisch – bewusste Design-Entscheidung, siehe
   `README.md` und `NegotiateController`).

---

## Automatischer Image-Build (CI)

`.github/workflows/docker-image.yml` baut das Image und veröffentlicht es.

### Auf GitHub (Standard)

Ohne jede Konfiguration:

- Git-Tag `v1.2.3` (Release) → `:1.2.3`, `:1.2`, **`:latest`**
- Commit auf `main` → **`:dev`**, `:main`, `:sha-<kurz>`
- Commit auf jedem anderen Branch → `:<branchname>`, `:sha-<kurz>`
- Pull Request → nur Build (kein Push)

`:latest` entsteht ausschließlich bei einem Versions-Tag, `:dev` bei jedem
Commit auf dem Default-Branch. Produktionsserver ziehen `:latest` (oder ein
festes `:1.2.3`), Staging/Dev zieht `:dev`.
- Das Image wird ausschließlich für `linux/amd64` gebaut (Intel/AMD-Server).
  ARM/Apple Silicon wird nicht unterstützt.

Die Registry-Anmeldung läuft über das automatische `GITHUB_TOKEN`
(`permissions: packages: write` ist im Workflow gesetzt). Nach dem ersten
Lauf das Package unter *GitHub → Packages* ggf. auf **public** stellen oder
auf den Servern mit einem Read-Token `docker login ghcr.io`.

`.env` → `APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest`.

### Auf Gitea (oder anderer Forge)

Gitea Actions ist GitHub-Actions-kompatibel und liest denselben Workflow.
Nur Registry-Ziel umstellen – im Repo unter *Settings → Actions → Variables
/ Secrets*:

| Typ | Name | Beispiel |
|---|---|---|
| Variable | `REGISTRY` | `git.example.org` |
| Variable | `IMAGE_NAME` | `kzg-software/identity-provider` |
| Variable | `REGISTRY_USER` | `ci-bot` |
| Secret | `REGISTRY_TOKEN` | Access-Token mit `write:package` |

Voraussetzung: ein **Act-Runner** ist für das Repo registriert und die
Container-Registry ist in Gitea aktiviert. `.env` →
`APP_IMAGE=git.example.org/kzg-software/identity-provider:latest`.

### Tests-Workflow

`.github/workflows/tests.yml` läuft bei jedem Push/PR: PHP 8.4,
`composer install`, `php artisan test` (SQLite in-memory).

---

## Version & Update-Prüfung

Der aktuelle Release-Stand wird im **Footer** jeder Seite angezeigt (verlinkt
auf die passende Release-Seite im Repo) und in der **Administration →
Aktualisierungen** ausgewertet.

**Woher kommt die Versionsnummer?**

| Betrieb | Quelle |
|---|---|
| Docker | Build-Arg `APP_VERSION` → das CI-Image setzt es auf den Git-Tag (`v1.4.2`), Dev-Builds auf `dev` |
| Git-Deployment | `deploy/install.sh` / `deploy/update.sh` schreiben eine Datei `VERSION` (aus `git describe --tags`) |
| sonst | `dev` |

**Update-Prüfung:** Zweimal täglich (Scheduler-Job `updates:check`) sowie beim
Öffnen der Admin-Seiten prüft das System die GitHub-Releases-API auf ein
neueres Release. Ergebnis und Changelog landen im Cache; bei einer neueren
Version erscheint ein Hinweis auf dem Dashboard und im Footer. Kein Scheduler
nötig – die Prüfung wird sonst nach dem Ausliefern der Antwort nachgeholt. Die
Prüfung ist fest eingebaut und lässt sich nicht abschalten.

| Variable | Default | Zweck |
|---|---|---|
| `UPDATE_REPOSITORY` | `kzg-software/identity-provider` | `owner/repo` für die Prüfung |
| `UPDATE_REPOSITORY_URL` | `https://github.com/kzg-software/identity-provider` | Footer-/Changelog-Links |
| `UPDATE_GITHUB_TOKEN` | – | Nur bei privatem Repo oder gegen Rate-Limits (`Contents: read`) |

Manuell prüfen: `php artisan updates:check --force` (bzw.
`docker compose exec app php artisan updates:check --force`).

---

## Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| `/install` endet mit 500, `system_settings` fehlt | Migrationen liefen nicht. Weg C: `AUTO_MIGRATE=true` setzen oder `docker compose exec app php artisan migrate --force`. |
| Login-Schleife, keine Session | `SESSION_SECURE_COOKIE=true` ohne HTTPS. Proxy davorsetzen oder für Test `false`. |
| Falsche Redirect-URLs (`http` statt `https`, falscher Host) | `APP_URL` korrekt setzen **und** `TRUSTED_PROXIES` für den Proxy freigeben. |
| Upload „Datei ist zu groß" bei Logo/Favicon/Hintergrund | PHP-Limits. Weg A/B: FPM-Pool (`upload_max_filesize`/`post_max_size`). Weg C: bereits im Image auf 20M/24M. nginx davor braucht `client_max_body_size 20m`. |
| Hochgeladenes Logo/Hintergrund 404 | `php artisan storage:link` fehlt. Weg C: erledigt der Entrypoint; Volume `app-storage` muss gemountet sein. |
| Status-Seite: „Kein Scheduler-Heartbeat" | `scheduler`-Dienst/Container läuft nicht. |
| `ldap`-Funktionen tot | `php -m | grep ldap` – Extension fehlt (Weg A). Im Docker-Image enthalten. |
| Container startet, `/up` bleibt „unhealthy" | `docker compose logs app` – meist DB nicht erreichbar oder `APP_KEY` leer bei nicht-gemounteter `.env`. |
