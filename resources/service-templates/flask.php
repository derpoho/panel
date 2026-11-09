<?php

return [
    'name' => 'Flask',
    'category' => 'Apps',
    'description' => 'Python 3.12 with Gunicorn, serving app:app or your APP_MODULE.',
    'icon' => 'flask',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_MODULE', 'label' => 'App module', 'default' => 'app:app'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt gunicorn
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "gunicorn ${APP_MODULE:-app:app} --bind 0.0.0.0:${PORT:-8000} --workers 3"]
DOCKERFILE,
    ],
];
