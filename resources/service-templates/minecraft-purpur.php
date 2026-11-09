<?php

return [
    'name' => 'Minecraft: Purpur',
    'category' => 'Game servers',
    'description' => 'Purpur server for Java players, a Paper fork with extra gameplay settings.',
    'icon' => 'minecraft',
    'kind' => 'compose',
    'port' => 25565,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. Plugins go in the plugins folder of the data volume.',
    'env' => [
        ['key' => 'EULA', 'label' => 'I accept the Minecraft EULA (minecraft.net/eula)', 'accept' => true, 'required' => true],
        ['key' => 'VERSION', 'label' => 'Version', 'default' => 'LATEST'],
        ['key' => 'MEMORY', 'label' => 'Memory', 'default' => '2G'],
        ['key' => 'MOTD', 'label' => 'Message of the day', 'default' => 'Hosted with ServerOS'],
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '20'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  minecraft:
    image: itzg/minecraft-server:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    environment:
      EULA: ${EULA}
      TYPE: PURPUR
      VERSION: ${VERSION}
      MEMORY: ${MEMORY}
      MOTD: ${MOTD}
      MAX_PLAYERS: ${MAX_PLAYERS}
    ports:
      - "${PORT}:25565"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
