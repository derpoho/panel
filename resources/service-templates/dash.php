<?php

return [
    'name' => 'Plotly Dash',
    'category' => 'Apps',
    'description' => 'Python 3.12 serving your Dash app with Gunicorn.',
    'icon' => 'plotly',
    'kind' => 'app',
    'port' => 8050,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Expose the Flask server in your app file, like server = app.server.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DASH_SERVER', 'label' => 'Server', 'default' => 'app:server', 'hint' => 'module:variable pointing at app.server.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt dash gunicorn
COPY . .
EXPOSE 8050
CMD ["sh", "-c", "exec gunicorn ${DASH_SERVER:-app:server} --bind 0.0.0.0:${PORT:-8050} --workers 2"]
DOCKERFILE,
    ],
];
