<?php

return [
    'name' => 'Minecraft: Modrinth modpack',
    'category' => 'Game servers',
    'description' => 'Runs any Modrinth modpack as a Java server, mods and loader installed for you.',
    'icon' => 'minecraft',
    'kind' => 'compose',
    'port' => 25565,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. The first start downloads the whole modpack, so give it a few minutes. Players need the same modpack installed.',
    'env' => [
        ['key' => 'EULA', 'label' => 'I accept the Minecraft EULA (minecraft.net/eula)', 'accept' => true, 'required' => true],
        ['key' => 'MODRINTH_MODPACK', 'label' => 'Modpack', 'required' => true, 'hint' => 'The modpack’s slug or page address, like fabulously-optimized or https://modrinth.com/modpack/cobblemon-fabric'],
        ['key' => 'MEMORY', 'label' => 'Memory', 'default' => '4G'],
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
      TYPE: MODRINTH
      MODRINTH_MODPACK: ${MODRINTH_MODPACK}
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
