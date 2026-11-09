<?php

return [
    'name' => 'Express',
    'category' => 'Apps',
    'description' => 'Your Express app on Node 22: installs, builds if there is a build script, then npm start.',
    'icon' => 'express',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Listen on process.env.PORT so the port you pick here is the one Express uses.',
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
