#!/usr/bin/env bash
#
# Rollback auf eine ältere Release. Genau wie das Update ist auch der
# Rollback ein reiner Symlink-Swap - kein Downtime-Fenster, kein Rebuild.
#
# Aufruf:
#   sudo bash deploy/rollback.sh previous          # letzte aktive Release vor dem letzten Update
#   sudo bash deploy/rollback.sh 20260825120000     # konkreter Release-Zeitstempel (ls /srv/auth-system/releases)
#
set -euo pipefail

APP_ROOT="${APP_ROOT:-/srv/auth-system}"
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }
fail() { echo -e "\033[1;31mFEHLER: $*\033[0m" >&2; exit 1; }

if [[ "${EUID}" -ne 0 ]]; then
    fail "Bitte als root ausführen (sudo bash deploy/rollback.sh <ziel>)."
fi

TARGET="${1:-}"
[[ -n "${TARGET}" ]] || fail "Bitte Ziel angeben: 'previous' oder ein Release-Zeitstempel aus ${APP_ROOT}/releases."

if [[ "${TARGET}" == "previous" ]]; then
    [[ -f "${APP_ROOT}/shared/.previous_release" ]] || fail "Keine vorherige Release bekannt (noch kein Update mit deploy/update.sh durchgeführt)."
    TARGET_DIR="$(cat "${APP_ROOT}/shared/.previous_release")"
else
    TARGET_DIR="${APP_ROOT}/releases/${TARGET}"
fi

[[ -d "${TARGET_DIR}" ]] || fail "Release-Verzeichnis ${TARGET_DIR} existiert nicht."

CURRENT_DIR="$(readlink -f "${APP_ROOT}/current")"
if [[ "${TARGET_DIR}" == "${CURRENT_DIR}" ]]; then
    fail "Ziel-Release ist bereits aktiv - kein Rollback nötig."
fi

log "Rollback: ${CURRENT_DIR} -> ${TARGET_DIR}"
ln -sfn "${TARGET_DIR}" "${APP_ROOT}/current_tmp"
mv -T "${APP_ROOT}/current_tmp" "${APP_ROOT}/current"
echo "${CURRENT_DIR}" > "${APP_ROOT}/shared/.previous_release"

systemctl reload "php${PHP_VERSION}-fpm" 2>/dev/null || systemctl restart "php${PHP_VERSION}-fpm"

if systemctl list-unit-files | grep -q '^auth-system-queue\.service'; then
    systemctl restart auth-system-queue.service || true
fi

log "Rollback abgeschlossen - aktive Release: ${TARGET_DIR}"
echo "Hinweis: Falls das Update, von dem zurückgerollt wurde, Datenbank-"
echo "Migrationen enthielt, prüfe, ob diese mit dem alten Code kompatibel"
echo "sind (bei diesem Projekt sind Migrationen bislang rein additiv)."
