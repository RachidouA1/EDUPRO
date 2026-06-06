#!/bin/sh
set -e

UPLOADS_DIR=/var/www/html/EDUPRO/assets/uploads

mkdir -p "$UPLOADS_DIR"
chown -R www-data:www-data "$UPLOADS_DIR"
chmod 775 "$UPLOADS_DIR"

exec docker-php-entrypoint apache2-foreground
