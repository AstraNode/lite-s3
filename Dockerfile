FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    default-mysql-client \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_sqlite pdo_mysql mysqli

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache for large files (5GB+)
RUN echo '<Directory /var/www/html>' >> /etc/apache2/apache2.conf && \
    echo '    AllowOverride All' >> /etc/apache2/apache2.conf && \
    echo '    Require all granted' >> /etc/apache2/apache2.conf && \
    echo '    LimitRequestBody 6442450944' >> /etc/apache2/apache2.conf && \
    echo '</Directory>' >> /etc/apache2/apache2.conf

# PHP settings for large files (5GB+)
COPY php.ini /usr/local/etc/php/conf.d/99-custom.ini

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Create required directories
RUN mkdir -p /var/www/html/storage /var/www/html/logs /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]