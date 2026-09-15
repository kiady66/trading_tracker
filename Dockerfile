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
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction

COPY . .

# .env est exclu de l'image (secrets) mais le runtime Symfony exige sa présence ;
# les variables d'environnement injectées par compose ont priorité sur ce stub.
RUN printf '%s\n' \
    'APP_ENV=prod' \
    'DATABASE_URL=postgresql://app:app@database:5432/app?serverVersion=16&charset=utf8' \
    'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0' \
    'MAILER_DSN=null://null' \
    'FIREBASE_PROJECT_ID=' \
    'FIREBASE_API_KEY=' \
    'FIREBASE_AUTH_DOMAIN=' \
    'FIREBASE_CREDENTIALS=' \
    'R2_BUCKET=' \
    'R2_ENDPOINT=' \
    'R2_ACCESS_KEY_ID=' \
    'R2_SECRET_ACCESS_KEY=' \
    'SCREENSHOTS_BASE_URL=' \
    > .env \
    && php bin/console importmap:install

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && mkdir -p var/cache var/log public/assets public/bundles \
    && chown -R www-data:www-data var/ public/assets/ public/bundles/

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]