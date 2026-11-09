<?php

return [
    'name' => 'Next.js',
    'category' => 'Apps',
    'description' => 'Builds your Next.js app and serves it with next start.',
    'icon' => 'nextjs',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'NEXT_TELEMETRY_DISABLED', 'label' => 'Disable telemetry', 'default' => '1'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build --if-present && npm prune --omit=dev
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["npm", "start"]
DOCKERFILE,
    ],
];
