<?php

return [
    'name' => 'MkDocs',
    'category' => 'Apps',
    'description' => 'Builds your MkDocs docs and serves site/ with Nginx.',
    'icon' => 'materialformkdocs',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Installs requirements.txt if you have one, otherwise mkdocs-material. Set site_url in mkdocs.yml to the address you serve it on.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim AS build
WORKDIR /docs
COPY . .
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; else pip install --no-cache-dir mkdocs-material; fi
RUN mkdocs build -d /out

FROM nginx:1.27-alpine
COPY --from=build /out /usr/share/nginx/html
DOCKERFILE,
    ],
];
