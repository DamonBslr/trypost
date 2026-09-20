# syntax=docker/dockerfile:1.7

# ----------------------------------------------------------------------------
# TryPost Dockerfile — multi-stage with `dev` and `production` targets.
#
#   docker build --target dev        -t trypost:dev  -f docker/Dockerfile .
#   docker build --target production -t trypost:prod -f docker/Dockerfile .
#
# Coolify (required for layer cache):
#   - Turn OFF Advanced → Inject Build Args to Dockerfile. This file already
#     declares the only build ARGs it needs (PHP_VERSION, VITE_*, UID/GID).
#   - Mark secrets and Laravel runtime env as Runtime only (not Build Variable):
#     APP_KEY, DB/Redis, mail, Passport PEMs, OAuth, OpenRouter, etc.
#   - Leave Build Variable only for Vite bake-ins: VITE_APP_NAME, VITE_REVERB_*,
#     VITE_POSTHOG_* (and only if Coolify actually sets them).
#   - Keep Include Source Commit in Build off. Do not enable Disable Build Cache.
#   Injecting runtime secrets as ARG after each FROM busts system-base and
#   writes those values into image history.
# ----------------------------------------------------------------------------

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ----------------------------------------------------------------------------
# Stage 1: system-base — PHP-FPM + system packages + extensions
# ----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS system-base

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        git \
        unzip \
        shadow \
        tzdata \
        postgresql-client \
        mysql-client \
        libpq \
        libpng \
        libjpeg-turbo \
        freetype \
        libwebp \
        libavif \
        libzip \
        oniguruma \
        icu-libs \
    && apk add --no-cache --virtual .php-build-deps \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pdo_mysql \
        gd \
        zip \
        opcache \
        bcmath \
        exif \
        pcntl \
        intl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .php-build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ----------------------------------------------------------------------------
# Stage 2: composer-deps — full PHP deps (incl. dev) for tooling/asset build
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 3: composer-deps-prod — production deps only (no dev tooling)
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps-prod

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 4: asset-build — wayfinder TS gen + Vite client build
#
# PHP and Node together in one stage because @laravel/vite-plugin-wayfinder
# invokes `php artisan wayfinder:generate` during vite build — splitting
# the stages would mean the vite phase has no PHP available.
#
# Vite inlines VITE_* values into the JS bundle at build time. Runtime
# container env (including VITE_REVERB_HOST) has no effect on that bundle.
# Echo follows window.location when the baked host is localhost, so a
# custom HTTPS domain does not need a rebuild. The Reverb app key is
# also written onto the page from REVERB_APP_KEY at request time, so
# Coolify only needs that runtime var — VITE_REVERB_APP_KEY is a fallback.
#
# Inertia SSR is off; do not run a second Vite client build here.
# ----------------------------------------------------------------------------
FROM composer-deps AS asset-build

# Node 22 for Vite + npm scripts.
RUN apk add --no-cache nodejs npm

# Stub PHP envs so artisan can boot (overridden at runtime). Declared before
# VITE_* so changing PostHog/Reverb keys does not invalidate Node or npm ci.
ENV APP_KEY=base64:c3R1Yi13YXlmaW5kZXItZ2VuLWtleS1mb3ItYnVpbGRpbmctYXNzZXRzMA== \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost

COPY . .

# Ensure Laravel runtime dirs exist — .dockerignore strips their contents
# (correct: caches shouldn't bloat builds), but artisan boot needs the dirs.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache

RUN --mount=type=cache,target=/root/.composer/cache \
    composer dump-autoload --no-scripts --optimize \
    && php artisan wayfinder:generate --with-form

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund --cache /root/.npm

ARG VITE_APP_NAME=TryPost
ARG VITE_REVERB_APP_KEY=trypost-reverb-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ARG VITE_POSTHOG_ENABLED=false
ARG VITE_POSTHOG_API_KEY=
ARG VITE_POSTHOG_HOST=https://us.i.posthog.com

ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME} \
    VITE_POSTHOG_ENABLED=${VITE_POSTHOG_ENABLED} \
    VITE_POSTHOG_API_KEY=${VITE_POSTHOG_API_KEY} \
    VITE_POSTHOG_HOST=${VITE_POSTHOG_HOST}

RUN export VITE_REVERB_APP_KEY="${VITE_REVERB_APP_KEY:-${REVERB_APP_KEY:-trypost-reverb-key}}" \
    && npm run build

# ----------------------------------------------------------------------------
# Stage 6: dev — local development image (bind-mount the source at runtime)
# ----------------------------------------------------------------------------
FROM system-base AS dev

ARG UID=1000
ARG GID=1000

# Node + npm for in-container Vite, npm scripts, ad-hoc tooling.
RUN apk add --no-cache nodejs npm

# Create non-root app user matching host UID/GID for clean bind-mount writes.
RUN groupmod -g "${GID}" www-data 2>/dev/null || groupadd -g "${GID}" app \
    && (id -u app >/dev/null 2>&1 || useradd -u "${UID}" -g "${GID}" -d /home/app -m -s /bin/bash app) \
    && chown -R "${UID}:${GID}" /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.dev.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.dev.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/.env.docker.example /var/www/html/.env.docker.example
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV TRYPOST_TARGET=dev

EXPOSE 80 5173 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ----------------------------------------------------------------------------
# Stage 7: production — self-contained image for self-hosters
# ----------------------------------------------------------------------------
FROM system-base AS production

# php-fpm runs as www-data (Alpine default pool config) — that's the user
# storage and bootstrap/cache must be owned by, so Laravel can write logs,
# session files, view cache, etc.

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.prod.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY --from=asset-build /var/www/html/app /var/www/html/app
COPY --from=asset-build /var/www/html/bootstrap /var/www/html/bootstrap
COPY --from=asset-build /var/www/html/config /var/www/html/config
COPY --from=asset-build /var/www/html/database /var/www/html/database
COPY --from=asset-build /var/www/html/lang /var/www/html/lang
COPY --from=asset-build /var/www/html/public /var/www/html/public
COPY --from=asset-build /var/www/html/resources/views /var/www/html/resources/views
COPY --from=asset-build /var/www/html/routes /var/www/html/routes
COPY --from=asset-build /var/www/html/artisan /var/www/html/artisan
COPY --from=asset-build /var/www/html/composer.json /var/www/html/composer.json
COPY --from=asset-build /var/www/html/composer.lock /var/www/html/composer.lock
COPY --from=composer-deps-prod /var/www/html/vendor /var/www/html/vendor

RUN mkdir -p storage/app/public \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV TRYPOST_TARGET=production

EXPOSE 80 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
