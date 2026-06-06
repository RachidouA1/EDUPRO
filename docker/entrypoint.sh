#!/bin/sh
set -e

UPLOADS_DIR=/var/www/html/EDUPRO/assets/uploads

mkdir -p \
    "$UPLOADS_DIR" \
    "$UPLOADS_DIR/etudiants" \
    "$UPLOADS_DIR/ecoles" \
    "$UPLOADS_DIR/enseignants"

chown -R www-data:www-data "$UPLOADS_DIR"
chmod -R 775 "$UPLOADS_DIR"

exec docker-php-entrypoint apache2-foreground
