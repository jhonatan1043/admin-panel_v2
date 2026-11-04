#!/bin/sh
set -e

cd /var/www/html

: "${QUEUE_WORKERS:=1}"
: "${RUN_QUEUE:=true}"
: "${RUN_SCHEDULER:=true}"
: "${RUN_MIGRATIONS:=true}"
: "${TZ:=UTC}"
export QUEUE_WORKERS RUN_QUEUE RUN_SCHEDULER TZ

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY no está definido. Genera uno con: php artisan key:generate --show" >&2
    exit 1
fi

# Los volúmenes montados pueden llegar vacíos: recrear la estructura de storage
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Esperar a que la base de datos acepte conexiones
if [ "${DB_CONNECTION:-mysql}" = "mysql" ] || [ "${DB_CONNECTION:-mysql}" = "mariadb" ]; then
    echo "Esperando a la base de datos ${DB_HOST}:${DB_PORT:-3306}..."
    i=0
    until php /usr/local/bin/wait-db.php; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "ERROR: la base de datos no respondió a tiempo" >&2
            exit 1
        fi
        sleep 2
    done
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi

php artisan storage:link --force >/dev/null 2>&1 || true

php artisan optimize:clear >/dev/null
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
