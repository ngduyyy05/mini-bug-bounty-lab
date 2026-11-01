#!/bin/sh
set -eu
mkdir -p /var/lib/lab/uploads /var/www/html/public/uploads/profile /var/www/html/public/drafts
chown www-data:www-data /var/lib/lab/uploads /var/www/html/public/uploads /var/www/html/public/uploads/profile /var/www/html/public/drafts
php /var/www/html/bin/seed.php
exec apache2-foreground
