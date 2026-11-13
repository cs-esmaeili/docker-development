ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# phpredis: pinned 6.2.0 first (works on 8.1-8.4); newest release as fallback
# for versions where 6.2.0 does not compile (e.g. 8.5).
RUN set -eux; \
    apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libzip-dev libpq-dev \
        libssl-dev \
        $PHPIZE_DEPS \
        git unzip zip curl wget; \
    if ! pecl install redis-6.2.0; then pecl install redis; fi; \
    docker-php-ext-enable redis; \
    pecl install mongodb; \
    docker-php-ext-enable mongodb; \
    rm -rf /tmp/pear /tmp/pear*; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath exif gd intl mysqli pcntl pdo_mysql pdo_pgsql posix opcache zip; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/99-global.ini

CMD ["tail", "-f", "/dev/null"]
