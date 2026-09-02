#!/usr/bin/env bash
#
# Erstinstallation des Auth-Systems auf einer frischen Debian/Ubuntu-VM.
#
# Baut die komplette Infrastruktur auf: nginx + PHP-FPM, systemd-Scheduler,
# Zero-Downtime-Verzeichnisstruktur (releases/ + shared/ + current-Symlink),
# checkt den Code per Git aus und richtet HTTPS ein (selbstsigniertes
# Zertifikat als Default, oder ein vorhandenes CA-Zertifikat, falls
# CERT_CHAIN_PATH/CERT_KEY_PATH gesetzt sind).
#
# Aufruf (als root bzw. via sudo):
#   sudo DOMAIN=auth.example.local GIT_REF=main bash deploy/install.sh
#
# Alle Variablen unten haben sinnvolle Defaults, siehe deploy/README.md für
# die vollständige Liste.
#
set -euo pipefail

# ── Konfiguration (per Umgebungsvariable überschreibbar) ────────────────────
DOMAIN="${DOMAIN:-auth.example.local}"
GIT_URL="${GIT_URL:-https://github.com/kzg-software/identity-provider.git}"
GIT_REF="${GIT_REF:-main}"
APP_USER="${APP_USER:-authapp}"
APP_ROOT="${APP_ROOT:-/srv/auth-system}"
PHP_VERSION="${PHP_VERSION:-8.4}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"   # sqlite | mysql
DB_DATABASE="${DB_DATABASE:-auth_system}"
DB_USERNAME="${DB_USERNAME:-auth_system}"
DB_PASSWORD="${DB_PASSWORD:-}"
INSTALL_MARIADB="${INSTALL_MARIADB:-auto}" # auto = installieren wenn DB_CONNECTION=mysql
CERT_DIR="${CERT_DIR:-/etc/ssl/auth-system}"
CERT_CHAIN_PATH="${CERT_CHAIN_PATH:-}"     # optional: vorhandenes Chain-Zertifikat
CERT_KEY_PATH="${CERT_KEY_PATH:-}"         # optional: zugehöriger Private Key
GIT_CREDENTIALS_FILE="${GIT_CREDENTIALS_FILE:-/etc/auth-system/git-credentials}"

# ── Vorbedingungen ───────────────────────────────────────────────────────────
if [[ "${EUID}" -ne 0 ]]; then
    echo "Bitte als root ausführen (sudo bash deploy/install.sh)." >&2
    exit 1
fi

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }

log "System-Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release git unzip openssl

# Manche Debian/Ubuntu-Versionen bringen die gewünschte PHP_VERSION bereits
# nativ mit (z.B. Debian 13 "trixie" -> PHP 8.4), andere brauchen dafür ein
# Drittanbieter-Repo. Wir prüfen zuerst, ob die native Paketquelle die
# gewünschte Version bereits kennt, und ziehen das sury.org-PHP-Repo (läuft
# auf Debian UND Ubuntu, im Gegensatz zum Ubuntu-only ondrej/php-PPA) nur
# als Fallback hinzu, wenn nicht.
if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    log "PHP ${PHP_VERSION} nicht in den nativen Paketquellen - sury.org-Repo hinzufügen"
    curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/deb.sury.org-php.gpg
    . /etc/os-release
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
        > /etc/apt/sources.list.d/php.list
    apt-get update -y
fi

apt-get install -y nginx sqlite3

# Nicht jede Extension existiert auf jeder Distribution als eigenes Paket -
# ab PHP 8.4 bündeln Debians Pakete z.B. sodium fest in php-cli/php-common
# statt es separat auszuliefern. Wir installieren daher nur, was apt
# tatsächlich kennt, und prüfen danach per `php -m`, ob alle wirklich
# benötigten Extensions geladen sind (das ist der eigentlich relevante Test,
# nicht ob es dafür ein eigenes .deb gab).
PHP_EXTENSIONS=(fpm cli xml curl mbstring intl sqlite3 mysql ldap bcmath gd zip opcache sodium)
PHP_PACKAGES_TO_INSTALL=()
for ext in "${PHP_EXTENSIONS[@]}"; do
    pkg="php${PHP_VERSION}-${ext}"
    if apt-cache show "${pkg}" >/dev/null 2>&1; then
        PHP_PACKAGES_TO_INSTALL+=("${pkg}")
    else
        echo "  Hinweis: Paket ${pkg} existiert nicht separat (vermutlich in php${PHP_VERSION}-cli/-common gebündelt) - wird übersprungen."
    fi
done
apt-get install -y "${PHP_PACKAGES_TO_INSTALL[@]}"

