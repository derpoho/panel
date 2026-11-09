<?php

return [
    'name' => 'Arma Reforger',
    'category' => 'Game servers',
    'description' => 'Dedicated Arma Reforger server for PC and console players, updated on every start.',
    'icon' => 'bohemiainteractive',
    'kind' => 'compose',
    'port' => 2001,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 2001 and 17777, open to the internet. Needs an x86 (amd64) machine with about 4 GB of RAM, and the first start downloads the game, so give it a while. Configs live in the configs volume; mods can be set with GAME_MODS_IDS_LIST in the environment.',
    'env' => [
        ['key' => 'GAME_NAME', 'label' => 'Server name', 'default' => 'ServerOS Arma Reforger'],
        ['key' => 'SERVER_PUBLIC_ADDRESS', 'label' => 'Public IP', 'hint' => 'This machine’s public IP, so the server shows up in the server browser.'],
        ['key' => 'GAME_PASSWORD', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'GAME_PASSWORD_ADMIN', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'GAME_MAX_PLAYERS', 'label' => 'Max players', 'default' => '32'],
        ['key' => 'GAME_SCENARIO_ID', 'label' => 'Scenario', 'default' => '{ECC61978EDCC2B5A}Missions/23_Campaign.conf'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  reforger:
    image: ghcr.io/acemod/arma-reforger:latest
    restart: unless-stopped
    stop_grace_period: 1m
    environment:
      GAME_NAME: ${GAME_NAME}
      SERVER_PUBLIC_ADDRESS: ${SERVER_PUBLIC_ADDRESS}
      GAME_PASSWORD: ${GAME_PASSWORD}
      GAME_PASSWORD_ADMIN: ${GAME_PASSWORD_ADMIN}
      GAME_MAX_PLAYERS: ${GAME_MAX_PLAYERS}
      GAME_SCENARIO_ID: ${GAME_SCENARIO_ID}
      GAME_CROSS_PLATFORM: "true"
    ports:
      - "2001:2001/udp"
      - "17777:17777/udp"
    volumes:
      - configs:/reforger/Configs
      - profile:/home/profile
      - workshop:/reforger/workshop
volumes:
  configs:
  profile:
  workshop:
YAML,
    ],
];
