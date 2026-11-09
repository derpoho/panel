<?php

return [
    'name' => 'TeamSpeak',
    'category' => 'Game servers',
    'description' => 'TeamSpeak 3 voice server, from the official image TeamSpeak maintains.',
    'icon' => 'teamspeak',
    'kind' => 'compose',
    'port' => 9987,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP port 9987 for voice and TCP port 30033 for file transfers, open to the internet. Needs an x86 (amd64) machine. On first start the server admin privilege key (token=…) is printed in this service’s logs: use it once in the TeamSpeak client to become admin. The free licence covers one server with up to 32 slots.',
    'env' => [
        ['key' => 'TS3_LICENSE', 'label' => 'I accept the TeamSpeak server licence agreement', 'accept' => true, 'required' => true, 'hint' => 'Read it with: docker run --rm -e TS3SERVER_LICENSE=view teamspeak'],
        ['key' => 'SERVERADMIN_PASSWORD', 'label' => 'ServerQuery admin password', 'default' => '{random:24}', 'secret' => true, 'hint' => 'For the serveradmin account. ServerQuery isn’t opened to the internet.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  teamspeak:
    image: teamspeak:latest
    restart: unless-stopped
    environment:
      TS3SERVER_LICENSE: accept
      TS3SERVER_SERVERADMIN_PASSWORD: ${SERVERADMIN_PASSWORD}
    ports:
      - "9987:9987/udp"
      - "30033:30033/tcp"
    volumes:
      - data:/var/ts3server
volumes:
  data:
YAML,
    ],
];
