ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-apache-bookworm

ENV EDITOR=nano
WORKDIR /app

# `apt-get upgrade` pulls patched Debian point-releases for OS packages
# inherited from the php base image (e.g. libssl3, libxml2, libsqlite3-0),
# mitigating CVEs reported against the published image.
RUN apt-get update && apt-get upgrade -y \
    && apt-get install -y \
        cron \
        supervisor \
        nano \
        zip \
        unzip \
        libzip-dev \
        zlib1g-dev \
        libicu-dev \
        libpq-dev \
        netcat-traditional \
        default-mysql-client \
        less \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install exif \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pcntl posix \
    && docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql \
    && docker-php-ext-install zip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer/composer:2-bin /composer /usr/bin/composer
