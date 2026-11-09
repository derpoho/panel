<?php

return [
    'name' => 'Sonic',
    'category' => 'Infrastructure',
    'description' => 'Tiny, fast search backend that indexes text and hands back IDs.',
    'icon' => 'search',
    'kind' => 'compose',
    'port' => 1491,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect to {bridge}:PORT over the Sonic channel protocol, with the password from your settings. It only listens locally and on the Docker bridge.',
    'env' => [
        ['key' => 'PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sonic:
    image: valeriansaliou/sonic:v1.4.9
    restart: unless-stopped
    environment:
      SONIC_PASSWORD: ${PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:1491"
      - "${DOCKER_BRIDGE}:${PORT}:1491"
    volumes:
      - ./sonic.cfg:/etc/sonic.cfg:ro
      - data:/var/lib/sonic/store
volumes:
  data:
YAML,
        'sonic.cfg' => <<<'CFG'
[server]
log_level = "error"

[channel]
inet = "0.0.0.0:1491"
tcp_timeout = 300
auth_password = "${env.SONIC_PASSWORD}"

[channel.search]
query_limit_default = 10
query_limit_maximum = 100
query_alternates_try = 4
suggest_limit_default = 5
suggest_limit_maximum = 20
list_limit_default = 100
list_limit_maximum = 500

[store]

[store.kv]
path = "/var/lib/sonic/store/kv/"
retain_word_objects = 1000

[store.kv.pool]
inactive_after = 1800

[store.kv.database]
flush_after = 900
compress = true
parallelism = 2
max_files = 100
max_compactions = 1
max_flushes = 1
write_buffer = 16384
write_ahead_log = true

[store.fst]
path = "/var/lib/sonic/store/fst/"

[store.fst.pool]
inactive_after = 300

[store.fst.graph]
consolidate_after = 180
max_size = 2048
max_words = 250000
CFG,
    ],
];
