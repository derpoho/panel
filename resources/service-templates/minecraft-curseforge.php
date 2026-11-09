<?php

return [
    'name' => 'Minecraft: CurseForge modpack',
    'category' => 'Game servers',
    'description' => 'Runs any CurseForge modpack as a Java server, mods and loader installed for you.',
    'icon' => 'minecraft',
    'kind' => 'compose',
    'port' => 25565,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. The first start downloads the whole modpack, so give it a few minutes. Players need the same modpack installed.',
    'env' => [
        ['key' => 'EULA', 'label' => 'I accept the Minecraft EULA (minecraft.net/eula)', 'accept' => true, 'required' => true],
        ['key' => 'CF_API_KEY', 'label' => 'CurseForge API key', 'secret' => true, 'required' => true, 'hint' => 'From console.curseforge.com, under API keys. Keys often contain $: type each $ twice ($$).'],
        ['key' => 'CF_PAGE_URL', 'label' => 'Modpack page', 'required' => true, 'hint' => 'Like https://www.curseforge.com/minecraft/modpacks/all-the-mods-10'],
        ['key' => 'MEMORY', 'label' => 'Memory', 'default' => '6G'],
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
      TYPE: AUTO_CURSEFORGE
      CF_API_KEY: ${CF_API_KEY}
      CF_PAGE_URL: ${CF_PAGE_URL}
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
