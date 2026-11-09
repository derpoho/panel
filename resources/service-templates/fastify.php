<?php

return [
    'name' => 'Fastify',
    'category' => 'Apps',
    'description' => 'Your Fastify app on Node 22: installs, builds if there is a build script, then npm start.',
    'icon' => 'fastify',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Fastify only listens on localhost unless told otherwise. fastify-cli picks up FASTIFY_ADDRESS and FASTIFY_PORT, which are set for you; if you call listen() yourself, pass { port: process.env.PORT, host: "0.0.0.0" }.',
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
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0 FASTIFY_ADDRESS=0.0.0.0
EXPOSE 3000
CMD ["sh", "-c", "FASTIFY_PORT=${PORT:-3000} exec npm start"]
DOCKERFILE,
    ],
];
