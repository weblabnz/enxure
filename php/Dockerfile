# Build context is the docker-invoxa/ root — see docker-compose.yml.
FROM php:8.3-fpm

# mbstring and zip: required by the PDF export and dompdf. libfcgi-bin:
# provides cgi-fcgi, used by this image's HEALTHCHECK. tesseract-ocr: powers
# the Add Expense receipt-photo vendor/amount prefill. libpng-dev + gd: only
# used by the Test Suite's Receipt OCR end-to-end check, to render a
# receipt-shaped fixture image on the fly instead of shipping a binary file.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libfcgi-bin tesseract-ocr libpng-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli \
    && php -m | grep -qi sodium || docker-php-ext-install sodium \
    && php -m | grep -qi mbstring || docker-php-ext-install mbstring \
    && php -m | grep -qi zip || docker-php-ext-install zip \
    && php -m | grep -qi gd || docker-php-ext-install gd

COPY php/php.ini /usr/local/etc/php/conf.d/invoxa.ini
COPY php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY php/docker-entrypoint.sh /usr/local/bin/invoxa-entrypoint.sh
RUN chmod +x /usr/local/bin/invoxa-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/invoxa-entrypoint.sh"]