log "Geladene PHP-Extensions prüfen"
REQUIRED_EXTENSIONS=(xml curl mbstring intl sqlite3 pdo_mysql ldap bcmath gd zip opcache sodium)
MISSING_EXTENSIONS=()
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    php -m | grep -qi "${ext}" || MISSING_EXTENSIONS+=("${ext}")
done
if [[ ${#MISSING_EXTENSIONS[@]} -gt 0 ]]; then
    echo "WARNUNG: folgende PHP-Extensions sind nicht geladen: ${MISSING_EXTENSIONS[*]}" >&2
    echo "Das System kann trotzdem starten, aber je nach fehlender Extension können AD/LDAP, SAML-Zertifikate oder Bildverarbeitung nicht funktionieren. Bitte manuell prüfen (php -m)." >&2
fi

if [[ "${DB_CONNECTION}" == "mysql" && "${INSTALL_MARIADB}" == "auto" ]]; then
    log "MariaDB installieren"
    apt-get install -y mariadb-server
    systemctl enable --now mariadb
fi

if ! command -v composer >/dev/null 2>&1; then
    log "Composer installieren"
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "Verzeichnisstruktur unter ${APP_ROOT} anlegen"
mkdir -p "${APP_ROOT}"/{releases,shared}
mkdir -p "${APP_ROOT}/shared/storage"/{app/public,framework/{cache,sessions,testing,views},logs}
mkdir -p "${APP_ROOT}/shared/bootstrap-cache"
mkdir -p "${APP_ROOT}/shared/.composer"
mkdir -p "${APP_ROOT}/shared/home"
mkdir -p "$(dirname "${GIT_CREDENTIALS_FILE}")"

log "System-User anlegen"
# HOME zeigt bewusst auf shared/home statt auf APP_ROOT selbst - APP_ROOT
# gehört root, "sudo -u authapp git config --global" (z.B. safe.directory)
# bräuchte sonst ein $HOME/.gitconfig, das authapp dort nicht anlegen dürfte.
if ! id -u "${APP_USER}" >/dev/null 2>&1; then
    useradd --system --home-dir "${APP_ROOT}/shared/home" --shell /usr/sbin/nologin "${APP_USER}"
fi

chown -R "${APP_USER}:${APP_USER}" "${APP_ROOT}/shared"
export COMPOSER_HOME="${APP_ROOT}/shared/.composer"

# ── Git-Credentials sicher hinterlegen ───────────────────────────────────────
# WICHTIG: Der Token wird NIE ins Repo eingecheckt oder in eine versionierte
# Datei geschrieben. Wir nutzen git's eigenen "store"-Credential-Helper mit
# einer eigenen, root-only lesbaren Datei außerhalb von APP_ROOT/releases -
# so bleibt der Token auch bei jedem künftigen `git fetch` (deploy/update.sh)
# verfügbar, ohne ihn in der Remote-URL jeder Release erneut einzubetten.
if [[ ! -f "${GIT_CREDENTIALS_FILE}" ]]; then
    if [[ -n "${GIT_USERNAME:-}" && -n "${GIT_TOKEN:-}" ]]; then
        GIT_USER="${GIT_USERNAME}"
        GIT_PASS="${GIT_TOKEN}"
    else
        echo
        echo "Git-Zugangsdaten für ${GIT_URL} (werden NICHT im Repo gespeichert):"
        read -r -p "  Benutzername: " GIT_USER
        read -r -s -p "  Token/Passwort: " GIT_PASS
        echo
    fi

    GIT_HOST_PATH="$(echo "${GIT_URL}" | sed -E 's#^https?://##')"
    PROTO="$(echo "${GIT_URL}" | sed -E 's#(^https?)://.*#\1#')"
    echo "${PROTO}://${GIT_USER}:${GIT_PASS}@${GIT_HOST_PATH}" > "${GIT_CREDENTIALS_FILE}"
    chmod 600 "${GIT_CREDENTIALS_FILE}"
    chown root:root "${GIT_CREDENTIALS_FILE}"
fi

git config --global credential.helper "store --file=${GIT_CREDENTIALS_FILE}"

# ── Erste Release auschecken ─────────────────────────────────────────────────
RELEASE_TS="$(date +%Y%m%d%H%M%S)"
RELEASE_DIR="${APP_ROOT}/releases/${RELEASE_TS}"

log "Code auschecken (${GIT_REF}) nach ${RELEASE_DIR}"
git clone --depth 1 --branch "${GIT_REF}" "${GIT_URL}" "${RELEASE_DIR}"

# Der Clone läuft als root, alle folgenden Schritte (composer, artisan) aber
# bewusst als APP_USER (nie als root Anwendungscode ausführen). Deshalb muss
# der Besitzwechsel HIER, sofort nach dem Clone, passieren - nicht erst am
# Ende - sonst schlägt "sudo -u authapp composer install" mit
# "dubious ownership"/Permission-Fehlern fehl, weil authapp das von root
# angelegte Verzeichnis noch nicht besitzt.
chown -R "${APP_USER}:${APP_USER}" "${RELEASE_DIR}"
sudo -u "${APP_USER}" git config --global --add safe.directory "${RELEASE_DIR}"

# ── Versionskennung festhalten (Footer + Update-Prüfung) ───────────────────
VERSION_STR="$(git -C "${RELEASE_DIR}" describe --tags --exact-match 2>/dev/null \
    || git -C "${RELEASE_DIR}" describe --tags 2>/dev/null \
    || echo "${GIT_REF}")"
echo "${VERSION_STR}" > "${RELEASE_DIR}/VERSION"
chown "${APP_USER}:${APP_USER}" "${RELEASE_DIR}/VERSION"

# ── .env erzeugen (liegt in shared/, wird in jede Release symlinkt) ─────────
ENV_FILE="${APP_ROOT}/shared/.env"
if [[ ! -f "${ENV_FILE}" ]]; then
    log ".env unter ${ENV_FILE} anlegen"
    cp "${RELEASE_DIR}/.env.example" "${ENV_FILE}"

    if [[ "${DB_CONNECTION}" == "sqlite" ]]; then
        touch "${APP_ROOT}/shared/database.sqlite"
        DB_LINES="DB_CONNECTION=sqlite\nDB_DATABASE=${APP_ROOT}/shared/database.sqlite"
    else
        DB_LINES="DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=${DB_DATABASE}\nDB_USERNAME=${DB_USERNAME}\nDB_PASSWORD=${DB_PASSWORD}"

        if [[ "${INSTALL_MARIADB}" == "auto" ]]; then
            mysql -u root <<-SQL
                CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
                GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
                FLUSH PRIVILEGES;
SQL
        fi
    fi

    # bestehende DB_*-Zeilen aus dem Template entfernen, unsere einsetzen
    sed -i '/^DB_CONNECTION=/d;/^DB_HOST=/d;/^DB_PORT=/d;/^DB_DATABASE=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' "${ENV_FILE}"
    echo -e "${DB_LINES}" >> "${ENV_FILE}"

    sed -i "s#^APP_URL=.*#APP_URL=https://${DOMAIN}#" "${ENV_FILE}"
    sed -i "s#^APP_ENV=.*#APP_ENV=production#" "${ENV_FILE}"
    sed -i "s#^APP_DEBUG=.*#APP_DEBUG=false#" "${ENV_FILE}"
fi

chown -R "${APP_USER}:${APP_USER}" "${APP_ROOT}/shared"

# ── shared-Verzeichnisse in die Release symlinken (Zero-Downtime-Grundprinzip:
#    alles Persistente lebt in shared/, jede Release ist zustandslos) ────────
link_shared() {
    local release_dir="$1"
    rm -rf "${release_dir}/storage"
    ln -s "${APP_ROOT}/shared/storage" "${release_dir}/storage"
    rm -rf "${release_dir}/bootstrap/cache"
    ln -s "${APP_ROOT}/shared/bootstrap-cache" "${release_dir}/bootstrap/cache"
    ln -sf "${APP_ROOT}/shared/.env" "${release_dir}/.env"
}
link_shared "${RELEASE_DIR}"

log "Composer-Abhängigkeiten installieren"
cd "${RELEASE_DIR}"
sudo -u "${APP_USER}" env COMPOSER_HOME="${APP_ROOT}/shared/.composer" composer install --no-dev --optimize-autoloader --no-interaction

log "Laravel initialisieren"
sudo -u "${APP_USER}" php artisan key:generate --force
sudo -u "${APP_USER}" php artisan migrate --force
sudo -u "${APP_USER}" php artisan config:cache
sudo -u "${APP_USER}" php artisan route:cache
sudo -u "${APP_USER}" php artisan view:cache
sudo -u "${APP_USER}" php artisan storage:link || true

chown -R "${APP_USER}:${APP_USER}" "${RELEASE_DIR}"

# ── current-Symlink setzen ───────────────────────────────────────────────────
ln -sfn "${RELEASE_DIR}" "${APP_ROOT}/current_tmp"
mv -T "${APP_ROOT}/current_tmp" "${APP_ROOT}/current"

# ── TLS-Zertifikat ───────────────────────────────────────────────────────────
mkdir -p "${CERT_DIR}"
if [[ -n "${CERT_CHAIN_PATH}" && -n "${CERT_KEY_PATH}" ]]; then
    log "Vorhandenes Zertifikat verwenden: ${CERT_CHAIN_PATH}"
    cp "${CERT_CHAIN_PATH}" "${CERT_DIR}/fullchain.pem"
    cp "${CERT_KEY_PATH}" "${CERT_DIR}/privkey.pem"
else
    log "Selbstsigniertes Zertifikat für ${DOMAIN} erzeugen"
    echo "    Hinweis: Ein selbstsigniertes Zertifikat lässt Browser eine Warnung"
    echo "    anzeigen, bis es auf den Client-Rechnern als vertrauenswürdig"
    echo "    importiert wird. Für echten Produktivbetrieb im internen Netz ist"
    echo "    ein von der internen Unternehmens-CA signiertes Zertifikat sauberer"
    echo "    - setze dafür CERT_CHAIN_PATH/CERT_KEY_PATH statt dieses Zweigs."
    openssl req -x509 -nodes -newkey rsa:4096 -days 825 \
        -keyout "${CERT_DIR}/privkey.pem" \
        -out "${CERT_DIR}/fullchain.pem" \
        -subj "/CN=${DOMAIN}" \
        -addext "subjectAltName=DNS:${DOMAIN}"
fi
chmod 600 "${CERT_DIR}/privkey.pem"
chmod 644 "${CERT_DIR}/fullchain.pem"

# ── PHP-FPM-Pool ──────────────────────────────────────────────────────────────
log "PHP-FPM-Pool einrichten"
FPM_POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/auth-system.conf"
cat > "${FPM_POOL_CONF}" <<EOF
[auth-system]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/auth-system.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.enable] = 1
; Uploads: Logo/Favicon/Login-Hintergrund brauchen mehr als die PHP-Defaults
; (2M/8M), das Einspielen einer Datensicherung (Administration -> Daten-
; sicherung) kann deutlich groesser sein. Muss <= nginx client_max_body_size.
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size] = 520M
php_admin_value[memory_limit] = 512M
EOF

systemctl restart "php${PHP_VERSION}-fpm"

# ── nginx-vhost ───────────────────────────────────────────────────────────────
log "nginx-Vhost einrichten"
cat > "/etc/nginx/sites-available/auth-system.conf" <<EOF
# Windows Integrated Authentication (Kerberos/SPNEGO) kann nginx selbst
# NICHT terminieren - das braucht entweder Apache mit mod_auth_gssapi (oder
# IIS) als Reverse-Proxy davor, oder man nutzt den bereits im Code
# vorhandenen In-App-NTLM-Endpoint (/auth/negotiate), der genau für ein
# reines nginx-Setup ohne vorgeschalteten Kerberos-fähigen Webserver gebaut
# wurde (siehe README zum Sicherheitsunterschied der beiden Verfahren).

server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name ${DOMAIN};

    ssl_certificate     ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    root ${APP_ROOT}/current/public;
    index index.php;

    # Grosszuegig, damit auch das Einspielen einer Datensicherung durchpasst.
    client_max_body_size 512m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/auth-system.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        # REMOTE_USER wird hier bewusst NICHT gesetzt - siehe Kommentar oben.
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

ln -sf /etc/nginx/sites-available/auth-system.conf /etc/nginx/sites-enabled/auth-system.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
systemctl enable nginx "php${PHP_VERSION}-fpm"

# ── Scheduler als systemd-Timer (ersetzt Cron) ───────────────────────────────
log "Scheduler-Timer einrichten"
cat > /etc/systemd/system/auth-system-scheduler.service <<EOF
[Unit]
Description=Auth-System Laravel Scheduler (ein Tick)
After=network.target

[Service]
Type=oneshot
User=${APP_USER}
WorkingDirectory=${APP_ROOT}/current
ExecStart=/usr/bin/php artisan schedule:run
EOF

cat > /etc/systemd/system/auth-system-scheduler.timer <<EOF
[Unit]
Description=Auth-System Scheduler - jede Minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now auth-system-scheduler.timer

log "Fertig!"
cat <<EOF

Der Auth-Server läuft unter https://${DOMAIN}

Nächster Schritt: Rufe die URL im Browser auf - der Web-Installer
(Datenbank, Systemname, Administrator-Konto, Active-Directory-Verbindung)
führt dich durch die restliche fachliche Einrichtung.

Für spätere Updates: sudo bash deploy/update.sh
Für einen Rollback:  sudo bash deploy/rollback.sh previous

Konfiguration:
  Code:       ${APP_ROOT}/current -> ${RELEASE_DIR}
  .env:       ${ENV_FILE}
  Zertifikat: ${CERT_DIR}
  Logs:       ${APP_ROOT}/shared/storage/logs/laravel.log
              journalctl -u auth-system-scheduler.service
              /var/log/nginx/error.log
EOF
