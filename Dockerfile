FROM php:8.3-cli

LABEL org.opencontainers.image.title="XML Faktur Toolkit" \
      org.opencontainers.image.version="3.4"

WORKDIR /var/www/html

# Install dependencies needed by PhpSpreadsheet (Xls reader)
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    git \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Create tmp directory
RUN mkdir -p app/tmp

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "app"]
