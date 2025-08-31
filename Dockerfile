# Use PHP with Apache
FROM php:8.1-apache

# Install system dependencies required by GD
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Copy your PHP script into the web root
COPY image-resizer.php /var/www/html/

# Add a health check endpoint
RUN echo "<?php http_response_code(200); echo 'OK'; ?>" > /var/www/html/health.php

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]

