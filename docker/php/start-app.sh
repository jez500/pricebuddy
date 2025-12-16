#!/bin/bash

# Setup the environment file if it doesn't exist.
if [ ! -f ".env" ] ||  ! grep -q . ".env" ; then
    cp .env.example .env
    php artisan key:generate --force
fi

# Debugging
printenv

# Ensure storage exists
mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && chmod -R 777 storage

# Setup storage and clear caches
php artisan storage:link
php artisan config:clear
php artisan optimize:clear

# wait for the database to be available
while ! nc -z ${DB_HOST:-database} ${DB_PORT:-3306}; do
  >&2 echo "Database unavailable - sleeping"
  sleep 1
done

# Run migrations and seed the database if required.
php artisan buddy:init-db

# Cache it all.
php artisan cache:clear
php artisan optimize
php artisan icons:cache
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan buddy:regenerate-price-cache

# Install xdebug if running in Lando environment
if [ ! -z "${LANDO_INFO}" ]; then
    pecl install xdebug && docker-php-ext-enable xdebug
fi

# Start supervisor that handles cron and apache.
supervisord -c /etc/supervisor/conf.d/supervisord.conf
