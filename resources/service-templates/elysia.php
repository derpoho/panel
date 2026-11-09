<?php

return [
    'name' => 'Elysia',
    'category' => 'Apps',
    'description' => 'Your Elysia app on Bun: installs, then runs your start script or src/index.ts.',
    'icon' => 'bun',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Listen on process.env.PORT, for example .listen(process.env.PORT ?? 3000).',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM oven/bun:1
WORKDIR /app
COPY package.json bun.lock* bun.lockb* ./
RUN bun install --frozen-lockfile || bun install
COPY . .
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["sh", "-c", "if bun -e \"process.exit(require('./package.json').scripts?.start ? 0 : 1)\"; then exec bun run start; else exec bun run src/index.ts; fi"]
DOCKERFILE,
    ],
];
