# In den Produktivbetrieb

Es gibt drei Wege. Alle drei sind vollständig unterstützt.

| Weg | Wann | Aufwand |
|---|---|---|
| **Docker** | Empfohlen. Server mit Docker vorhanden, reproduzierbar, einfaches Update | niedrig |
| **Skript** (`deploy/install.sh`) | Eine frische Debian oder Ubuntu VM nur für dieses System, Updates ohne Downtime | niedrig |
| **Von Hand** | Bestehende Serverlandschaft, kein Docker, volle Kontrolle über jeden Schritt | hoch |

## Gilt für alle Wege

* PHP 8.3 oder neuer, getestet mit 8.4. Erweiterungen: `openssl`, `pdo` mit Treiber für MySQL oder SQLite, `ldap`, `xml` und `dom`, `curl`, `mbstring`, `intl`, `sodium`, `gd`, `zip`, `bcmath`, `opcache`.
* Kein Node, npm oder Vite. Die Oberfläche läuft über Blade. Tailwind, Alpine.js und die Schrift liegen lokal in `public/vendor/`.
* Das System terminiert kein TLS. Davor gehört ein Reverse Proxy mit gültigem Zertifikat (nginx, Apache, Traefik, Caddy, IIS ARR). SAML, OIDC und Kerberos brauchen HTTPS.
* Nach der Infrastruktur läuft der Einrichtungs Assistent im Browser (`/install`). Er fragt: neu einrichten oder aus einer Sicherung wiederherstellen. Danach sperrt er sich selbst.
* Der Scheduler muss laufen (`php artisan schedule:run` jede Minute). Er betreibt AD Sync, den Status Heartbeat und die Update Prüfung.
* Ein Queue Worker (`php artisan queue:work`) ist derzeit optional, aber empfohlen.

---

# Weg 1: Docker (empfohlen)

Ein einziges Image enthält PHP 8.4, nginx und supervisor. Über die Variable
`CONTAINER_ROLE` übernimmt derselbe Container eine Rolle:

| `CONTAINER_ROLE` | Aufgabe |
|---|---|
| `app` (Standard) | nginx und PHP, Weboberfläche auf Port 8080 |
| `scheduler` | `php artisan schedule:work` |
| `queue` | `php artisan queue:work` |

## 1.1 Voraussetzungen

* Docker Engine 24 oder neuer mit Compose v2 (`docker compose`, nicht `docker-compose`).
* Ein Reverse Proxy mit TLS vor Port 8080. Für einen reinen lokalen Test siehe Abschnitt 1.6.

## 1.2 Schnellstart mit MariaDB und fertigem Image

Sie brauchen nur zwei Dateien, nicht den ganzen Quellcode.

```bash
mkdir auth && cd auth

curl -O https://raw.githubusercontent.com/kzg-software/identity-provider/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/kzg-software/identity-provider/main/.env.docker.example
```

In der `.env` setzen:

```dotenv
APP_IMAGE=ghcr.io/kzg-software/identity-provider:latest
APP_PORT=8080
APP_URL=https://auth.firma.de
TRUSTED_PROXIES=*

DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=auth
DB_USERNAME=auth
DB_PASSWORD=langes-passwort-1
DB_ROOT_PASSWORD=langes-passwort-2
AUTO_MIGRATE=true

SESSION_SECURE_COOKIE=true
```

Starten:

```bash
docker login ghcr.io          # nur nötig, wenn das Image privat ist
docker compose up -d
docker compose logs -f app
```

Compose startet der Reihe nach: `db` (MariaDB mit Healthcheck), dann `app`
(wartet auf `db`, legt die Tabellen an, serviert), dann `scheduler` und `queue`.

Danach den Reverse Proxy auf Port 8080 zeigen lassen (Abschnitt 1.7) und
`https://auth.firma.de` im Browser öffnen.

## 1.3 Update

```bash
docker compose pull
docker compose up -d
```

Die Tabellen werden beim Start automatisch aktualisiert (`AUTO_MIGRATE=true`).
Vor größeren Updates in der Administration eine Datensicherung erstellen.

## 1.4 Ohne separaten Datenbankserver (SQLite)

Für kleine Installationen oder Tests. Baut das Image lokal, keine Registry
nötig, dafür der ganze Quellcode.

```bash
git clone https://github.com/kzg-software/identity-provider.git auth && cd auth
cp .env.docker.example .env
```

In der `.env`: `DB_CONNECTION=sqlite` und `APP_URL` anpassen. Dann:

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
```

## 1.5 Image selbst bauen statt ziehen

Wenn kein Zugriff auf die Registry gewünscht ist:

```bash
git clone https://github.com/kzg-software/identity-provider.git auth && cd auth
cp .env.docker.example .env      # APP_IMAGE leer lassen
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

