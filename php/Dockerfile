# Build context is the docker-enxure/ root — see docker-compose.yml.
FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libfcgi-bin tesseract-ocr libpng-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli \
    && php -m | grep -qi sodium || docker-php-ext-install sodium \
    && php -m | grep -qi mbstring || docker-php-ext-install mbstring \
    && php -m | grep -qi zip || docker-php-ext-install zip \
    && docker-php-ext-configure gd --with-webp \
    && docker-php-ext-install gd

COPY php/php.ini /usr/local/etc/php/conf.d/enxure.ini
COPY php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY php/docker-entrypoint.sh /usr/local/bin/enxure-entrypoint.sh
RUN chmod +x /usr/local/bin/enxure-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/enxure-entrypoint.sh"]
