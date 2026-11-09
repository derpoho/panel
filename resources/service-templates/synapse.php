<?php

return [
    'name' => 'Matrix Synapse',
    'category' => 'Self-hosted',
    'description' => 'Your own Matrix homeserver for chat that federates with the rest of Matrix, with Postgres.',
    'icon' => 'matrix',
    'kind' => 'compose',
    'port' => 8008,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'The server name is taken from the public URL on first start and cannot change later, so give the service its domain first. Registration is off: make users from the service console with register_new_matrix_user -c /data/homeserver.yaml http://localhost:8008. Federation also needs port 8448 or a .well-known delegation on your domain.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  synapse:
    image: matrixdotorg/synapse:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      APP_URL: ${APP_URL}
      DB_PASSWORD: ${DB_PASSWORD}
      SYNAPSE_REPORT_STATS: "no"
    # On first boot, generate homeserver.yaml for the public URL's domain and point it at Postgres.
    entrypoint:
      - sh
      - -c
      - |
        if [ ! -f /data/homeserver.yaml ]; then
          host=$${APP_URL#*://}
          export SYNAPSE_SERVER_NAME=$${host%%/*}
          /start.py generate
          python - <<'PY'
        import os, yaml
        path = '/data/homeserver.yaml'
        config = yaml.safe_load(open(path))
        config['public_baseurl'] = os.environ['APP_URL'].rstrip('/') + '/'
        config['database'] = {
            'name': 'psycopg2',
            'args': {
                'user': 'synapse',
                'password': os.environ['DB_PASSWORD'],
                'database': 'synapse',
                'host': 'db',
                'cp_min': 5,
                'cp_max': 10,
            },
        }
        yaml.safe_dump(config, open(path, 'w'), sort_keys=False)
        PY
        fi
        exec /start.py
    ports:
      - "127.0.0.1:${PORT}:8008"
    volumes:
      - data:/data
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: synapse
      POSTGRES_USER: synapse
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_INITDB_ARGS: --encoding=UTF-8 --lc-collate=C --lc-ctype=C
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
