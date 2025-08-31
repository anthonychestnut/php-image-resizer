FROM php:8.2-apache

# Install GD (used for resizing images)
RUN docker-php-ext-install gd

# Copy your PHP script into the web root
COPY image-resizer.php /var/www/html/image-resizer.php

# Add a health check endpoint
RUN echo "<?php http_response_code(200); echo 'OK';" > /var/www/html/health.php

EXPOSE 80
