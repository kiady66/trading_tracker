#!/bin/sh
set -e

php bin/console cache:warmup
php bin/console assets:install
php bin/console asset-map:compile

exec docker-php-entrypoint php-fpm