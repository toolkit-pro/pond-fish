FROM php:8.1-apache

# Install required extensions
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create data directory with proper permissions
RUN mkdir -p /var/www/html/data && \
    chmod 755 /var/www/html/data && \
    chown www-data:www-data /var/www/html/data

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY index.php .
COPY .htaccess .

# Set Apache ServerName
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Enable mod_rewrite
RUN a2enmod rewrite

# Configure Apache for security
RUN sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-available/security.conf && \
    sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-available/security.conf

# Set proper ownership
RUN chown -R www-data:www-data /var/www/html

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php -r 'exit(file_exists("/var/www/html/index.php") ? 0 : 1);'

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
