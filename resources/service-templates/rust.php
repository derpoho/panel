<?php

return [
    'name' => 'Rust',
    'category' => 'Apps',
    'description' => 'Builds your crate in release mode and runs its binary.',
    'icon' => 'rust',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM rust:1-slim AS build
WORKDIR /src
COPY . .
RUN cargo install --path . --root /out --locked || cargo install --path . --root /out

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*
COPY --from=build /out/bin/ /usr/local/bin/
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "exec /usr/local/bin/$(ls /usr/local/bin | head -n1)"]
DOCKERFILE,
    ],
];
