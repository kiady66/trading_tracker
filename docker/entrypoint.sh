#!/bin/sh
set -e

# Le volume app_var survit aux rebuilds : un cache compilé avec l'ancien code
# peut référencer des classes disparues et faire boucler le démarrage
# (vécu au passage DBAL 3 → 4). On repart toujours d'un cache neuf.
rm -rf var/cache/prod

php bin/console cache:warmup
php bin/console assets:install
php bin/console asset-map:compile

exec docker-php-entrypoint php-fpm