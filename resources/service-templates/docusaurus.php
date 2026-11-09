<?php

return [
    'name' => 'Docusaurus',
    'category' => 'Apps',
    'description' => 'Builds your Docusaurus docs and serves build/ with Nginx.',
    'icon' => 'docusaurus',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Set url and baseUrl in docusaurus.config to the address you serve it on.',
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
COPY --from=build /app/build /usr/share/nginx/html
DOCKERFILE,
        '.serveros/nginx.conf' => <<<'NGINX'
server {
    listen 80;
    root /usr/share/nginx/html;
    error_page 404 /404.html;

    location / {
        try_files $uri $uri/ $uri.html =404;
    }

    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
NGINX,
    ],
];
