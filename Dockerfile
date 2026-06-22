FROM php:8.4-apache

# Install libcurl dev headers, then compile the PHP curl extension
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Copy all project files into the web root
COPY . /var/www/html/

# Allow .htaccess overrides (optional but good practice)
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set loose file permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
