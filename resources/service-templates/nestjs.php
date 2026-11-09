<?php

return [
    'name' => 'NestJS',
    'category' => 'Apps',
    'description' => 'Builds your NestJS API and runs dist/main with Node 22.',
    'icon' => 'nestjs',
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
RUN npm run build && npm prune --omit=dev
ENV NODE_ENV=production PORT=3000
EXPOSE 3000
CMD ["node", "dist/main"]
DOCKERFILE,
    ],
];
