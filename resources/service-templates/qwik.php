<?php

return [
    'name' => 'Qwik City',
    'category' => 'Apps',
    'description' => 'Builds your Qwik City app and runs it with its Node adapter.',
    'icon' => 'qwik',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Needs a Node adapter. Add one with npm run qwik add express (or node-server); that gives you the serve script this runs.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build
ENV NODE_ENV=production PORT=3000 HOST=0.0.0.0
EXPOSE 3000
CMD ["npm", "run", "serve"]
DOCKERFILE,
    ],
];
