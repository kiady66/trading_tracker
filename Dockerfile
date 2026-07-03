FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libxml2-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        zip \
        intl \
        gd \
        opcache \
        mbstring \
        xml

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock symfony.lock importmap.php ./
ENV APP_ENV=prod APP_SECRET=placeholder
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && mkdir -p var/cache var/log public/assets public/bundles \
    && chown -R www-data:www-data var/ public/assets/ public/bundles/

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]