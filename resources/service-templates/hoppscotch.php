<?php

return [
    'name' => 'Hoppscotch',
    'category' => 'Infrastructure',
    'description' => 'API client for REST, GraphQL and WebSockets that your team shares, with Postgres.',
    'icon' => 'hoppscotch',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The app is at your URL, the admin dashboard at /admin and the API at /backend. Open /admin first to set up sign-in (email or GitHub, Google and Microsoft) and become the admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Data encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  hoppscotch:
    image: hoppscotch/hoppscotch:latest
    restart: unless-stopped
    depends_on: [db]
    command: ["sh", "-c", "export VITE_BACKEND_WS_URL=\"$$(echo \"$$VITE_BASE_URL\" | sed 's/^http/ws/')/backend/graphql\" && pnpm exec prisma migrate deploy && exec node /usr/src/app/aio_run.mjs"]
    environment:
      DATABASE_URL: postgresql://hoppscotch:${DB_PASSWORD}@db:5432/hoppscotch
      DATA_ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      WHITELISTED_ORIGINS: ${APP_URL},app://hoppscotch
      TRUST_PROXY: "true"
      ENABLE_SUBPATH_BASED_ACCESS: "true"
      VITE_BASE_URL: ${APP_URL}
      VITE_SHORTCODE_BASE_URL: ${APP_URL}
      VITE_ADMIN_URL: ${APP_URL}/admin
      VITE_BACKEND_GQL_URL: ${APP_URL}/backend/graphql
      VITE_BACKEND_API_URL: ${APP_URL}/backend/v1
    ports:
      - "127.0.0.1:${PORT}:80"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: hoppscotch
      POSTGRES_USER: hoppscotch
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
