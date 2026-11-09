<?php

return [
    'name' => 'Dart',
    'category' => 'Apps',
    'description' => 'Compiles your Dart server (bin/server.dart) to a native binary.',
    'icon' => 'dart',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Read the PORT environment variable and bind InternetAddress.anyIPv4, like the dart create server-shelf template.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM dart:stable AS build
WORKDIR /app
COPY pubspec.* ./
RUN dart pub get
COPY . .
RUN dart pub get --offline && dart compile exe bin/server.dart -o bin/server

FROM scratch
COPY --from=build /runtime/ /
COPY --from=build /app/bin/server /app/bin/server
ENV PORT=8080
EXPOSE 8080
CMD ["/app/bin/server"]
DOCKERFILE,
    ],
];
