<?php

return [
    'name' => 'Hono',
    'category' => 'Apps',
    'description' => 'Your Hono app on Node with @hono/node-server: builds, then npm start.',
    'icon' => 'hono',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Made for the nodejs starter. Pass port: Number(process.env.PORT) to serve() so it follows the port you pick, and make sure package.json has a start script (node dist/index.js).',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
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
