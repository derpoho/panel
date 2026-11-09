<?php

return [
    'name' => 'Django',
    'category' => 'Apps',
    'description' => 'Python 3.12 with Gunicorn. Collects static files and migrates on boot.',
    'icon' => 'django',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DJANGO_WSGI', 'label' => 'WSGI module', 'default' => 'config.wsgi', 'hint' => 'The module that holds your WSGI application.'],
        ['key' => 'DJANGO_SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DJANGO_DEBUG', 'label' => 'Debug', 'default' => 'False'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt gunicorn
COPY . .
RUN python manage.py collectstatic --noinput || true
EXPOSE 8000
CMD ["sh", "-c", "python manage.py migrate --noinput && gunicorn ${DJANGO_WSGI:-config.wsgi} --bind 0.0.0.0:${PORT:-8000} --workers 3"]
DOCKERFILE,
    ],
];
