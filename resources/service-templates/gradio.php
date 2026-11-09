<?php

return [
    'name' => 'Gradio',
    'category' => 'Apps',
    'description' => 'Python 3.12 running your Gradio app, app.py by default.',
    'icon' => 'gradio',
    'kind' => 'app',
    'port' => 7860,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Call launch() without a fixed server_name or server_port so Gradio picks up the address and port set here.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'GRADIO_APP', 'label' => 'App file', 'default' => 'app.py'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 GRADIO_SERVER_NAME=0.0.0.0 GRADIO_ANALYTICS_ENABLED=False
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt gradio
COPY . .
EXPOSE 7860
CMD ["sh", "-c", "GRADIO_SERVER_PORT=${PORT:-7860} exec python ${GRADIO_APP:-app.py}"]
DOCKERFILE,
    ],
];
