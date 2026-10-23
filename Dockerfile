# One PHP image, used by both order-app and worker (each runs a different command).
FROM php:8.3-cli

# php-amqplib needs the "sockets" extension (mbstring is already built in).
# unzip lets Composer extract packages.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install php-amqplib during the build
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist

COPY order-app/ ./order-app/
COPY worker/ ./worker/
