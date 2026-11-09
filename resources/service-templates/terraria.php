<?php

return [
    'name' => 'Terraria',
    'category' => 'Game servers',
    'description' => 'TShock-powered Terraria server that creates its world on first boot.',
    'icon' => 'terraria',
    'kind' => 'compose',
    'port' => 7777,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. Run /setup in-game with the code from the logs to become the owner.',
    'env' => [
        ['key' => 'WORLD_NAME', 'label' => 'World name', 'default' => 'world'],
        ['key' => 'WORLD_SIZE', 'label' => 'World size', 'default' => '2', 'hint' => '1 small, 2 medium, 3 large.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  terraria:
    image: ryshe/terraria:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    command: ["-world", "/root/.local/share/Terraria/Worlds/${WORLD_NAME}.wld", "-autocreate", "${WORLD_SIZE}"]
    ports:
      - "${PORT}:7777/tcp"
      - "${PORT}:7777/udp"
    volumes:
      - worlds:/root/.local/share/Terraria/Worlds
volumes:
  worlds:
YAML,
    ],
];
