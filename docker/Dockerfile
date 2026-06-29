FROM php:8.4-apache

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo pdo_mysql

# Active mod_rewrite (pour le .htaccess)
RUN a2enmod rewrite

# Config Apache : AllowOverride All
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# PHP : afficher les erreurs en local
RUN printf "display_errors = On\nerror_reporting = E_ALL\nupload_max_filesize = 50M\npost_max_size = 55M\nmemory_limit = 128M\n" > /usr/local/etc/php/conf.d/local.ini
