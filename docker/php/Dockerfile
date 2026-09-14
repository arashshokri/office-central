FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git curl && docker-php-ext-install pdo_pgsql bcmath opcache zip && rm -rf /var/lib/apt/lists/*
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
