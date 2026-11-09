<?php

return [
    'name' => 'Deno',
    'category' => 'Apps',
    'description' => 'Runs main.ts (or your DENO_ENTRY) with network and env access.',
    'icon' => 'deno',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DENO_ENTRY', 'label' => 'Entry file', 'default' => 'main.ts'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM denoland/deno:2.1.4
WORKDIR /app
COPY . .
RUN deno cache "${DENO_ENTRY:-main.ts}" || true
ENV PORT=8000
EXPOSE 8000
CMD ["sh", "-c", "deno run --allow-net --allow-env --allow-read ${DENO_ENTRY:-main.ts}"]
DOCKERFILE,
    ],
];
