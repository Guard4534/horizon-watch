#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

export PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

as_app() {
    setpriv --reuid=www-data --regid=www-data --init-groups "$@"
}

wait_for_database() {
    local attempts=60

    until php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE"));
        new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    ' >/dev/null 2>&1; do
        attempts=$((attempts - 1))
        if [ "$attempts" -le 0 ]; then
            echo "horizon-watch: database not reachable" >&2
            exit 1
        fi
        sleep 1
    done
}

# The key encrypts stored Horizon credentials: generate it once and keep it on
# the data volume, unless the operator provides one explicitly.
load_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        return
    fi

    chown www-data:www-data /data
    local key_file=/data/app-key

    if [ ! -s "$key_file" ]; then
        as_app php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
        chown www-data:www-data "$key_file"
        chmod 600 "$key_file"
    fi

    export APP_KEY="$(cat "$key_file")"
}

role="${1:-web}"

case "$role" in
    web)
        wait_for_database
        load_app_key
        as_app php artisan migrate --force --no-interaction
        as_app php artisan optimize
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/horizon-watch.conf
        ;;
    scheduler)
        wait_for_database
        load_app_key
        as_app php artisan optimize
        exec setpriv --reuid=www-data --regid=www-data --init-groups php artisan schedule:work
        ;;
    worker)
        wait_for_database
        load_app_key
        as_app php artisan optimize
        exec setpriv --reuid=www-data --regid=www-data --init-groups php artisan queue:work --max-time=3600 --tries=3
        ;;
    *)
        load_app_key
        exec "$@"
        ;;
esac
