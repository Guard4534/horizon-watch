# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

FROM ubuntu:24.04 AS php
ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    LANG=C.UTF-8
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ noble main" > /etc/apt/sources.list.d/php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-pgsql \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/*
WORKDIR /var/www/html

FROM php AS vendor
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM vendor AS assets
ARG NODE_VERSION
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*
COPY package.json package-lock.json ./
RUN npm ci
# resources/js/generated is gitignored (regenerated on every build); typescript:transform
# requires the directory to exist beforehand. typescript:transform and Wayfinder both boot
# the application; neither needs a database.
RUN mkdir -p resources/js/generated \
    && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) npm run build

FROM php AS runtime
ARG PHP_VERSION
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/local/sbin/php-fpm
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build
COPY docker/prod/nginx.conf /etc/nginx/nginx.conf
COPY docker/prod/php-fpm.conf /etc/php/${PHP_VERSION}/fpm/horizon-watch.conf
COPY docker/prod/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-horizon-watch.ini
COPY docker/prod/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-horizon-watch.ini
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/horizon-watch.conf
COPY --chmod=755 docker/prod/entrypoint.sh /usr/local/bin/horizon-watch
RUN rm -f public/hot \
    && mkdir -p /data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data /data storage bootstrap/cache
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/horizon-watch"]
CMD ["web"]
