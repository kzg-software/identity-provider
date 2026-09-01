#!/usr/bin/env bash
#
# Zero-Downtime-Update einer bestehenden Auth-System-Installation.
#
# Prinzip: eine komplett neue, unabhängige Release wird in einem eigenen
# Verzeichnis vorbereitet (Code auschecken, Composer, Migrationen, Caches) -
# während laufende Requests weiter unverändert von der ALTEN Release über
# den bisherigen "current"-Symlink bedient werden. Erst wenn die neue
# Release nachweislich funktioniert, wird "current" per `mv -T` (atomarer
# Rename unter Linux) auf die neue Release umgebogen. nginx/PHP-FPM lesen
# den Pfad hinter "current" bei jedem Request neu auf - es gibt keinen
# Moment, in dem der Webserver neu gestartet werden müsste oder Requests
# scheitern. Fehlgeschlagene Health-Checks brechen VOR dem Umschalten ab,
# die alte Release bleibt unangetastet aktiv.
#
# Aufruf: sudo bash deploy/update.sh [git-ref]
#
set -euo pipefail

APP_ROOT="${APP_ROOT:-/srv/auth-system}"
APP_USER="${APP_USER:-authapp}"
GIT_URL="${GIT_URL:-https://github.com/kzg-software/identity-provider.git}"
GIT_REF="${1:-${GIT_REF:-main}}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }
fail() { echo -e "\033[1;31mFEHLER: $*\033[0m" >&2; exit 1; }

if [[ "${EUID}" -ne 0 ]]; then
    fail "Bitte als root ausführen (sudo bash deploy/update.sh)."
fi

[[ -L "${APP_ROOT}/current" ]] || fail "Keine bestehende Installation unter ${APP_ROOT}/current gefunden - erst deploy/install.sh ausführen."

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
RELEASE_TS="$(date +%Y%m%d%H%M%S)"
RELEASE_DIR="${APP_ROOT}/releases/${RELEASE_TS}"

log "Neue Release nach ${RELEASE_DIR} auschecken (${GIT_REF})"
git clone --depth 1 --branch "${GIT_REF}" "${GIT_URL}" "${RELEASE_DIR}"

# Besitzwechsel sofort nach dem Clone (root -> APP_USER), BEVOR composer als
# APP_USER läuft - sonst "dubious ownership"/Permission-Fehler, siehe
# install.sh für die ausführliche Begründung.
chown -R "${APP_USER}:${APP_USER}" "${RELEASE_DIR}"
sudo -u "${APP_USER}" git config --global --add safe.directory "${RELEASE_DIR}"

log "shared/-Verzeichnisse einbinden"
rm -rf "${RELEASE_DIR}/storage"
ln -s "${APP_ROOT}/shared/storage" "${RELEASE_DIR}/storage"
rm -rf "${RELEASE_DIR}/bootstrap/cache"
ln -s "${APP_ROOT}/shared/bootstrap-cache" "${RELEASE_DIR}/bootstrap/cache"
ln -sf "${APP_ROOT}/shared/.env" "${RELEASE_DIR}/.env"

log "Composer-Abhängigkeiten installieren"
cd "${RELEASE_DIR}"
sudo -u "${APP_USER}" env COMPOSER_HOME="${APP_ROOT}/shared/.composer" composer install --no-dev --optimize-autoloader --no-interaction

# public/storage liegt im Release-Verzeichnis (nicht in shared/) und muss
# daher bei jedem Deploy neu verlinkt werden, sonst sind hochgeladene
# Branding-Dateien (Logo/Favicon) nach dem naechsten Update 404.
sudo -u "${APP_USER}" php artisan storage:link || true

log "Datenbank-Migrationen ausführen"
# Läuft bewusst VOR dem Symlink-Swap: Migrationen dieses Projekts sind rein
# additiv (neue Tabellen/Spalten), sodass die noch aktive alte Release
# währenddessen unverändert weiterläuft. Für eine hypothetische, wirklich
# brechende Schema-Änderung (z.B. Spalte umbenennen/löschen, die die alte
# Release noch braucht) müsste man in zwei Deploy-Schritten arbeiten
# (Spalte erst hinzufügen+befüllen, in einem SPÄTEREN Deploy erst entfernen)
# - für den aktuellen Codestand ist das nicht nötig.
sudo -u "${APP_USER}" php artisan migrate --force

log "Caches der neuen Release aufbauen"
sudo -u "${APP_USER}" php artisan config:cache
sudo -u "${APP_USER}" php artisan route:cache
sudo -u "${APP_USER}" php artisan view:cache

chown -R "${APP_USER}:${APP_USER}" "${RELEASE_DIR}"

log "Health-Check der neuen Release (vor dem Umschalten)"
# 1) Bootstrap/Config/DB-Verbindung der neuen Release prüfen, ohne den
#    Webserver anzufassen.
if ! sudo -u "${APP_USER}" php artisan about --only=environment >/tmp/auth-system-health.log 2>&1; then
    cat /tmp/auth-system-health.log >&2
    fail "Health-Check fehlgeschlagen (php artisan about) - Deploy abgebrochen, alte Release bleibt aktiv."
