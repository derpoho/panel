<?php

return [
    'name' => 'Python',
    'category' => 'Apps',
    'description' => 'Any Python app on 3.12: installs requirements.txt, then runs your start command.',
    'icon' => 'python',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Listen on 0.0.0.0 and the PORT environment variable.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'START_COMMAND', 'label' => 'Start command', 'default' => 'python main.py'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PORT=8000
WORKDIR /app
COPY . .
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; \
    elif [ -f pyproject.toml ]; then pip install --no-cache-dir .; fi
EXPOSE 8000
CMD ["sh", "-c", "exec ${START_COMMAND:-python main.py}"]
DOCKERFILE,
    ],
];
