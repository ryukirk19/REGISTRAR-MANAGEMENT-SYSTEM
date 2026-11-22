FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/regis.ini

WORKDIR /var/www
COPY . /var/www

RUN mkdir -p /var/www/storage/scans /var/data \
    && chown -R www-data:www-data /var/www/storage /var/data \
    && chmod +x /var/www/docker/entrypoint.sh

ENV REGIS_DATA_DIR=/var/data
EXPOSE 8080

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
