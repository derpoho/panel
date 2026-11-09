<?php

return [
    'name' => 'Deno Fresh',
    'category' => 'Apps',
    'description' => 'Builds your Fresh app with Deno and serves it.',
    'icon' => 'fresh',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Runs deno task build. Fresh 2 is served from _fresh/server.js, Fresh 1 from main.ts.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM denoland/deno:latest
WORKDIR /app
COPY . .
RUN deno install || true
RUN deno task build
ENV DENO_ENV=production
EXPOSE 8000
CMD ["sh", "-c", "if [ -f _fresh/server.js ]; then exec deno serve -A --host 0.0.0.0 --port ${PORT:-8000} _fresh/server.js; else exec deno run -A main.ts; fi"]
DOCKERFILE,
    ],
];
