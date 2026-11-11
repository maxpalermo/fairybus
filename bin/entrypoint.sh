#!/bin/bash
set -e

if [ -d /var/www/html/writable ]; then
    chown -R www-data:www-data /var/www/html/writable
fi

if [ -d /var/www/html/public ]; then
    chown -R www-data:www-data /var/www/html/public
fi

exec apache2-foreground

