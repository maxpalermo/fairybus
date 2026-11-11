#!/bin/bash
set -e

for d in cache logs session uploads backups debugbar twig/cache; do
    mkdir -p "/var/www/html/writable/$d"
done

chown -R www-data:www-data /var/www/html/writable

exec apache2-foreground
