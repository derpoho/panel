<?php

return [
    'name' => 'Gleam',
    'category' => 'Apps',
    'description' => 'Builds your Gleam app as an Erlang shipment and runs it on the BEAM.',
    'icon' => 'gleam',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Read the PORT environment variable and bind 0.0.0.0, for example mist.bind("0.0.0.0") with Wisp or Mist.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM ghcr.io/gleam-lang/gleam:v1.19.2-erlang-alpine AS build
WORKDIR /build
COPY . .
RUN gleam export erlang-shipment

FROM erlang:28-alpine
WORKDIR /app
COPY --from=build /build/build/erlang-shipment /app
ENV PORT=8000
EXPOSE 8000
CMD ["/app/entrypoint.sh", "run"]
DOCKERFILE,
    ],
];
