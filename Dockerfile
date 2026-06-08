FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    mysql-client \
    supervisor

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mbstring \
    intl \
    zip \
    pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

RUN mkdir -p storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Supervisor config để chạy cả web server lẫn queue worker
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 10000

CMD ["sh", "-c", "php artisan migrate --force && supervisord -c /etc/supervisord.conf"]
