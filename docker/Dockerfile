# Builds the Perysee image on your own machine from the verified perysee.zip next to this file.
# Nothing is pulled from a Perysee registry: only the public php and alpine base images.
# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.4

FROM alpine:3.21 AS release
RUN apk add --no-cache unzip
COPY perysee.zip /tmp/perysee.zip
RUN unzip -q /tmp/perysee.zip -d /opt

FROM php:${PHP_VERSION}-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql zip opcache \
    && a2enmod rewrite remoteip headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/perysee/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=release --chown=www-data:www-data /opt/perysee /var/www/perysee
RUN chown www-data:www-data /var/www/perysee
COPY docker-entrypoint.sh /usr/local/bin/perysee-entrypoint
RUN chmod 0755 /usr/local/bin/perysee-entrypoint

WORKDIR /var/www/perysee
ENTRYPOINT ["perysee-entrypoint"]
CMD ["apache2-foreground"]
