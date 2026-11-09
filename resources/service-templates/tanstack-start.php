<?php

return [
    'name' => 'TanStack Start',
    'category' => 'Apps',
    'description' => 'Builds your TanStack Start app and runs its Node server.',
    'icon' => 'tanstack',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Runs .output/server/index.mjs, so add the Nitro Vite plugin as the TanStack hosting guide shows. Without it, it falls back to your start script.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["sh", "-c", "if [ -f .output/server/index.mjs ]; then exec node .output/server/index.mjs; else exec npm start; fi"]
DOCKERFILE,
    ],
];
