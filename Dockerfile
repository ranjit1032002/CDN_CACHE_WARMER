FROM php:8.4-apache

# Copy all project files into the web root
COPY . /var/www/html/

# Set loose file permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
