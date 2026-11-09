<?php

return [
    'name' => 'Luanti',
    'category' => 'Game servers',
    'description' => 'Luanti server, the open source voxel game engine formerly called Minetest.',
    'icon' => 'luanti',
    'kind' => 'compose',
    'port' => 30000,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP port 30000, open to the internet. Only the devtest game comes built in: add a game like Mineclonia or Minetest Game to games/ in the data volume, set its id as the game, and restart. Players need the same Luanti version as the server.',
    'env' => [
        ['key' => 'GAME_ID', 'label' => 'Game', 'default' => 'devtest', 'hint' => 'The id of a game in the games folder, like mineclonia or minetest.'],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  luanti:
    image: lscr.io/linuxserver/luanti:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TZ}
      CLI_ARGS: "--gameid ${GAME_ID}"
    ports:
      - "30000:30000/udp"
    volumes:
      - data:/config/.minetest
volumes:
  data:
YAML,
    ],
];
