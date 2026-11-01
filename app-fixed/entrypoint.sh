#!/bin/sh
set -eu
mkdir -p /var/lib/lab/uploads /var/www/html/public/uploads
chown www-data:www-data /var/lib/lab/uploads /var/www/html/public/uploads
php /var/www/html/bin/seed.php
exec apache2-foreground
