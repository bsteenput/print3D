#!/bin/sh
set -e
php /var/www/html/docker/migrate.php
exec apache2-foreground
