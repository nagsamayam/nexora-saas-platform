# ==============================================================================
# STAGE 1: Base & Extension Assembly
# ==============================================================================
FROM php:8.4-fpm-alpine AS base

# Install PHP extension installer helper
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install system extensions
RUN install-php-extensions pdo_pgsql zip gd pcntl opcache redis amqp sockets intl

# Set production configuration defaults
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod-opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"

WORKDIR /var/www/html

# ==============================================================================
# STAGE 2: Vendor Builder (Dependency Isolation)
# ==============================================================================
FROM base AS builder

# Bring in official Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Copy only dependency maps first to utilize Docker layer cache
COPY composer.json composer.lock ./

# Install vendor packages without running hooks/scripts (code isn't copied yet)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist

# ==============================================================================
# STAGE 3: Final Production Image
# ==============================================================================
FROM base AS runner

RUN adduser -D -u 1000 -h /home/laravel -G www-data laravel

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R laravel:www-data bootstrap/cache storage \
    && chmod -R 775 bootstrap/cache storage

COPY --from=builder --chown=laravel:www-data /var/www/html/vendor ./vendor
COPY --chown=laravel:www-data . .

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm /usr/bin/composer

# Copy the entrypoint script and make it executable
COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

USER laravel

EXPOSE 9000

# Set the entrypoint script to run on boot
ENTRYPOINT ["docker-entrypoint"]

CMD ["php-fpm"]
