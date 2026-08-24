#!/bin/sh
# The repo is bind-mounted over /var/www/html, so uploads/ arrives owned by
# whichever host user cloned it -- shehitastaging on staging, root on production.
# Apache serves as www-data (uid 33) and must be able to create
# uploads/tenants/<slug>/ during signup (lib/provision.php). Without this, every
# company signup fails at mkdir and rolls back with a generic error.
#
# Errors are tolerated because chown/chmod are no-ops on the Windows and macOS
# bind mounts used in development, where permissions are not enforced anyway.
set -e

mkdir -p /var/www/html/uploads/tenants
chown -R www-data:www-data /var/www/html/uploads || true
chmod -R u+rwX /var/www/html/uploads || true

exec "$@"
