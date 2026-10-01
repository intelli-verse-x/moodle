# syntax=docker/dockerfile:1.7
# Production image for learn.toba-tech.ai from this repository's tree.
# Moodle 5 serves the site from public/; config.php stays at the repo root.
ARG PHP_IMAGE=public.ecr.aws/docker/library/php:8.4-apache-bookworm
FROM ${PHP_IMAGE}

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libzip-dev libicu-dev libxml2-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd intl mysqli pdo_mysql zip soap opcache exif \
    && a2enmod rewrite headers remoteip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/moodle.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/moodle-entrypoint
RUN chmod 0755 /usr/local/bin/moodle-entrypoint \
    && rm -rf /var/www/html/.git

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/moodle-entrypoint"]