fi

# 2) Echten HTTP-Request gegen /up (Laravel-Health-Route, bootstrap/app.php)
#    fahren, dafür kurz den eingebauten PHP-Webserver auf einem freien
#    lokalen Port gegen den public/-Ordner DER NEUEN RELEASE starten - der
#    laufende Produktivserver (nginx + der bisherige PHP-FPM-Pool) ist davon
#    komplett unberührt.
#
# WICHTIG: "public/index.php" wird explizit als Router-Skript übergeben.
# Ohne Router beantwortet der eingebaute PHP-Server jede URL, die keiner
# echten Datei entspricht (z.B. /up), direkt selbst mit 404 - der Request
# erreicht dann nie Laravel, und der Health-Check würde immer fehlschlagen.
#
# Ein zufälliger Port (statt eines festen) plus ein EXIT-Trap stellen sicher,
# dass weder ein Leichen-Prozess aus einem vorherigen fehlgeschlagenen Lauf
# kollidiert, noch dieser Lauf selbst einen hinterlässt (der Trap killt den
# Health-Server bei jedem Skript-Ende - Erfolg, Fehler oder Abbruch).
HEALTH_PORT="$(( (RANDOM % 20000) + 20000 ))"
HEALTH_PID=""

cleanup_health_server() {
    if [[ -n "${HEALTH_PID}" ]] && kill -0 "${HEALTH_PID}" 2>/dev/null; then
        kill "${HEALTH_PID}" 2>/dev/null || true
    fi
}
trap cleanup_health_server EXIT

( cd "${RELEASE_DIR}/public" && exec sudo -u "${APP_USER}" php -S "127.0.0.1:${HEALTH_PORT}" -t "${RELEASE_DIR}/public" "${RELEASE_DIR}/public/index.php" >/tmp/auth-system-health-http.log 2>&1 ) &
HEALTH_PID=$!
sleep 1

HEALTH_OK=0
for _ in 1 2 3 4 5; do
    if curl -fsS "http://127.0.0.1:${HEALTH_PORT}/up" >/dev/null 2>&1; then
        HEALTH_OK=1
        break
    fi
    sleep 1
done

if [[ "${HEALTH_OK}" -ne 1 ]]; then
    cat /tmp/auth-system-health-http.log >&2 || true
    fail "HTTP-Health-Check (/up) der neuen Release fehlgeschlagen - Deploy abgebrochen, alte Release bleibt aktiv."
fi

log "current-Symlink atomar umschalten"
OLD_RELEASE="$(readlink -f "${APP_ROOT}/current")"
ln -sfn "${RELEASE_DIR}" "${APP_ROOT}/current_tmp"
mv -T "${APP_ROOT}/current_tmp" "${APP_ROOT}/current"
echo "${OLD_RELEASE}" > "${APP_ROOT}/shared/.previous_release"

# Ältere Installationen nachrüsten: Upload-Limits im FPM-Pool (die PHP-
# Defaults 2M/8M sind zu klein für Logo/Favicon/Login-Hintergrund-Uploads).
FPM_POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/auth-system.conf"
if [[ -f "${FPM_POOL_CONF}" ]] && ! grep -q 'upload_max_filesize' "${FPM_POOL_CONF}"; then
    log "FPM-Pool: Upload-Limits nachrüsten"
    cat >> "${FPM_POOL_CONF}" <<'EOF'
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 24M
php_admin_value[memory_limit] = 256M
EOF
fi

log "OPcache/PHP-FPM auffrischen"
# Kein Neustart nötig (das würde kurz Requests blockieren) - ein Reload
# reicht, damit neue PHP-FPM-Worker den Code der neuen Release über den
# frischen current-Pfad laden; laufende Worker bedienen ihre aktuelle
# Anfrage noch fertig aus, danach übernehmen sie automatisch den neuen Pfad.
systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null || systemctl restart "php${PHP_VERSION}-fpm"

if systemctl list-unit-files | grep -q '^auth-system-queue\.service'; then
    log "Queue-Worker neu starten (übernehmen neuen Code beim nächsten Job, ohne Downtime der Weboberfläche)"
    systemctl restart auth-system-queue.service || true
fi

log "Alte Releases aufräumen (letzte ${KEEP_RELEASES} behalten)"
cd "${APP_ROOT}/releases"
# shellcheck disable=SC2012
ls -1t | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
    [[ "${APP_ROOT}/releases/${old}" == "$(readlink -f "${APP_ROOT}/current")" ]] && continue
    echo "  entferne alte Release ${old}"
    rm -rf "${APP_ROOT:?}/releases/${old}"
done

log "Update abgeschlossen"
echo "Aktive Release: ${RELEASE_DIR}"
echo "Vorherige Release (für Rollback): ${OLD_RELEASE}"
echo "Rollback bei Problemen: sudo bash deploy/rollback.sh previous"
