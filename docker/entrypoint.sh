#!/bin/sh
# Wird bei JEDEM Containerstart ausgeführt (alle Rollen). Bereitet .env,
# APP_KEY, Schreibrechte und – nur für die "app"-Rolle – Migrationen /
# storage:link vor und übergibt danach an den eigentlichen Prozess.
set -e

cd /var/www/html
ROLE="${CONTAINER_ROLE:-app}"
log() { echo "[entrypoint] $*"; }

# Ein von docker compose (env_file) als LEER übergebenes APP_KEY würde den
# echten Wert aus der .env-Datei überschreiben (gesetzte ENV schlägt Datei).
# In dem Fall aus der Umgebung entfernen, damit der Datei-Wert greift.
if [ -z "${APP_KEY:-}" ]; then
    unset APP_KEY 2>/dev/null || true
fi

########################################################################
# .env bereitstellen
#   Empfehlung: .env als Bind-Mount/Volume einhängen, damit vom Web-
#   Installer geschriebene Werte (APP_KEY, DB-Zugang, Mail) einen
#   Neustart überleben. Container-ENV (docker compose "environment:")
#   hat trotzdem Vorrang vor Werten aus der Datei.
########################################################################
if [ ! -f .env ]; then
    log ".env nicht vorhanden – aus .env.example erzeugen"
    cp .env.example .env
fi

# APP_KEY: aus Container-ENV ODER aus der .env-Datei akzeptieren.
# Fehlt er komplett, erzeugt ihn NUR die app-Rolle (verhindert eine Race-
# Condition, wenn app/scheduler/queue gleichzeitig starten und sich
# gegenseitig unterschiedliche Keys in die gemeinsame .env schreiben).
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=(base64:)?.{20,}' .env; then
    if [ "$ROLE" = "app" ]; then
        log "APP_KEY erzeugen"
        php artisan key:generate --force --no-interaction
    else
        log "APP_KEY noch nicht gesetzt – warte auf die app-Rolle ..."
        sleep 5
        exit 1   # restart: unless-stopped lässt den Container erneut starten
    fi
fi

########################################################################
# Schreibrechte auf evtl. frisch gemountete Volumes
########################################################################
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache \
    database
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
chown www-data:www-data .env 2>/dev/null || true

########################################################################
# Paket-Manifest (bewusst nicht ins Image gebacken -> hier erzeugen)
########################################################################
php artisan package:discover --ansi >/dev/null 2>&1 || true

########################################################################
# Einmalige Setup-Schritte nur in der app-Rolle (scheduler/queue nicht,
# damit nicht mehrere Container gleichzeitig migrieren)
########################################################################
if [ "$ROLE" = "app" ]; then

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
        DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
        if [ ! -f "$DB_FILE" ]; then
            log "SQLite-Datei anlegen: $DB_FILE"
            touch "$DB_FILE"
            chown www-data:www-data "$DB_FILE"
        fi
    elif [ -n "${DB_HOST:-}" ]; then
        log "Warte auf Datenbank ${DB_HOST}:${DB_PORT:-3306} ..."
        i=0
        while [ "$i" -lt 60 ]; do
            if php -r '$h=getenv("DB_HOST");$p=(int)(getenv("DB_PORT")?:3306);exit(@fsockopen($h,$p,$e,$s,2)?0:1);'; then
                break
            fi
            i=$((i + 1))
            sleep 2
        done
    fi

    if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
        log "Migrationen ausführen (AUTO_MIGRATE=true)"
        php artisan migrate --force --no-interaction || \
            log "WARN: Migration fehlgeschlagen – DB noch nicht bereit oder nicht konfiguriert?"
    fi

    if [ ! -L public/storage ]; then
        php artisan storage:link --no-interaction || true
    fi
fi

########################################################################
# Config NICHT gecacht ausliefern: der Web-Installer schreibt .env zur
# Laufzeit. route/view/event dagegen cachen (rein codebasiert, schnell).
########################################################################
php artisan config:clear >/dev/null 2>&1 || true
php artisan event:cache  >/dev/null 2>&1 || true
php artisan view:cache   >/dev/null 2>&1 || true

# Von den artisan-Läufen (als root) erzeugte Cache-Dateien der App-Gruppe geben
chown -R www-data:www-data bootstrap/cache storage 2>/dev/null || true

log "Container-Rolle: $ROLE"

case "$ROLE" in
    app)
        exec "$@"
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction
        ;;
    queue)
        exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --no-interaction
        ;;
    *)
        exec "$@"
        ;;
esac
