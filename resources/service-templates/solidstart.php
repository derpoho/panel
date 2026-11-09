<?php

return [
    'name' => 'SolidStart',
    'category' => 'Apps',
    'description' => 'Builds your SolidStart app and runs the Node server it outputs.',
    'icon' => 'solid',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Uses the default node-server preset, which writes .output/server/index.mjs.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build

FROM node:22-alpine
WORKDIR /app
COPY --from=build /app/.output ./.output
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
DOCKERFILE,
    ],
];
