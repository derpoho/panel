<?php

return [
    'name' => 'Go',
    'category' => 'Apps',
    'description' => 'Builds your module into a small static binary.',
    'icon' => 'go',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM golang:1.23-alpine AS build
WORKDIR /src
COPY . .
RUN go mod download && CGO_ENABLED=0 go build -o /out/app .

FROM alpine:3.20
RUN apk add --no-cache ca-certificates
COPY --from=build /out/app /app
ENV PORT=8080
EXPOSE 8080
CMD ["/app"]
DOCKERFILE,
    ],
];