Danach genügt wieder `docker compose up -d`. Das Image ist als
`auth-system:local` getaggt.

## 1.6 Rein lokaler Test ohne Proxy und HTTPS

In der `.env`:

```dotenv
APP_URL=http://localhost:8080
SESSION_SECURE_COOKIE=false
TRUSTED_PROXIES=
```

```bash
docker compose -f docker-compose.sqlite.yml up -d --build
# http://localhost:8080
```

## 1.7 Reverse Proxy davorstellen

Der Container spricht HTTP auf Port 8080. Der Proxy terminiert TLS und muss
`X-Forwarded-Proto`, `X-Forwarded-Host` und `X-Forwarded-For` setzen. In der App
über `TRUSTED_PROXIES` freigeben. `*` ist in Ordnung, wenn der Container nur
über den Proxy erreichbar ist. Dann Port 8080 nicht nach außen mappen.

nginx:

```nginx
server {
    listen 443 ssl;
    server_name auth.firma.de;
    ssl_certificate     /etc/letsencrypt/live/auth.firma.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/auth.firma.de/privkey.pem;
    client_max_body_size 512m;

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

Traefik, als Labels am `app` Service in einer Compose Override Datei:

```yaml
services:
  app:
    labels:
      - traefik.enable=true
      - traefik.http.routers.auth.rule=Host(`auth.firma.de`)
      - traefik.http.routers.auth.entrypoints=websecure
      - traefik.http.routers.auth.tls.certresolver=le
      - traefik.http.services.auth.loadbalancer.server.port=8080
```

## 1.8 Betrieb

```bash
docker compose ps
docker compose logs -f app scheduler queue
docker compose exec app php artisan about

# nach jeder .env-Aenderung:
docker compose restart app scheduler queue

# manuell migrieren, falls AUTO_MIGRATE=false:
docker compose exec app php artisan migrate --force
```

Die Datensicherung läuft komplett über die Weboberfläche (Administration,
Datensicherung). Ein Dump von Hand ist nicht nötig. Details in
[`BACKUP.md`](BACKUP.md).

## 1.9 Mehrere Instanzen (Produktiv und Test)

Ein Ordner pro Instanz, eine eigene `.env` mit eigenem `COMPOSE_PROJECT_NAME`,
`APP_PORT` und `APP_URL`. Siehe [`INSTANZEN.md`](INSTANZEN.md).

## 1.10 Windows SSO im Docker Betrieb

nginx im Container kann Kerberos nicht terminieren, genau wie ohne Docker. Zwei
Optionen, identisch zum VM Betrieb:

1. Einen Apache mit `mod_auth_gssapi` oder IIS ARR als Proxy vor den Container stellen. Er macht die Kerberos Prüfung und reicht `REMOTE_USER` als Header durch.
2. Den eingebauten Endpunkt `/auth/negotiate` nutzen. Kein vorgeschalteter Kerberos Server nötig, er validiert nur den gemeldeten Namen. Bewusste Entscheidung, siehe `README.md`.

---

# Weg 2: mit Skript

Für eine dedizierte Debian oder Ubuntu VM, die nur dieses System betreibt. Das
Skript baut die komplette Infrastruktur auf (nginx, PHP FPM, Zertifikat,
systemd Scheduler) und legt eine Struktur für Updates ohne Downtime an
(`releases/`, `shared/`, `current` Symlink). Details in
[`../deploy/README.md`](../deploy/README.md).

## 2.1 Erstinstallation

```bash
git clone https://github.com/kzg-software/identity-provider.git /opt/auth-src && cd /opt/auth-src

sudo DOMAIN=auth.firma.de \
     GIT_REF=main \
     DB_CONNECTION=mysql \
     DB_PASSWORD='langes-passwort' \
     bash deploy/install.sh
```

Wichtige Variablen, alle optional, Standardwerte im Skriptkopf:

| Variable | Bedeutung |
|---|---|
| `DOMAIN` | Domain für Zertifikat und vhost |
| `GIT_URL`, `GIT_REF` | Remote und Branch oder Tag |
| `DB_CONNECTION` | `sqlite` oder `mysql`, MariaDB wird bei Bedarf mitinstalliert |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | nur bei `mysql` |
| `CERT_CHAIN_PATH`, `CERT_KEY_PATH` | vorhandenes Zertifikat statt selbstsigniert |
| `GIT_USERNAME`, `GIT_TOKEN` | Git Zugang vorab, sonst interaktive Abfrage |

Danach `https://auth.firma.de` öffnen und den Einrichtungs Assistenten
durchlaufen.

## 2.2 Update ohne Downtime

```bash
sudo bash deploy/update.sh            # rollt GIT_REF aus, Standard main
sudo bash deploy/update.sh v1.4.0     # oder einen bestimmten Tag
```

