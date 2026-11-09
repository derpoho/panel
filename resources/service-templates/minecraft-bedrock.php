<?php

return [
    'name' => 'Minecraft: Bedrock Edition',
    'category' => 'Game servers',
    'description' => 'Bedrock server for console, mobile and Windows players.',
    'icon' => 'minecraft',
    'kind' => 'compose',
    'port' => 19132,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'UDP, open to the internet. Players add it with this machine’s IP and port.',
    'env' => [
        ['key' => 'EULA', 'label' => 'I accept the Minecraft EULA (minecraft.net/eula)', 'accept' => true, 'required' => true],
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS Bedrock'],
        ['key' => 'GAMEMODE', 'label' => 'Game mode', 'default' => 'survival'],
        ['key' => 'DIFFICULTY', 'label' => 'Difficulty', 'default' => 'normal'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  minecraft:
    image: itzg/minecraft-bedrock-server:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    environment:
      EULA: ${EULA}
      SERVER_NAME: ${SERVER_NAME}
      GAMEMODE: ${GAMEMODE}
      DIFFICULTY: ${DIFFICULTY}
    ports:
      - "${PORT}:19132/udp"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
