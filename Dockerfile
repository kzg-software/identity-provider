# syntax=docker/dockerfile:1
#
# Ein einzelnes, in sich geschlossenes Image für das Auth-System.
# Enthält PHP 8.4 (FPM) + nginx + supervisor. Über die Umgebungsvariable
# CONTAINER_ROLE übernimmt derselbe Container eine von drei Rollen:
#
#   app        -> nginx + php-fpm (Weboberfläche)        [Default]
#   scheduler  -> php artisan schedule:work              (Cron-Ersatz)
#   queue      -> php artisan queue:work                 (Hintergrundjobs)
#
# Kein NodeJS/Vite nötig – das Frontend läuft über Blade + CDN.

########################################################################
# 1) Composer-Abhängigkeiten (ohne dev, optimierter Autoloader)
########################################################################
FROM composer:2 AS vendor

WORKDIR /app

# Zuerst nur die Abhängigkeiten auflösen (cachet solange composer.lock gleich
# bleibt). --no-scripts: package:discover braucht die volle App + die richtige
# PHP-Version und läuft daher zur Laufzeit im Runtime-Image (Entrypoint).
COPY composer.json composer.lock ./
# --ignore-platform-reqs: das schlanke composer-Image hat z.B. kein ext-ldap;
# die Abhängigkeiten kommen aber 1:1 aus composer.lock und laufen im
# Runtime-Image (das alle Extensions hat).
RUN composer install \
        --no-dev --no-scripts --no-autoloader --no-interaction --no-progress \
        --prefer-dist --ignore-platform-reqs

# Jetzt der komplette Quellcode, danach der optimierte Autoloader.
# --no-scripts: package:discover braucht Extensions, die das schlanke
# composer-Image nicht hat -> erledigt der Entrypoint im Runtime-Image.
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction --ignore-platform-reqs

########################################################################
# 2) Laufzeit-Image
########################################################################
FROM php:8.4-fpm-bookworm AS runtime

# --- PHP-Extensions zuverlässig via Extension-Installer ---------------
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_mysql pdo_sqlite mysqli \
        intl gd zip bcmath exif pcntl \
        ldap sodium opcache \
    && rm -f /usr/local/bin/install-php-extensions

# --- nginx + supervisor + tini + curl (Healthcheck) ------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx-light supervisor tini curl procps \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

WORKDIR /var/www/html

# --- Konfiguration --------------------------------------------------
COPY docker/php/php.ini        /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf  /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/app.conf
COPY docker/supervisord.conf   /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh      /usr/local/bin/entrypoint
COPY docker/healthcheck.sh     /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/healthcheck

# --- Anwendungscode + vendor --------------------------------------
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# --- Schreibbare Verzeichnisse anlegen; .env NICHT ins Image --------
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
        database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && rm -f .env

ENV CONTAINER_ROLE=app \
    AUTO_MIGRATE=false

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=5 \
    CMD /usr/local/bin/healthcheck

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
