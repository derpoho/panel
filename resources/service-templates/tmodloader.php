<?php

return [
    'name' => 'tModLoader',
    'category' => 'Game servers',
    'description' => 'Modded Terraria server with tModLoader, mods downloaded from the Steam Workshop.',
    'icon' => 'terraria',
    'kind' => 'compose',
    'port' => 7777,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. Mods are Steam Workshop IDs: list them under download to fetch them, and under enabled to turn them on. Players need the same mods.',
    'env' => [
        ['key' => 'TMOD_WORLDNAME', 'label' => 'World name', 'default' => 'ServerOS'],
        ['key' => 'TMOD_WORLDSIZE', 'label' => 'World size', 'default' => '2', 'hint' => '1 small, 2 medium, 3 large. Only used for a new world.'],
        ['key' => 'TMOD_DIFFICULTY', 'label' => 'Difficulty', 'default' => '0', 'hint' => '0 normal, 1 expert, 2 master, 3 journey. Only used for a new world.'],
        ['key' => 'TMOD_PASS', 'label' => 'Join password', 'default' => '{random:10}', 'secret' => true, 'hint' => 'N/A for no password.'],
        ['key' => 'TMOD_MAXPLAYERS', 'label' => 'Max players', 'default' => '8'],
        ['key' => 'TMOD_AUTODOWNLOAD', 'label' => 'Mods to download', 'hint' => 'Workshop IDs, comma separated.'],
        ['key' => 'TMOD_ENABLEDMODS', 'label' => 'Mods to enable', 'hint' => 'Workshop IDs, comma separated.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tmodloader:
    image: jacobsmile/tmodloader1.4:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    stop_grace_period: 1m
    environment:
      TMOD_WORLDNAME: ${TMOD_WORLDNAME}
      TMOD_WORLDSIZE: ${TMOD_WORLDSIZE}
      TMOD_DIFFICULTY: ${TMOD_DIFFICULTY}
      TMOD_PASS: ${TMOD_PASS}
      TMOD_MAXPLAYERS: ${TMOD_MAXPLAYERS}
      TMOD_AUTODOWNLOAD: ${TMOD_AUTODOWNLOAD}
      TMOD_ENABLEDMODS: ${TMOD_ENABLEDMODS}
      TMOD_MOTD: Hosted with ServerOS
    ports:
      - "${PORT}:7777"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
