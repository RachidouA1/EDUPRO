FROM php:8.2-apache

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Activer mod_rewrite Apache
RUN a2enmod rewrite

# Dossier de travail
WORKDIR /var/www/html

# Copier le projet dans /EDUPRO (pour garder APP_URL = '/EDUPRO')
COPY . /var/www/html/EDUPRO/

# Permissions
RUN chown -R www-data:www-data /var/www/html/EDUPRO \
    && find /var/www/html/EDUPRO -type d -exec chmod 755 {} \; \
    && find /var/www/html/EDUPRO -type f -exec chmod 644 {} \;

# Config Apache
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Script de démarrage
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
