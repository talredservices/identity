FROM php:8.3-fpm-alpine

RUN apk add --no-cache $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install bcmath intl mbstring pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY apps/identity-server/composer.json apps/identity-server/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY apps/identity-server ./
COPY docker/identity-server/entrypoint.sh /usr/local/bin/identity-entrypoint

RUN chmod +x /usr/local/bin/identity-entrypoint \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["identity-entrypoint"]
CMD ["php-fpm"]