Ablauf: neue Release komplett vorbereiten, Health Check, erst dann `current`
atomar umschalten, dann PHP FPM neu laden. Schlägt der Health Check fehl, bleibt
die alte Release aktiv.

## 2.3 Rollback

```bash
sudo bash deploy/rollback.sh previous
sudo bash deploy/rollback.sh 20260825120000
```

---

# Weg 3: von Hand

Für ein Setup, das Sie selbst Schritt für Schritt kontrollieren. Beispiel:
Debian oder Ubuntu mit nginx und PHP FPM. Für Apache oder IIS sinngemäß.

## 3.1 Pakete

```bash
sudo apt update
sudo apt install -y nginx git unzip \
  php8.4-fpm php8.4-cli php8.4-xml php8.4-curl php8.4-mbstring php8.4-intl \
  php8.4-sqlite3 php8.4-mysql php8.4-ldap php8.4-bcmath php8.4-gd php8.4-zip \
  php8.4-opcache
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Prüfen:

```bash
php -m | grep -Ei 'openssl|pdo|ldap|dom|curl|mbstring|intl|sodium|gd|zip|bcmath|opcache'
```

## 3.2 Datenbank

MariaDB oder MySQL, für den Produktivbetrieb empfohlen:

```bash
sudo apt install -y mariadb-server
sudo mysql <<'SQL'
CREATE DATABASE auth CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'auth'@'localhost' IDENTIFIED BY 'LANGES-PASSWORT';
GRANT ALL PRIVILEGES ON auth.* TO 'auth'@'localhost';
FLUSH PRIVILEGES;
SQL
```

SQLite reicht für kleine Installationen oder Tests: nichts zu installieren, nur
später eine Datei mit Schreibrechten für den Webserver Benutzer.

## 3.3 Code und Konfiguration

```bash
sudo mkdir -p /var/www/auth
sudo chown "$USER" /var/www/auth
git clone https://github.com/kzg-software/identity-provider.git /var/www/auth
cd /var/www/auth

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

`.env` anpassen, mindestens:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://auth.firma.de

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=auth
DB_USERNAME=auth
DB_PASSWORD=LANGES-PASSWORT
# oder SQLite:
# DB_CONNECTION=sqlite
# DB_DATABASE=/var/www/auth/database/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# Fast immer, da ein Reverse Proxy davor steht:
TRUSTED_PROXIES=127.0.0.1
```

Hinweis zu `TRUSTED_PROXIES` und Config Cache: sobald Sie unten
`php artisan config:cache` nutzen, wird die `.env` zur Laufzeit nicht mehr
gelesen. `TRUSTED_PROXIES` dann als echte Umgebungsvariable im FPM Pool setzen
(`env[TRUSTED_PROXIES] = 127.0.0.1`, siehe 3.5) oder auf den Config Cache
verzichten.

## 3.4 Initialisieren

```bash
# SQLite: Datei anlegen
# touch database/database.sqlite

