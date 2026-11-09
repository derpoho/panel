<?php

return [
    'name' => 'Vite app',
    'category' => 'Apps',
    'description' => 'React, Vue or Svelte single-page app: builds with Vite and serves dist/ with Nginx.',
    'icon' => 'vite',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
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

FROM nginx:1.27-alpine
COPY .serveros/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
DOCKERFILE,
        '.serveros/nginx.conf' => <<<'NGINX'
server {
    listen 80;
    root /usr/share/nginx/html;

    # Single-page app: unknown paths fall back to index.html.
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(?:js|css|woff2?|svg|png|jpg|webp|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
NGINX,
    ],
];
