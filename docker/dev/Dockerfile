# The ServerOS panel's development image: PHP 8.4 with the extensions the
# app and its tests use, Composer and Node 22. The project itself is
# mounted at /app, so edits on the host show up straight away.
FROM php:8.4-cli-bookworm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip sqlite3 openssl ca-certificates \
    && install-php-extensions bcmath exif gd intl pcntl pdo_sqlite sockets zip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && printf 'memory_limit=2G\nupload_max_filesize=64M\npost_max_size=64M\n' > "$PHP_INI_DIR/conf.d/serveros.ini"

# Run as your own user, so files the container writes (vendor,
# node_modules, the database, logs) belong to you on the host.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "$GID" dev 2>/dev/null || true \
    && useradd --uid "$UID" --gid "$GID" --create-home --shell /bin/bash dev \
    && mkdir -p /home/dev/.composer /home/dev/.npm \
    && chown -R "$UID:$GID" /home/dev

USER dev
ENV COMPOSER_HOME=/home/dev/.composer
WORKDIR /app
