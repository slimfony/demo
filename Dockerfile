# syntax=docker/dockerfile:1
ARG FRANKENPHP_VERSION=1.13.1-php8.4-trixie
ARG FRANKENPHP_RELEASE=1.13.1

# ---- base: FrankenPHP with the extensions and the production php.ini, running as a non-root user
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS base
ARG FRANKENPHP_RELEASE
# shown on the page (the image does not export its own version to PHP)
ENV FRANKENPHP_VERSION=${FRANKENPHP_RELEASE}
RUN install-php-extensions apcu opcache pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php/prod.ini ${PHP_INI_DIR}/conf.d/zz-demo-prod.ini
# FrankenPHP listens on unprivileged ports (8080, 8443), so it needs no capabilities
RUN groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --no-create-home --shell /usr/sbin/nologin app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

# ---- build: dependencies and the compiled container (warmup), done once at image build time
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
# a download can time out on a busy network (and composer under ZTS PHP then sometimes aborts): try three times
ENV COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_PROCESS_TIMEOUT=600
RUN for attempt in 1 2 3; do \
        composer install --no-dev --no-interaction --no-progress --no-scripts --classmap-authoritative && exit 0; \
        echo "composer install failed (attempt $attempt)"; sleep 5; \
    done; exit 1
COPY src ./src
COPY config ./config
COPY public ./public
COPY bin ./bin
RUN composer dump-autoload --no-dev --no-interaction --classmap-authoritative \
    && vendor/bin/slimfony warmup config/api/services.php config/api/routes.php var/cache/api \
    && vendor/bin/slimfony warmup config/idp/services.php config/idp/routes.php var/cache/idp \
    && vendor/bin/slimfony warmup config/relay/services.php config/relay/routes.php var/cache/relay

# ---- runtime: only what is needed to run
FROM base AS runtime
WORKDIR /app
COPY --from=build /app /app
COPY ui /app/ui
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/Caddyfile.idp /etc/frankenphp/Caddyfile.idp
USER app
EXPOSE 8080 8443
