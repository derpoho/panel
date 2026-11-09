<?php

return [
    'name' => 'React Router',
    'category' => 'Apps',
    'description' => 'React Router 7 in framework mode: builds and serves it with react-router-serve.',
    'icon' => 'reactrouter',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'For server rendering (the default). A plain SPA build with ssr: false fits the Vite app template better.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build && npm prune --omit=dev

FROM node:22-alpine
WORKDIR /app
COPY --from=build /app/package*.json ./
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/build ./build
ENV NODE_ENV=production PORT=3000
EXPOSE 3000
CMD ["npm", "run", "start"]
DOCKERFILE,
    ],
];
