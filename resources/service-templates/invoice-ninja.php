<?php

return [
    'name' => 'Invoice Ninja',
    'category' => 'Self-hosted',
    'description' => 'Invoices, quotes, expenses and online payments for freelancers and small businesses.',
    'icon' => 'invoiceninja',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the admin email and password you set here. The first start sets up the database and takes a few minutes.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true],
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  app:
    image: invoiceninja/invoiceninja-debian:latest
    restart: unless-stopped
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_started
    environment:
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      APP_ENV: production
      APP_DEBUG: "false"
      REQUIRE_HTTPS: "false"
      TRUSTED_PROXIES: "*"
      IS_DOCKER: "true"
      PDF_GENERATOR: snappdf
      FILESYSTEM_DISK: debian_docker
      CACHE_DRIVER: redis
      QUEUE_CONNECTION: redis
      SESSION_DRIVER: redis
      REDIS_HOST: redis
      REDIS_PORT: 6379
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: ninja
      DB_USERNAME: ninja
      DB_PASSWORD: ${DB_PASSWORD}
      IN_USER_EMAIL: ${ADMIN_EMAIL}
      IN_PASSWORD: ${ADMIN_PASSWORD}
      MAIL_MAILER: log
    volumes:
      - public:/var/www/html/public
      - storage:/var/www/html/storage
  nginx:
    image: nginx:alpine
    restart: unless-stopped
    depends_on: [app]
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - public:/var/www/html/public:ro
      - storage:/var/www/html/storage:ro
  mysql:
    image: mysql:8
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ninja
      MYSQL_USER: ninja
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 10
    volumes:
      - mysql:/var/lib/mysql
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  public:
  storage:
  mysql:
  redis:
YAML,
        'nginx.conf' => <<<'NGINX'
client_max_body_size 10M;
client_body_buffer_size 10M;
server_tokens off;
fastcgi_buffers 32 16K;

server {
    listen 80 default_server;
    server_name _;
    root /var/www/html/public;
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX,
    ],
];
