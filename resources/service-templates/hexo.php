<?php

return [
    'name' => 'Hexo',
    'category' => 'Apps',
    'description' => 'Builds your Hexo blog and serves public/ with Nginx.',
    'icon' => 'hexo',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Runs your build script if you have one, otherwise hexo generate. Uses the url in your _config.yml.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN if node -e "process.exit(require('./package.json').scripts?.build ? 0 : 1)"; then npm run build; else npx hexo generate; fi

FROM nginx:1.27-alpine
COPY --from=build /app/public /usr/share/nginx/html
DOCKERFILE,
    ],
];
