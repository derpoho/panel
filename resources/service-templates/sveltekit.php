<?php

return [
    'name' => 'SvelteKit',
    'category' => 'Apps',
    'description' => 'For apps using adapter-node: builds, then runs node build.',
    'icon' => 'svelte',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build --if-present && npm prune --omit=dev
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["node", "build"]
DOCKERFILE,
    ],
];
