#!/bin/bash
set -e

# Ensure CI4 writable directories are owned by the web server user.
if [ -d /var/www/html/writable ]; then
    chown -R www-data:www-data /var/www/html/writable
fi

# Ensure the web server can read assets.
if [ -d /var/www/html/public ]; then
    chown -R www-data:www-data /var/www/html/public
fi

exec apache2-foreground
