#!/bin/sh
set -e

# Create and hand over the data directory on first start. Apache's workers
# (and so all PHP code) run as www-data; only this script runs as root.
mkdir -p "${DATA_DIR:-/data}"
chown -R www-data:www-data "${DATA_DIR:-/data}"
chmod 750 "${DATA_DIR:-/data}"

# Apply migrations now so a broken schema fails at startup, not on first request.
su -s /bin/sh www-data -c 'php -r "require \"/var/www/html/src/bootstrap.php\"; Db::get();"'

exec docker-php-entrypoint "$@"
