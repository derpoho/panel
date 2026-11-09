<?php

return [
    'name' => 'discord.py',
    'category' => 'Apps',
    'description' => 'A Discord bot written with discord.py, or a fork like Pycord or nextcord, on Python 3.12: installs requirements.txt, then runs your start command.',
    'icon' => 'discord',
    'kind' => 'app',
    // Bots don't serve web pages: the deploy checks the bot keeps running
    // instead. The port is only there because every app has one.
    'port' => 8000,
    'web' => false,
    'port_editable' => false,
    'notes' => 'Your bot token is in the DISCORD_TOKEN environment variable, so log in with os.environ["DISCORD_TOKEN"]. FFmpeg is installed for voice. The deploy passes once the bot has stayed running for a few seconds.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DISCORD_TOKEN', 'label' => 'Bot token', 'hint' => 'From the Bot page of your app in the Discord Developer Portal.', 'secret' => true, 'required' => true],
        ['key' => 'START_COMMAND', 'label' => 'Start command', 'default' => 'python main.py', 'hint' => 'For example python bot.py.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg libopus0 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; \
    elif [ -f pyproject.toml ]; then pip install --no-cache-dir .; fi
CMD ["sh", "-c", "exec ${START_COMMAND:-python main.py}"]
DOCKERFILE,
    ],
];
