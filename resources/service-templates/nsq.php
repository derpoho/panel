<?php

return [
    'name' => 'NSQ',
    'category' => 'Infrastructure',
    'description' => 'Simple distributed message queue, with nsqlookupd and the nsqadmin web UI.',
    'icon' => 'server',
    'kind' => 'compose',
    'port' => 4171,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'The main port is nsqadmin, which has no login. Producers publish to nsqd at {bridge} on the TCP port (or its HTTP port), from this machine and apps you deploy on it.',
    'env' => [
        ['key' => 'NSQD_TCP_PORT', 'label' => 'nsqd TCP port', 'default' => '4150'],
        ['key' => 'NSQD_HTTP_PORT', 'label' => 'nsqd HTTP port', 'default' => '4151'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nsqlookupd:
    image: nsqio/nsq:latest
    restart: unless-stopped
    command: /nsqlookupd
  nsqd:
    image: nsqio/nsq:latest
    restart: unless-stopped
    depends_on: [nsqlookupd]
    command: /nsqd --lookupd-tcp-address=nsqlookupd:4160 --data-path=/data --broadcast-address=${DOCKER_BRIDGE} --broadcast-tcp-port=${NSQD_TCP_PORT} --broadcast-http-port=${NSQD_HTTP_PORT}
    ports:
      - "127.0.0.1:${NSQD_TCP_PORT}:4150"
      - "${DOCKER_BRIDGE}:${NSQD_TCP_PORT}:4150"
      - "127.0.0.1:${NSQD_HTTP_PORT}:4151"
      - "${DOCKER_BRIDGE}:${NSQD_HTTP_PORT}:4151"
    volumes:
      - data:/data
  nsqadmin:
    image: nsqio/nsq:latest
    restart: unless-stopped
    depends_on: [nsqlookupd]
    command: /nsqadmin --lookupd-http-address=nsqlookupd:4161
    ports:
      - "127.0.0.1:${PORT}:4171"
volumes:
  data:
YAML,
    ],
];
