FROM php:8.5.0-fpm-bookworm AS php-extensions

ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    curl git libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev \
    libpq-dev libzip-dev unzip \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j1 bcmath exif gd intl pcntl pdo_pgsql zip
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2.8.12 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

FROM php-extensions AS development
COPY composer.json composer.lock ./
RUN COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-interaction --prefer-dist --no-progress --no-scripts
COPY php-development-performance.ini /usr/local/etc/php/conf.d/zz-performance.ini
COPY . .
RUN composer dump-autoload --optimize
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM php-extensions AS vendor
COPY composer.json composer.lock ./
RUN COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --classmap-authoritative

FROM php:8.5.0-fpm-bookworm AS production

ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    libfreetype6 libicu72 libjpeg62-turbo libpng16-16 libpq5 libzip4 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php-extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY php-production-performance.ini /usr/local/etc/php/conf.d/zz-performance.ini
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
RUN php artisan route:cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
