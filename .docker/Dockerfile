# syntax=docker/dockerfile:1.7

# Julianna is built only from the source in this repository. No release archive
# or application image from the upstream project is downloaded or inherited.

FROM node:22-alpine@sha256:b6f26b36c8ff49624cfdac716b8ea1138d606df02586a77d364bb5536a634f85 AS frontend

ARG JULIANNA_VERSION=1.0.0-dev
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN JULIANNA_VERSION="${JULIANNA_VERSION}" npx mix --production \
    && node generateBlocklist.mjs


FROM php:8.3-fpm-alpine@sha256:4574194a55e413b8eb799f4ac79233e5b7c3be30d49a7df62cf37187186fe659 AS php-build

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        openldap-dev \
        postgresql-dev \
        zstd-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" \
        bcmath \
        dom \
        exif \
        fileinfo \
        gd \
        ldap \
        mbstring \
        mysqli \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        simplexml \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
COPY app ./app
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
        --prefer-dist \
    && composer check-platform-reqs


FROM php:8.3-fpm-alpine@sha256:4574194a55e413b8eb799f4ac79233e5b7c3be30d49a7df62cf37187186fe659 AS runtime

ARG PUID=1000
ARG PGID=1000
ARG JULIANNA_VERSION=1.0.0-dev
ARG JULIANNA_COMMIT=unknown
ARG JULIANNA_SOURCE_URL=""

LABEL org.opencontainers.image.title="Julianna" \
      org.opencontainers.image.description="Independent self-hosted project and work management" \
      org.opencontainers.image.version="${JULIANNA_VERSION}" \
      org.opencontainers.image.revision="${JULIANNA_COMMIT}" \
      org.opencontainers.image.source="${JULIANNA_SOURCE_URL}" \
      org.opencontainers.image.licenses="AGPL-3.0-only"

ENV JULIANNA_VERSION="${JULIANNA_VERSION}" \
    JULIANNA_COMMIT="${JULIANNA_COMMIT}" \
    JULIANNA_SOURCE_URL="${JULIANNA_SOURCE_URL}"

RUN apk add --no-cache \
        curl \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libpq \
        libzip \
        mysql-client \
        nginx \
        openldap \
        openssl \
        supervisor \
        tini \
        zstd-libs \
    && rm -rf /var/cache/apk/* /tmp/*

COPY --from=php-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

RUN deluser www-data \
    && addgroup -g "${PGID}" www-data \
    && adduser -u "${PUID}" -G www-data -h /home/www-data -s /bin/sh -D www-data

WORKDIR /var/www/html
COPY app ./app
COPY bin ./bin
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY composer.json composer.lock LICENSE NOTICE.md index.php ./
COPY --from=php-build /build/vendor ./vendor
COPY --from=frontend /build/public/dist ./public/dist
COPY --from=frontend /build/blocklist.json ./blocklist.json

RUN mkdir -p \
        app/Plugins \
        backupdb \
        bootstrap/cache \
        public/userfiles \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        userfiles \
        /run \
        /var/lib/nginx \
        /var/log/nginx \
    && chown root:root /var/www/html \
    && chmod 0755 /var/www/html \
    && chown -R www-data:www-data \
        /var/www/html/app/Plugins \
        /var/www/html/backupdb \
        /var/www/html/bootstrap/cache \
        /var/www/html/public/userfiles \
        /var/www/html/storage \
        /var/www/html/userfiles \
        /run \
        /var/lib/nginx \
        /var/log/nginx \
    && chmod 775 \
        app/Plugins \
        backupdb \
        bootstrap/cache \
        public/userfiles \
        storage \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        userfiles

COPY .docker/config/custom.ini /usr/local/etc/php/conf.d/99-julianna.ini
COPY .docker/config/nginx.conf /etc/nginx/nginx.conf
COPY .docker/config/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY .docker/config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=0755 .docker/start.sh /start.sh

USER www-data

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/status || exit 1

EXPOSE 8080
ENTRYPOINT ["/sbin/tini", "--", "/start.sh"]
