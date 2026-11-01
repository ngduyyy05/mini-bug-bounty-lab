FROM php:8.3-apache
COPY index.php /var/www/html/index.php
RUN echo 'FallbackResource /index.php' > /etc/apache2/conf-enabled/delivery.conf
