FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/skillpass-entrypoint
RUN chmod +x /usr/local/bin/skillpass-entrypoint

ENV PORT=8080
EXPOSE 8080

CMD ["skillpass-entrypoint"]