# Development image for tamas-labs/laravel-aura.
# There is deliberately no PHP or composer on the host — every command runs here.
# See CLAUDE.md for the command table.

FROM composer:2 AS composer

FROM php:8.4-cli-alpine

# Runtime libraries for the extensions built below, plus what composer needs to
# fetch and unpack packages.
RUN apk add --no-cache git unzip icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

# pcov rather than Xdebug: the only thing coverage is wanted for here is the
# line count, and pcov measures it at a fraction of the cost. Restricted to the
# sources phpunit.xml also declares, so vendor and tests are never instrumented.
RUN printf 'pcov.directory=/package/src\n' > /usr/local/etc/php/conf.d/zz-pcov.ini

# mbstring and pdo_sqlite ship with the official image; intl and zip are built
# above. Fail the build rather than discovering a missing extension in a test run.
RUN php -r 'foreach (["mbstring", "zip", "intl", "pdo_sqlite", "pcov"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "missing extension: $ext\n"); exit(1); } }'

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Development image: PHPStan at level max and the Testbench boot both exceed the
# 128M default, and nothing here runs in production.
RUN printf 'memory_limit=-1\n' > /usr/local/etc/php/conf.d/zz-development.ini

# Match the host uid/gid so files written from the container stay editable outside it.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "$GID" app \
    && adduser -u "$UID" -G app -D -h /home/app app

ENV COMPOSER_HOME=/home/app/.composer \
    COMPOSER_CACHE_DIR=/home/app/.composer/cache \
    COMPOSER_MEMORY_LIMIT=-1

# Create COMPOSER_HOME in the image so the named volume mounted over it inherits
# app's ownership; a volume over a missing directory would be owned by root and
# composer would silently fall back to running without a cache.
RUN mkdir -p /home/app/.composer/cache && chown -R app:app /home/app/.composer

USER app
WORKDIR /package
