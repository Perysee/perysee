#!/bin/sh
set -eu

cd /var/www/perysee

# Apply the migrations of a newer image to an instance that is already installed. A first start leaves the
# installation to `perysee:install`, which the installer runs once the database answers. Migrations are additive.
if [ "${PERYSEE_RUN_MIGRATIONS:-false}" = "true" ] \
    && [ -f storage/app/private/installation/installed ] \
    && [ "$(id -u)" = "0" ]; then
    runuser -u www-data -- php artisan migrate --force
fi

exec "$@"
