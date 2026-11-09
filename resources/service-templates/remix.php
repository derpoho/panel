<?php

return [
    'name' => 'Remix',
    'category' => 'Apps',
    'description' => 'Builds your Remix app and serves it with remix-serve.',
    'icon' => 'remix',
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
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["npm", "start"]
DOCKERFILE,
    ],
];
