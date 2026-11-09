<?php

return [
    'name' => 'Vapor',
    'category' => 'Apps',
    'description' => 'Swift 6.2. Builds your Vapor app in release mode and serves it.',
    'icon' => 'vapor',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Expects the executable target to be called App, like the Vapor template. Public/ and Resources/ are copied in if you have them.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM swift:6.2-noble AS build
WORKDIR /build
COPY Package.* ./
RUN swift package resolve
COPY . .
RUN swift build -c release --static-swift-stdlib --product App
RUN BIN="$(swift build -c release --show-bin-path)" && mkdir -p /staging \
    && cp "$BIN/App" /staging/ \
    && find "$BIN" -maxdepth 1 -name '*.resources' -exec cp -R {} /staging/ \; \
    && if [ -d Public ]; then cp -R Public /staging/; fi \
    && if [ -d Resources ]; then cp -R Resources /staging/; fi

FROM ubuntu:noble
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates tzdata && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=build /staging /app
EXPOSE 8080
CMD ["sh", "-c", "exec ./App serve --env production --hostname 0.0.0.0 --port ${PORT:-8080}"]
DOCKERFILE,
    ],
];
