<?php

return [
    'name' => 'Rust (game server)',
    'category' => 'Game servers',
    'description' => 'Dedicated server for the survival game Rust, with optional Oxide mods.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 28015,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP port 28015 for players, TCP port 28016 for web RCON and TCP port 28082 for the Rust+ app, open to the internet. Needs an x86 (amd64) machine with about 12 GB of RAM and 20 GB of disk, and the first start downloads the game and builds the map, so give it a while.',
    'env' => [
        ['key' => 'RUST_SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS Rust'],
        ['key' => 'RUST_SERVER_DESCRIPTION', 'label' => 'Description', 'default' => 'Hosted with ServerOS'],
        ['key' => 'RUST_SERVER_MAXPLAYERS', 'label' => 'Max players', 'default' => '50'],
        ['key' => 'RUST_SERVER_WORLDSIZE', 'label' => 'Map size', 'default' => '3500', 'hint' => '1000 to 6000.'],
        ['key' => 'RUST_SERVER_SEED', 'label' => 'Map seed', 'default' => '12345'],
        ['key' => 'RUST_RCON_PASSWORD', 'label' => 'RCON password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'RUST_OXIDE_ENABLED', 'label' => 'Install Oxide (uMod)', 'default' => '0', 'hint' => '1 to install Oxide for plugins.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rust:
    image: didstopia/rust-server:latest
    restart: unless-stopped
    stop_grace_period: 2m
    environment:
      RUST_SERVER_NAME: ${RUST_SERVER_NAME}
      RUST_SERVER_DESCRIPTION: ${RUST_SERVER_DESCRIPTION}
      RUST_SERVER_MAXPLAYERS: ${RUST_SERVER_MAXPLAYERS}
      RUST_SERVER_WORLDSIZE: ${RUST_SERVER_WORLDSIZE}
      RUST_SERVER_SEED: ${RUST_SERVER_SEED}
      RUST_SERVER_IDENTITY: serveros
      RUST_RCON_PASSWORD: ${RUST_RCON_PASSWORD}
      RUST_OXIDE_ENABLED: ${RUST_OXIDE_ENABLED}
    ports:
      - "28015:28015/udp"
      - "28016:28016/tcp"
      - "28082:28082/tcp"
    volumes:
      - data:/steamcmd/rust
volumes:
  data:
YAML,
    ],
];
