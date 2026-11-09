<?php

return [
    'name' => 'Kemal',
    'category' => 'Apps',
    'description' => 'Crystal. Builds your Kemal app into a static release binary.',
    'icon' => 'crystal',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Builds the first target in shard.yml and starts it with Kemal\'s -b and -p flags.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM crystallang/crystal:latest-alpine AS build
WORKDIR /src
COPY shard.yml shard.lock* ./
RUN shards install --production
COPY . .
RUN shards build --production --release --static --no-debug \
    && cp "bin/$(ls bin | head -1)" /app

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata
WORKDIR /srv
COPY --from=build /src /srv
COPY --from=build /app /usr/local/bin/app
ENV KEMAL_ENV=production
EXPOSE 3000
CMD ["sh", "-c", "exec app -b 0.0.0.0 -p ${PORT:-3000}"]
DOCKERFILE,
    ],
];
