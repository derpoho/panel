<?php

return [
    'name' => 'Gatsby',
    'category' => 'Apps',
    'description' => 'Builds your Gatsby site and serves public/ with Nginx.',
    'icon' => 'gatsby',
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
ENV GATSBY_TELEMETRY_DISABLED=1
RUN npm run build

FROM nginx:1.27-alpine
COPY .serveros/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /usr/share/nginx/html
DOCKERFILE,
        '.serveros/nginx.conf' => <<<'NGINX'
server {
    listen 80;
    root /usr/share/nginx/html;
    error_page 404 /404.html;

    location / {
        try_files $uri $uri/ $uri.html =404;
    }

    # Gatsby fingerprints these, so they can be cached for good.
    location ~* \.(?:js|css|woff2?|svg|png|jpg|webp|avif|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~* \.html$ {
        add_header Cache-Control "public, max-age=0, must-revalidate";
    }
}
NGINX,
    ],
];
