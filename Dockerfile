FROM php:8.4-apache

# Copy all project files into the web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod +x /var/www/html/run.php

EXPOSE 80
