<?php

return [
    'name' => 'Strapi',
    'category' => 'Apps',
    'description' => 'Your Strapi 5 project: builds the admin panel and runs strapi start.',
    'icon' => 'strapi',
    'kind' => 'app',
    'port' => 1337,
    'web' => true,
    'port_editable' => true,
    'health' => '/_health',
    'notes' => 'The admin panel is at /admin. SQLite lives inside the container and is lost on redeploy, so set DATABASE_CLIENT to postgres and add DATABASE_URL (or the DATABASE_HOST fields) for anything real. Uploads go to public/uploads unless you add an upload provider.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_KEYS', 'label' => 'App keys', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'API_TOKEN_SALT', 'label' => 'API token salt', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'ADMIN_JWT_SECRET', 'label' => 'Admin JWT secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'TRANSFER_TOKEN_SALT', 'label' => 'Transfer token salt', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DATABASE_CLIENT', 'label' => 'Database client', 'default' => 'sqlite', 'hint' => 'postgres, mysql or sqlite.'],
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-slim
RUN apt-get update && apt-get install -y --no-install-recommends python3 make g++ && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
ENV NODE_ENV=production
RUN npm run build
ENV HOST=0.0.0.0 PORT=1337
EXPOSE 1337
CMD ["npm", "run", "start"]
DOCKERFILE,
    ],
];
