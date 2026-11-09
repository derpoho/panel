# syntax=docker/dockerfile:1
#
# The ServerOS panel, for running on your own server (self-hosted). One
# image runs every part: the web server (FrankenPHP, which is Caddy with
# PHP built in, so HTTPS certificates come on their own), the queue
# worker, the scheduler and the gateway the daemons connect to. Which one
# a container is comes from its command (see entrypoint.sh); compose.yaml
# runs one of each, with PostgreSQL 16 and Redis.
#
# Built from the repository's root:
#
#   docker build -f docker/self-hosted/Dockerfile -t serveros/panel .
#
# A checkout that requires extension packages from private repositories
# (docs/extensions.md) also needs a Composer auth JSON that can read them:
# add --secret id=composer_auth,env=COMPOSER_AUTH. The packages named in
# REMOVE_PACKAGES are left out of the image (serveroshq/serveros.com, the
# parts only serveros.com runs, when it's there); any other stays in, and
# an image with a private package's code in it is for your own use, not
# for publishing. See docs/self-hosting.md.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm

# The gateway (serveros-gateway) from the daemon's public repository, at
# the release tag the panel is tested with.
FROM rust:1-bookworm AS gateway
ARG DAEMON_REF=v0.1.1-pre.36
RUN git clone --depth 1 --branch "${DAEMON_REF}" https://github.com/serveroshq/daemon.git /src
WORKDIR /src
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release --locked -p serveros-gateway \
    && cp target/release/serveros-gateway /usr/local/bin/serveros-gateway

# PHP with the extensions the panel uses.
FROM ${PHP_IMAGE} AS php
RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_pgsql redis sockets zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client ca-certificates curl libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

# Composer and Node build the app: vendor/ and public/build.
FROM php AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
WORKDIR /app

# The packages first, so code changes don't install them again. The
# serveros.com package is removed from the lock (only its entry: every
# other package stays on its locked version).
ARG REMOVE_PACKAGES="serveroshq/serveros.com"
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth,env=COMPOSER_AUTH \
    --mount=type=cache,target=/root/.composer/cache \
    for package in ${REMOVE_PACKAGES}; do \
        if grep -q "\"${package}\"" composer.json; then \
            composer remove "${package}" --no-update --no-scripts; \
        fi; \
    done \
    && composer update ${REMOVE_PACKAGES} --no-dev --no-scripts --no-autoloader --no-install --prefer-dist \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY . .
# Never a developer's .env, build or caches from the context, nor what a
# local build copied into public/ from a package (each folder's .gitignore
# from vite.config.ts lists them), like serveros.com's install.sh and
# robots.txt: without them, /install.sh is the panel's own, pointing at
# this install (DaemonInstallScriptController), and /robots.txt the
# panel's. The route helpers are written for serveros.com's domain, so
# links to the docs go to docs.serveros.com; workspace links are paths,
# whatever the domain.
RUN rm -rf .env public/build public/hot bootstrap/cache/*.php \
    && for ignore in $(grep -rlx --include=.gitignore '# From a private package: vite.config.ts copies these in.' public); do \
        [ -f "$ignore" ] || continue; \
        dir=$(dirname "$ignore"); \
        if grep -qx '\*' "$ignore"; then rm -rf "$dir"; \
        else sed -n 's#^/##p' "$ignore" | while read -r file; do rm -rf "${dir:?}/$file"; done; fi; \
    done \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && APP_URL=https://serveros.com APP_DOMAIN=serveros.com npm run build \
    && rm -rf node_modules tests storage/logs/* storage/framework/views/*

# What runs.
FROM php AS panel
ARG SERVEROS_VERSION=dev
COPY --from=gateway /usr/local/bin/serveros-gateway /usr/local/bin/serveros-gateway
COPY --from=build /app /app
COPY docker/self-hosted/Caddyfile /etc/caddy/Caddyfile
COPY docker/self-hosted/php.ini /usr/local/etc/php/conf.d/zz-serveros.ini
COPY docker/self-hosted/entrypoint.sh /usr/local/bin/serveros-entrypoint

# Everything runs as one unprivileged user; the web server may still bind
# ports 80 and 443. The app's .env points into storage (a volume), where
# the web installer saves what it's given (email, for one); compose.yaml's
# own settings still come first.
RUN useradd --uid 1000 --user-group --home-dir /app --no-create-home serveros \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /app/storage/app/private /app/storage/app/public /app/storage/framework/cache \
        /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
        /app/bootstrap/cache /data/caddy /config/caddy /gateway \
    && chown -R serveros:serveros /app/storage /app/bootstrap/cache /data /config /gateway \
    && chmod +x /usr/local/bin/serveros-entrypoint \
    && echo "${SERVEROS_VERSION}" > /app/VERSION \
    && ln -s storage/app/private/settings.env /app/.env

USER serveros
WORKDIR /app
ENV SERVEROS_EDITION=self-hosted \
    SERVEROS_DOCKER=true \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

EXPOSE 80 443 443/udp 8443
# FrankenPHP's own check asks Caddy, which only the web container runs:
# compose.yaml checks that one itself.
HEALTHCHECK NONE
ENTRYPOINT ["serveros-entrypoint"]
CMD ["web"]
