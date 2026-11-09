<?php

return [
    'name' => 'VitePress',
    'category' => 'Apps',
    'description' => 'Builds your VitePress docs with docs:build and serves them with Nginx.',
    'icon' => 'vitepress',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Runs the docs:build script (or build if there is none) and serves whichever .vitepress/dist it makes. Clean URLs work.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN if node -e "process.exit(require('./package.json').scripts?.['docs:build'] ? 0 : 1)"; then npm run docs:build; else npm run build; fi && \
    dist=$(find . -path ./node_modules -prune -o -type d -path '*/.vitepress/dist' -print | head -n1) && \
    test -n "$dist" && mkdir -p /out && cp -r "$dist"/. /out/

FROM nginx:1.27-alpine
COPY .serveros/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /out /usr/share/nginx/html
DOCKERFILE,
        '.serveros/nginx.conf' => <<<'NGINX'
server {
    listen 80;
    root /usr/share/nginx/html;
    error_page 404 /404.html;

    # Clean URLs: /guide serves guide.html.
    location / {
        try_files $uri $uri.html $uri/ =404;
    }

    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
NGINX,
    ],
];
