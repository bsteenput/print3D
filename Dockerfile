FROM php:8.4-apache

# Dépendances système + extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite

# Config Apache
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# PHP production
RUN printf "display_errors = Off\nerror_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE\nupload_max_filesize = 50M\npost_max_size = 55M\nmemory_limit = 128M\n" \
    > /usr/local/etc/php/conf.d/prod.ini

# Code applicatif
COPY --chown=www-data:www-data . /var/www/html

# Nettoyage fichiers dev
RUN rm -f  /var/www/html/config/config.local.php \
 && rm -rf /var/www/html/docker-compose*.yml \
 && rm -rf /var/www/html/tools \
 && mkdir -p /var/www/html/uploads \
 && chown www-data:www-data /var/www/html/uploads

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=45s --retries=5 \
    CMD curl -s http://localhost/ -o /dev/null || exit 1
