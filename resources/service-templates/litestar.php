<?php

return [
    'name' => 'Litestar',
    'category' => 'Apps',
    'description' => 'Python 3.12 with Uvicorn, serving app:app or your APP_MODULE.',
    'icon' => 'code',
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
RUN pip install --no-cache-dir -r requirements.txt litestar "uvicorn[standard]"
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "exec uvicorn ${APP_MODULE:-app:app} --host 0.0.0.0 --port ${PORT:-8000} --proxy-headers"]
DOCKERFILE,
    ],
];
