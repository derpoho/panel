<?php

return [
    'name' => 'Bun',
    'category' => 'Apps',
    'description' => 'Installs with Bun and runs bun run start.',
    'icon' => 'bun',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM oven/bun:1
WORKDIR /app
COPY . .
RUN bun install --production || bun install
ENV NODE_ENV=production PORT=3000
EXPOSE 3000
CMD ["bun", "run", "start"]
DOCKERFILE,
    ],
];
