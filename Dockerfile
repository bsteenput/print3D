FROM php:8.4-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Désactive l'affichage des erreurs en production
RUN echo "display_errors = Off\nerror_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE" \
    > /usr/local/etc/php/conf.d/prod.ini

# Copie le code applicatif
COPY --chown=www-data:www-data . /var/www/html

# Supprime les fichiers dev inutiles dans l'image
RUN rm -f /var/www/html/config/config.local.php \
 && rm -f /var/www/html/docker-compose*.yml \
 && mkdir -p /var/www/html/uploads \
 && chown www-data:www-data /var/www/html/uploads
