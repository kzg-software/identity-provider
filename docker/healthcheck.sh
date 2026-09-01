#!/bin/sh
# Rollenabhängiger Healthcheck (siehe HEALTHCHECK im Dockerfile).
case "${CONTAINER_ROLE:-app}" in
    app)
        exec curl -fsS -o /dev/null http://127.0.0.1:8080/up
        ;;
    scheduler)
        pgrep -f 'artisan schedule:work' >/dev/null
        ;;
    queue)
        pgrep -f 'artisan queue:work' >/dev/null
        ;;
    *)
        exit 0
        ;;
esac
