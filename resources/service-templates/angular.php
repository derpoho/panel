<?php

return [
    'name' => 'Angular',
    'category' => 'Apps',
    'description' => 'Builds your Angular app and serves the browser bundle with Nginx.',
    'icon' => 'angular',
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
# Angular writes dist/<project>/browser (or dist/<project> before v17).
RUN mkdir /site && cp -r "$(dirname "$(find dist -name index.html | sort | head -n 1)")"/. /site/

FROM nginx:1.27-alpine
COPY .serveros/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /site /usr/share/nginx/html
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
