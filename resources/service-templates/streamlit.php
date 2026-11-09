<?php

return [
    'name' => 'Streamlit',
    'category' => 'Apps',
    'description' => 'Python 3.12 running your Streamlit app, app.py by default.',
    'icon' => 'streamlit',
    'kind' => 'app',
    'port' => 8501,
    'web' => true,
    'port_editable' => true,
    'health' => '/_stcore/health',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'STREAMLIT_APP', 'label' => 'App file', 'default' => 'app.py', 'hint' => 'The script you would pass to streamlit run.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 STREAMLIT_BROWSER_GATHER_USAGE_STATS=false
WORKDIR /app
COPY requirements*.txt ./
RUN pip install --no-cache-dir -r requirements.txt streamlit
COPY . .
EXPOSE 8501
CMD ["sh", "-c", "exec streamlit run ${STREAMLIT_APP:-app.py} --server.address 0.0.0.0 --server.port ${PORT:-8501} --server.headless true"]
DOCKERFILE,
    ],
];
