<?php

return [
    'name' => 'Chainlit',
    'category' => 'Apps',
    'description' => 'Python 3.12 running your Chainlit chat app, app.py by default.',
    'icon' => 'bot',
    'kind' => 'app',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Add your model API keys as environment variables after it is created.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'CHAINLIT_APP', 'label' => 'App file', 'default' => 'app.py'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt chainlit
COPY . .
EXPOSE 8000
CMD ["sh", "-c", "exec chainlit run ${CHAINLIT_APP:-app.py} --host 0.0.0.0 --port ${PORT:-8000} --headless"]
DOCKERFILE,
    ],
];
