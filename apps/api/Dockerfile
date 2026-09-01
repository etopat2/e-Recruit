FROM php:8.5.0-fpm-bookworm AS php-base

ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl git libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev \
    libpq-dev libzip-dev unzip \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j1 bcmath exif gd intl pcntl pdo_pgsql zip
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2.8.12 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php-base AS development
COPY composer.json composer.lock ./
RUN COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-interaction --prefer-dist --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --optimize
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM php-base AS production
COPY composer.json composer.lock ./
RUN COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
