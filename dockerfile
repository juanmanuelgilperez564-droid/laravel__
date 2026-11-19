FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    libxml2-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl xml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN git config --global --add safe.directory /var/www/html \
    && COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]