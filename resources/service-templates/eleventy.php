<?php

return [
    'name' => 'Eleventy',
    'category' => 'Apps',
    'description' => 'Builds your Eleventy site and serves _site/ with Nginx.',
    'icon' => 'eleventy',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Runs your build script if you have one, otherwise npx @11ty/eleventy. Expects the default _site output folder.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN if node -e "process.exit(require('./package.json').scripts?.build ? 0 : 1)"; then npm run build; else npx @11ty/eleventy; fi

FROM nginx:1.27-alpine
COPY --from=build /app/_site /usr/share/nginx/html
DOCKERFILE,
    ],
];