php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
# bei SQLite zusaetzlich:
# sudo chown www-data:www-data database database/database.sqlite
```

## 3.5 PHP FPM Pool

`/etc/php/8.4/fpm/pool.d/auth.conf`:

```ini
[auth]
user = www-data
group = www-data
listen = /run/php/auth.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
; Uploads und vor allem das Einspielen einer Datensicherung
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size] = 520M
php_admin_value[memory_limit] = 512M
; optional, siehe Hinweis in 3.3:
; env[TRUSTED_PROXIES] = 127.0.0.1
```

```bash
sudo systemctl restart php8.4-fpm
```

## 3.6 nginx vhost

`/etc/nginx/sites-available/auth.conf`:

```nginx
server {
    listen 80;
    server_name auth.firma.de;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name auth.firma.de;

    ssl_certificate     /etc/ssl/auth/fullchain.pem;
    ssl_certificate_key /etc/ssl/auth/privkey.pem;

    root /var/www/auth/public;
    index index.php;
    client_max_body_size 512m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/auth.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/auth.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Zertifikat: entweder von der internen CA (`fullchain.pem` und `privkey.pem` nach
`/etc/ssl/auth/` legen) oder selbstsigniert:

```bash
sudo mkdir -p /etc/ssl/auth
sudo openssl req -x509 -nodes -newkey rsa:4096 -days 825 \
  -keyout /etc/ssl/auth/privkey.pem \
  -out /etc/ssl/auth/fullchain.pem \
  -subj "/CN=auth.firma.de" -addext "subjectAltName=DNS:auth.firma.de"
```

## 3.7 Scheduler und Queue über systemd

`/etc/systemd/system/auth-scheduler.service`:

```ini
[Unit]
Description=Auth Scheduler
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/auth
ExecStart=/usr/bin/php artisan schedule:run
```

`/etc/systemd/system/auth-scheduler.timer`:

```ini
[Unit]
Description=Auth Scheduler jede Minute
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
[Install]
WantedBy=timers.target
```

`/etc/systemd/system/auth-queue.service`:

```ini
[Unit]
Description=Auth Queue Worker
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/auth
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now auth-scheduler.timer auth-queue.service
```

## 3.8 Einrichtungs Assistent

`https://auth.firma.de` im Browser öffnen und durch die Schritte klicken. Bei
der Datenbank dieselben Werte wie in der `.env` eintragen.

## 3.9 Update von Hand

```bash
cd /var/www/auth
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan storage:link
sudo systemctl reload php8.4-fpm
sudo systemctl restart auth-queue.service
```

Bei diesem Weg gibt es einen kurzen Moment, in dem neuer Code und alter OPcache
aufeinandertreffen. Für echtes Zero Downtime siehe Weg 2.

---

# Versionsnummer und Update Prüfung

Der aktuelle Stand steht im Footer jeder Seite und wird in der Administration
unter Aktualisierungen ausgewertet.

| Betrieb | Woher die Versionsnummer kommt |
|---|---|
| Docker | Build Arg `APP_VERSION`. Das CI Image setzt es auf den Git Tag |
| Weg 2 | `deploy/install.sh` und `deploy/update.sh` schreiben eine Datei `VERSION` aus `git describe` |
| sonst | `dev` |

Die Prüfung läuft zweimal täglich über den Scheduler Job `updates:check` und
zusätzlich beim Öffnen der Admin Seiten. Sie fragt die GitHub Releases API ab.

| Variable | Standard | Zweck |
|---|---|---|
| `UPDATE_REPOSITORY` | `kzg-software/identity-provider` | `owner/repo` für die Prüfung |
| `UPDATE_REPOSITORY_URL` | `https://github.com/kzg-software/identity-provider` | Links im Footer und Changelog |
| `UPDATE_GITHUB_TOKEN` | leer | Nur bei privatem Repo oder gegen Rate Limits |

Manuell: `php artisan updates:check --force` oder
`docker compose exec app php artisan updates:check --force`.

---

# Automatischer Image Build

`.github/workflows/docker-image.yml` baut das Image und veröffentlicht es.

Ohne weitere Konfiguration:

* Git Tag `v1.2.3` ergibt `:1.2.3`, `:1.2` und `:latest`
* Commit auf `main` ergibt `:dev`, `:main` und `:sha-<kurz>`
* Commit auf jedem anderen Branch ergibt `:<branchname>` und `:sha-<kurz>`
* Pull Request wird nur gebaut, nicht veröffentlicht

Produktionsserver ziehen `:latest` oder ein festes `:1.2.3`, Test zieht `:dev`.
Das Image wird nur für `linux/amd64` gebaut.

Für Gitea oder eine andere Forge nur das Registry Ziel über Repo Variablen und
Secrets umstellen (`REGISTRY`, `IMAGE_NAME`, `REGISTRY_USER`, `REGISTRY_TOKEN`).

---

# Fehlersuche

| Symptom | Ursache und Lösung |
|---|---|
| `/install` endet mit 500, `system_settings` fehlt | Tabellen wurden nicht angelegt. Docker: `AUTO_MIGRATE=true` setzen oder `docker compose exec app php artisan migrate --force`. |
| Login Schleife, keine Session | `SESSION_SECURE_COOKIE=true` ohne HTTPS. Proxy davorsetzen oder für den Test auf `false`. |
| Falsche Redirect URLs, `http` statt `https` oder falscher Host | `APP_URL` korrekt setzen und `TRUSTED_PROXIES` für den Proxy freigeben. |
| Datensicherung lässt sich nicht hochladen, "Datei ist zu groß" oder "kein temporäres Upload Verzeichnis" | PHP Limits. `upload_max_filesize` und `post_max_size` hoch setzen (Docker und Skript: bereits 512M), im nginx davor `client_max_body_size`. Fehlt ein `upload_tmp_dir`, in der PHP Konfiguration ein beschreibbares Verzeichnis eintragen. |
| Hochgeladenes Logo oder Hintergrund liefert 404 | `php artisan storage:link` fehlt. Docker: erledigt der Entrypoint, das Volume `app-storage` muss gemountet sein. |
| Statusseite meldet "Kein Scheduler Heartbeat" | Der Scheduler Dienst oder Container läuft nicht. |
| `ldap` Funktionen tot | `php -m \| grep ldap`. Die Erweiterung fehlt (Weg 3). Im Docker Image enthalten. |
| Container startet, `/up` bleibt unhealthy | `docker compose logs app`. Meist ist die DB nicht erreichbar oder `APP_KEY` leer bei nicht gemounteter `.env`. |
