<?php

return [
    'name' => 'Static site',
    'category' => 'Apps',
    'description' => 'Serves your repository (or its public/ or dist/ folder) with Nginx.',
    'icon' => 'html',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM nginx:1.27-alpine
COPY . /tmp/site
RUN if [ -d /tmp/site/dist ]; then cp -r /tmp/site/dist/. /usr/share/nginx/html/; \
    elif [ -d /tmp/site/public ]; then cp -r /tmp/site/public/. /usr/share/nginx/html/; \
    else cp -r /tmp/site/. /usr/share/nginx/html/; fi && rm -rf /tmp/site
DOCKERFILE,
    ],
];
