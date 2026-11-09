<?php

return [
    'name' => 'Payload CMS',
    'category' => 'Apps',
    'description' => 'Your Payload 3 project: builds the Next.js app and serves it with next start.',
    'icon' => 'payloadcms',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/access',
    'notes' => 'The admin panel is at /admin. Payload needs a database: point DATABASE_URI at a Postgres or MongoDB service on this machine. If your pages query Payload while building, keep them dynamic, since the build has no database.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'PAYLOAD_SECRET', 'label' => 'Payload secret', 'default' => '{random:48}', 'secret' => true, 'hint' => 'Generated. Keep it the same between deploys.'],
        ['key' => 'DATABASE_URI', 'label' => 'Database URI', 'default' => '', 'secret' => true, 'hint' => 'postgres://user:pass@host:5432/db or mongodb://host/db'],
        ['key' => 'NEXT_PUBLIC_SERVER_URL', 'label' => 'Server URL', 'default' => '{url}'],
        ['key' => 'NEXT_TELEMETRY_DISABLED', 'label' => 'Disable telemetry', 'default' => '1'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
RUN apk add --no-cache libc6-compat
WORKDIR /app
COPY . .
RUN corepack enable && \
    if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; \
    elif [ -f yarn.lock ]; then yarn install --frozen-lockfile; \
    else npm ci || npm install; fi
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build
ENV NODE_ENV=production PORT=3000 HOSTNAME=0.0.0.0
EXPOSE 3000
CMD ["npm", "start"]
DOCKERFILE,
    ],
];
