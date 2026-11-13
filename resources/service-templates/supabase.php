<?php

return [
    'name' => 'Supabase',
    'category' => 'Self-hosted',
    'description' => 'Postgres with auth, a REST API, realtime, storage, edge functions and the Studio dashboard.',
    'icon' => 'supabase',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/auth/v1/.well-known/jwks.json',
    'health_retries' => 40,
    'notes' => 'Open the address for Studio and sign in with the Studio user and password. Apps use the public URL with the anon key; the service role key is for your servers only. Postgres is at 127.0.0.1 on the Postgres port, user postgres.supabase, and apps on this machine reach it through {bridge}. Idle it uses about 350 MB of memory, more under load, and edge functions can use up to 2 GB. The first start pulls eleven images and sets up the database, so give it a few minutes.',
    'env' => [
        ['key' => 'SUPABASE_PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}', 'hint' => 'Where the API and Studio are reached. Your apps point their Supabase client here.'],
        ['key' => 'SITE_URL', 'label' => 'Site URL', 'default' => '{url}', 'hint' => 'Your app’s address. Sign-in emails send people back here.'],
        ['key' => 'ADDITIONAL_REDIRECT_URLS', 'label' => 'Extra redirect URLs', 'default' => '', 'hint' => 'Comma-separated, for sign-in redirects other than the site URL.'],
        ['key' => 'DASHBOARD_USERNAME', 'label' => 'Studio user', 'default' => 'supabase'],
        ['key' => 'DASHBOARD_PASSWORD', 'label' => 'Studio password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'POSTGRES_PASSWORD', 'label' => 'Database password', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:40}', 'secret' => true, 'hint' => 'At least 32 characters. The API keys are signed with it.'],
        ['key' => 'ANON_KEY', 'label' => 'Anon key', 'default' => '{jwt:anon:JWT_SECRET}', 'secret' => true, 'hint' => 'The public key your apps use. Made from the JWT secret if left blank.'],
        ['key' => 'SERVICE_ROLE_KEY', 'label' => 'Service role key', 'default' => '{jwt:service_role:JWT_SECRET}', 'secret' => true, 'hint' => 'Bypasses row level security. Keep it on your servers. Made from the JWT secret if left blank.'],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Realtime and pooler secret', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'VAULT_ENC_KEY', 'label' => 'Pooler encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'PG_META_CRYPTO_KEY', 'label' => 'Studio encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'S3_PROTOCOL_ACCESS_KEY_ID', 'label' => 'Storage S3 key ID', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'S3_PROTOCOL_ACCESS_KEY_SECRET', 'label' => 'Storage S3 secret', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'DB_PORT', 'label' => 'Postgres port', 'default' => '54322', 'hint' => 'Session connections through the pooler, on this machine only. Pick another if this machine already runs a Supabase.'],
        ['key' => 'POOLER_PORT', 'label' => 'Transaction pooler port', 'default' => '54329', 'hint' => 'Pick another if this machine already runs a Supabase.'],
        ['key' => 'DISABLE_SIGNUP', 'label' => 'Turn off sign-ups', 'default' => 'false', 'hint' => 'true or false.'],
        ['key' => 'ENABLE_EMAIL_AUTOCONFIRM', 'label' => 'Skip email confirmation', 'default' => 'true', 'hint' => 'true until you set up SMTP below, so sign-ups work without email.'],
        ['key' => 'SMTP_HOST', 'label' => 'SMTP host', 'default' => ''],
        ['key' => 'SMTP_PORT', 'label' => 'SMTP port', 'default' => '587'],
        ['key' => 'SMTP_USER', 'label' => 'SMTP user', 'default' => ''],
        ['key' => 'SMTP_PASS', 'label' => 'SMTP password', 'default' => '', 'secret' => true],
        ['key' => 'SMTP_ADMIN_EMAIL', 'label' => 'Sender email', 'default' => 'admin@example.com'],
        ['key' => 'SMTP_SENDER_NAME', 'label' => 'Sender name', 'default' => 'Supabase'],
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI key for Studio', 'default' => '', 'secret' => true, 'hint' => 'Optional. Turns on the SQL assistant in Studio.'],
    ],
    'files' => [
        'compose.yaml' => <<<'SB_COMPOSE_YAML'
services:
  # The daemon clears the deploy's own folder once it's done, so copy the files into
  # volumes the stack keeps. Uses the database image, so there's nothing extra to pull.
  config:
    image: supabase/postgres:17.6.1.136
    restart: "no"
    entrypoint: ["/bin/sh", "-c", "cp -a /from/api /from/db /from/pooler /config/ && cp -a /from/functions/. /functions/"]
    volumes:
      - ./volumes:/from:ro
      - config:/config
      - functions:/functions

  studio:
    image: supabase/studio:2026.11.02-sha-a05e7ec
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "node -e \"fetch('http://localhost:3000/api/platform/profile').then((r) => {if (r.status !== 200) throw new Error(r.status)})\""]
      timeout: 10s
      interval: 5s
      retries: 3
      start_period: 20s
    environment:
      HOSTNAME: "0.0.0.0"
      STUDIO_PG_META_URL: http://meta:8080
      POSTGRES_PORT: 5432
      POSTGRES_HOST: db
      POSTGRES_DB: postgres
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_USER_READ_WRITE: postgres
      PG_META_CRYPTO_KEY: ${PG_META_CRYPTO_KEY}
      PGRST_DB_SCHEMAS: public,graphql_public
      PGRST_DB_MAX_ROWS: 1000
      PGRST_DB_EXTRA_SEARCH_PATH: public
      DEFAULT_ORGANIZATION_NAME: Default Organization
      DEFAULT_PROJECT_NAME: Default Project
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      SUPABASE_URL: http://api-gw:8000
      SUPABASE_PUBLIC_URL: ${SUPABASE_PUBLIC_URL}
      SUPABASE_ANON_KEY: ${ANON_KEY}
      SUPABASE_SERVICE_KEY: ${SERVICE_ROLE_KEY}
      AUTH_JWT_SECRET: ${JWT_SECRET}
      ENABLED_FEATURES_LOGS_ALL: "false"
      SNIPPETS_MANAGEMENT_FOLDER: /app/snippets
      EDGE_FUNCTIONS_MANAGEMENT_FOLDER: /app/edge-functions
    volumes:
      - snippets:/app/snippets
      - functions:/app/edge-functions:ro

  api-gw:
    image: kong/kong:3.9.3
    restart: unless-stopped
    networks:
      default:
        aliases: [kong, envoy]
    healthcheck:
      test: ["CMD", "kong", "health"]
      interval: 5s
      timeout: 5s
      retries: 5
    depends_on:
      config:
        condition: service_completed_successfully
      studio:
        condition: service_healthy
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - config:/config:ro
    environment:
      KONG_DATABASE: "off"
      KONG_DECLARATIVE_CONFIG: /usr/local/kong/kong.yml
      KONG_ROUTER_FLAVOR: expressions
      KONG_DNS_ORDER: LAST,A,CNAME
      KONG_DNS_NOT_FOUND_TTL: 1
      KONG_DNS_VALID_TTL: 5
      KONG_PLUGINS: request-transformer,cors,key-auth,acl,basic-auth,request-termination,ip-restriction,post-function
      KONG_NGINX_PROXY_PROXY_BUFFER_SIZE: 160k
      KONG_NGINX_PROXY_PROXY_BUFFERS: 64 160k
      KONG_PROXY_ACCESS_LOG: /dev/stdout combined
      SUPABASE_ANON_KEY: ${ANON_KEY}
      SUPABASE_SERVICE_KEY: ${SERVICE_ROLE_KEY}
      DASHBOARD_USERNAME: ${DASHBOARD_USERNAME}
      DASHBOARD_PASSWORD: ${DASHBOARD_PASSWORD}
    entrypoint: ["/bin/sh", "/config/api/kong-entrypoint.sh"]

  auth:
    image: supabase/gotrue:v2.197.0
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost:9999/health"]
      timeout: 5s
      interval: 5s
      retries: 3
    depends_on:
      db:
        condition: service_healthy
    environment:
      GOTRUE_API_HOST: 0.0.0.0
      GOTRUE_API_PORT: 9999
      API_EXTERNAL_URL: ${SUPABASE_PUBLIC_URL}/auth/v1
      GOTRUE_DB_DRIVER: postgres
      GOTRUE_DB_DATABASE_URL: postgres://supabase_auth_admin:${POSTGRES_PASSWORD}@db:5432/postgres
      GOTRUE_SITE_URL: ${SITE_URL}
      GOTRUE_URI_ALLOW_LIST: ${ADDITIONAL_REDIRECT_URLS}
      GOTRUE_DISABLE_SIGNUP: ${DISABLE_SIGNUP}
      GOTRUE_JWT_ADMIN_ROLES: service_role
      GOTRUE_JWT_AUD: authenticated
      GOTRUE_JWT_DEFAULT_GROUP_NAME: authenticated
      GOTRUE_JWT_EXP: 3600
      GOTRUE_JWT_SECRET: ${JWT_SECRET}
      GOTRUE_JWT_ISSUER: ${SUPABASE_PUBLIC_URL}/auth/v1
      GOTRUE_EXTERNAL_EMAIL_ENABLED: "true"
      GOTRUE_EXTERNAL_ANONYMOUS_USERS_ENABLED: "false"
      GOTRUE_MAILER_AUTOCONFIRM: ${ENABLE_EMAIL_AUTOCONFIRM}
      GOTRUE_SMTP_ADMIN_EMAIL: ${SMTP_ADMIN_EMAIL}
      GOTRUE_SMTP_HOST: ${SMTP_HOST}
      GOTRUE_SMTP_PORT: ${SMTP_PORT}
      GOTRUE_SMTP_USER: ${SMTP_USER}
      GOTRUE_SMTP_PASS: ${SMTP_PASS}
      GOTRUE_SMTP_SENDER_NAME: ${SMTP_SENDER_NAME}
      GOTRUE_MAILER_URLPATHS_INVITE: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_CONFIRMATION: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_RECOVERY: /auth/v1/verify
      GOTRUE_MAILER_URLPATHS_EMAIL_CHANGE: /auth/v1/verify
      GOTRUE_EXTERNAL_PHONE_ENABLED: "false"
      GOTRUE_SMS_AUTOCONFIRM: "false"

  rest:
    image: postgrest/postgrest:v14.18
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "postgrest", "--ready"]
      interval: 5s
      timeout: 5s
      retries: 3
    environment:
      PGRST_DB_URI: postgres://authenticator:${POSTGRES_PASSWORD}@db:5432/postgres
      PGRST_DB_SCHEMAS: public,graphql_public
      PGRST_DB_MAX_ROWS: 1000
      PGRST_DB_EXTRA_SEARCH_PATH: public
      PGRST_DB_ANON_ROLE: anon
      PGRST_ADMIN_SERVER_PORT: 3001
      PGRST_ADMIN_SERVER_HOST: localhost
      PGRST_JWT_SECRET: ${JWT_SECRET}
      PGRST_DB_USE_LEGACY_GUCS: "false"
      PGRST_APP_SETTINGS_JWT_EXP: 3600
    command: ["postgrest"]

  realtime:
    image: supabase/realtime:v2.148.3
    restart: unless-stopped
    networks:
      default:
        # Realtime reads its tenant from this host name, so the gateway calls it by it.
        aliases: [realtime-dev.supabase-realtime]
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "curl -sSfL --head -o /dev/null -H \"Authorization: Bearer ${ANON_KEY}\" http://localhost:4000/api/tenants/realtime-dev/health"]
      timeout: 5s
      interval: 30s
      retries: 3
      start_period: 10s
    environment:
      PORT: 4000
      DB_HOST: db
      DB_PORT: 5432
      DB_USER: supabase_admin
      DB_PASSWORD: ${POSTGRES_PASSWORD}
      DB_NAME: postgres
      DB_AFTER_CONNECT_QUERY: 'SET search_path TO _realtime'
      DB_ENC_KEY: supabaserealtime
      API_JWT_SECRET: ${JWT_SECRET}
      SECRET_KEY_BASE: ${SECRET_KEY_BASE}
      METRICS_JWT_SECRET: ${JWT_SECRET}
      ERL_AFLAGS: -proto_dist inet_tcp
      DNS_NODES: "''"
      RLIMIT_NOFILE: "10000"
      APP_NAME: realtime
      SEED_SELF_HOST: "true"
      RUN_JANITOR: "true"
      DISABLE_HEALTHCHECK_LOGGING: "true"

  storage:
    image: supabase/storage-api:v1.89.12
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
      rest:
        condition: service_started
      imgproxy:
        condition: service_started
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://storage:5000/status"]
      timeout: 5s
      interval: 5s
      retries: 3
      start_period: 10s
    environment:
      ANON_KEY: ${ANON_KEY}
      SERVICE_KEY: ${SERVICE_ROLE_KEY}
      POSTGREST_URL: http://rest:3000
      AUTH_JWT_SECRET: ${JWT_SECRET}
      DATABASE_URL: postgres://supabase_storage_admin:${POSTGRES_PASSWORD}@db:5432/postgres
      STORAGE_PUBLIC_URL: ${SUPABASE_PUBLIC_URL}
      REQUEST_ALLOW_X_FORWARDED_PATH: "true"
      FILE_SIZE_LIMIT: 52428800
      STORAGE_BACKEND: file
      GLOBAL_S3_BUCKET: stub
      FILE_STORAGE_BACKEND_PATH: /var/lib/storage
      TENANT_ID: stub
      REGION: stub
      ENABLE_IMAGE_TRANSFORMATION: "true"
      IMGPROXY_URL: http://imgproxy:5001
      S3_PROTOCOL_ACCESS_KEY_ID: ${S3_PROTOCOL_ACCESS_KEY_ID}
      S3_PROTOCOL_ACCESS_KEY_SECRET: ${S3_PROTOCOL_ACCESS_KEY_SECRET}
    volumes:
      - storage:/var/lib/storage

  imgproxy:
    image: darthsim/imgproxy:v4.0.21
    restart: unless-stopped
    volumes:
      - storage:/var/lib/storage
    healthcheck:
      test: ["CMD", "imgproxy", "health"]
      timeout: 5s
      interval: 5s
      retries: 3
    environment:
      IMGPROXY_BIND: ":5001"
      IMGPROXY_LOCAL_FILESYSTEM_ROOT: /
      IMGPROXY_USE_ETAG: "true"
      IMGPROXY_AUTO_WEBP: "true"
      IMGPROXY_MAX_SRC_RESOLUTION: 16.8

  meta:
    image: supabase/postgres-meta:v0.100.0
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      PG_META_PORT: 8080
      PG_META_DB_HOST: db
      PG_META_DB_PORT: 5432
      PG_META_DB_NAME: postgres
      PG_META_DB_USER: postgres
      PG_META_DB_PASSWORD: ${POSTGRES_PASSWORD}
      CRYPTO_KEY: ${PG_META_CRYPTO_KEY}

  functions:
    image: supabase/edge-runtime:v1.77.9
    mem_limit: 2g
    restart: unless-stopped
    volumes:
      - functions:/home/deno/functions
      - deno-cache:/root/.cache/deno
    depends_on:
      api-gw:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "timeout 1 bash -c '</dev/tcp/127.0.0.1/9000'"]
      interval: 5s
      timeout: 5s
      retries: 3
    environment:
      JWT_SECRET: ${JWT_SECRET}
      SUPABASE_URL: http://api-gw:8000
      SUPABASE_PUBLIC_URL: ${SUPABASE_PUBLIC_URL}
      SUPABASE_ANON_KEY: ${ANON_KEY}
      SUPABASE_SERVICE_ROLE_KEY: ${SERVICE_ROLE_KEY}
      SUPABASE_DB_URL: postgresql://postgres:${POSTGRES_PASSWORD}@db:5432/postgres
      VERIFY_JWT: "false"
    command: ["start", "--user-worker-request-idle-timeout", "150000", "--main-service", "/home/deno/functions/main"]

  db:
    image: supabase/postgres:17.6.1.136
    restart: unless-stopped
    depends_on:
      config:
        condition: service_completed_successfully
    # Put the setup scripts where the image runs them on first start, then start it as usual.
    entrypoint:
      - /bin/sh
      - -c
      - >-
        cd /docker-entrypoint-initdb.d
        && cp /config/db/realtime.sql migrations/99-realtime.sql
        && cp /config/db/webhooks.sql init-scripts/98-webhooks.sql
        && cp /config/db/roles.sql init-scripts/99-roles.sql
        && cp /config/db/jwt.sql init-scripts/99-jwt.sql
        && cp /config/db/_supabase.sql migrations/97-_supabase.sql
        && cp /config/db/logs.sql migrations/99-logs.sql
        && cp /config/db/pooler.sql migrations/99-pooler.sql
        && cd / && exec docker-entrypoint.sh "$$@"
      - sh
    volumes:
      - config:/config:ro
      - db-data:/var/lib/postgresql/data
      - db-config:/etc/postgresql-custom
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "postgres", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
    environment:
      POSTGRES_HOST: /var/run/postgresql
      PGPORT: 5432
      POSTGRES_PORT: 5432
      PGPASSWORD: ${POSTGRES_PASSWORD}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      PGDATABASE: postgres
      POSTGRES_DB: postgres
      JWT_EXP: 3600
    command: ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf", "-c", "log_min_messages=fatal"]

  supavisor:
    image: supabase/supavisor:2.9.13
    restart: unless-stopped
    ports:
      - "127.0.0.1:${DB_PORT}:5432"
      - "${DOCKER_BRIDGE}:${DB_PORT}:5432"
      - "127.0.0.1:${POOLER_PORT}:6543"
      - "${DOCKER_BRIDGE}:${POOLER_PORT}:6543"
    volumes:
      - config:/config:ro
    healthcheck:
      test: ["CMD", "curl", "-sSfL", "--head", "-o", "/dev/null", "http://127.0.0.1:4000/api/health"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s
    depends_on:
      db:
        condition: service_healthy
    environment:
      PORT: 4000
      POSTGRES_PORT: 5432
      POSTGRES_HOST: db
      POSTGRES_DB: postgres
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
      DATABASE_URL: ecto://supabase_admin:${POSTGRES_PASSWORD}@db:5432/_supabase
      CLUSTER_POSTGRES: "true"
      SECRET_KEY_BASE: ${SECRET_KEY_BASE}
      VAULT_ENC_KEY: ${VAULT_ENC_KEY}
      API_JWT_SECRET: ${JWT_SECRET}
      METRICS_JWT_SECRET: ${JWT_SECRET}
      REGION: local
      ERL_AFLAGS: -proto_dist inet_tcp
      POOLER_TENANT_ID: supabase
      POOLER_DEFAULT_POOL_SIZE: 20
      POOLER_MAX_CLIENT_CONN: 100
      POOLER_POOL_MODE: transaction
      DB_POOL_SIZE: 5
    command: ["/bin/sh", "-c", "/app/bin/migrate && /app/bin/supavisor eval \"$$(cat /config/pooler/pooler.exs)\" && /app/bin/server"]

volumes:
  config:
  functions:
  db-data:
  db-config:
  storage:
  snippets:
  deno-cache:
SB_COMPOSE_YAML,
        'volumes/api/kong.yml' => <<<'SB_VOLUMES_API_KONG_YML'
_format_version: '2.1'
_transform: true

###
### Consumers / Users
###
consumers:
  - username: DASHBOARD
  - username: anon
    keyauth_credentials:
      - key: $SUPABASE_ANON_KEY
      - key: $SUPABASE_PUBLISHABLE_KEY
  - username: service_role
    keyauth_credentials:
      - key: $SUPABASE_SERVICE_KEY
      - key: $SUPABASE_SECRET_KEY

###
### Access Control List
###
acls:
  - consumer: anon
    group: anon
  - consumer: service_role
    group: admin

###
### Dashboard credentials
###
basicauth_credentials:
  - consumer: DASHBOARD
    username: '$DASHBOARD_USERNAME'
    password: '$DASHBOARD_PASSWORD'

###
### API Routes
###
services:
  ## Open Auth routes
  - name: auth-v1-open
    _comment: 'Auth: /auth/v1/verify* -> http://auth:9999/verify*'
    url: http://auth:9999/verify
    routes:
      - name: auth-v1-open
        strip_path: true
        paths:
          - /auth/v1/verify
    plugins:
      - name: cors
  - name: auth-v1-open-callback
    _comment: 'Auth: /auth/v1/callback* -> http://auth:9999/callback*'
    url: http://auth:9999/callback
    routes:
      - name: auth-v1-open-callback
        strip_path: true
        paths:
          - /auth/v1/callback
    plugins:
      - name: cors
  - name: auth-v1-open-authorize
    _comment: 'Auth: /auth/v1/authorize* -> http://auth:9999/authorize*'
    url: http://auth:9999/authorize
    routes:
      - name: auth-v1-open-authorize
        strip_path: true
        paths:
          - /auth/v1/authorize
    plugins:
      - name: cors
  - name: auth-v1-open-jwks
    _comment: 'Auth: /auth/v1/.well-known/jwks.json -> http://auth:9999/.well-known/jwks.json'
    url: http://auth:9999/.well-known/jwks.json
    routes:
      - name: auth-v1-open-jwks
        strip_path: true
        paths:
          - /auth/v1/.well-known/jwks.json
    plugins:
      - name: cors
  - name: auth-v1-open-well-known-oauth
    _comment: 'Auth: /auth/v1/.well-known/oauth-authorization-server -> http://auth:9999/.well-known/oauth-authorization-server'
    url: http://auth:9999/.well-known/oauth-authorization-server
    routes:
      - name: auth-v1-open-well-known-oauth
        strip_path: true
        paths:
          - /auth/v1/.well-known/oauth-authorization-server
    plugins:
      - name: cors
  - name: auth-v1-open-well-known-openid
    _comment: 'Auth: /auth/v1/.well-known/openid-configuration -> http://auth:9999/.well-known/openid-configuration'
    url: http://auth:9999/.well-known/openid-configuration
    routes:
      - name: auth-v1-open-well-known-openid
        strip_path: true
        paths:
          - /auth/v1/.well-known/openid-configuration
    plugins:
      - name: cors

  - name: auth-v1-open-sso-acs
    url: "http://auth:9999/sso/saml/acs"
    routes:
      - name: auth-v1-open-sso-acs
        strip_path: true
        paths:
        - /auth/v1/sso/saml/acs
    plugins:
      - name: cors

  - name: auth-v1-open-sso-metadata
    url: "http://auth:9999/sso/saml/metadata"
    routes:
      - name: auth-v1-open-sso-metadata
        strip_path: true
        paths:
        - /auth/v1/sso/saml/metadata
    plugins:
      - name: cors

  ## Secure Auth routes
  - name: auth-v1
    _comment: 'Auth: /auth/v1/* -> http://auth:9999/*'
    url: http://auth:9999/
    routes:
      - name: auth-v1-all
        strip_path: true
        paths:
          - /auth/v1/
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin
            - anon

  ## OpenAPI root - admin only
  - name: rest-v1-openapi
    _comment: 'PostgREST OpenAPI root: /rest/v1/ -> <http://rest:3000/> (admin only). See <https://github.com/orgs/supabase/discussions/42949>'
    url: http://rest:3000/
    routes:
      - name: rest-v1-openapi-root
        strip_path: true
        expression: 'http.path == "/rest/v1/"'
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin

  ## Secure PostgREST routes
  - name: rest-v1
    _comment: 'PostgREST: /rest/v1/* -> http://rest:3000/*'
    url: http://rest:3000/
    routes:
      - name: rest-v1-all
        strip_path: true
        paths:
          - /rest/v1/
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin
            - anon

  ## Secure GraphQL routes
  - name: graphql-v1
    _comment: 'PostgREST: /graphql/v1/* -> http://rest:3000/rpc/graphql'
    url: http://rest:3000/rpc/graphql
    routes:
      - name: graphql-v1-all
        strip_path: true
        paths:
          - /graphql/v1
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "Content-Profile: graphql_public"
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin
            - anon

  ## Secure Realtime routes
  - name: realtime-v1-ws
    _comment: 'Realtime: /realtime/v1/* -> ws://realtime:4000/socket/*'
    url: http://realtime-dev.supabase-realtime:4000/socket
    protocol: ws
    routes:
      - name: realtime-v1-ws
        strip_path: true
        paths:
          - /realtime/v1/
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "x-api-key:$LUA_RT_WS_EXPR"
          replace:
            querystring:
              - "apikey:$LUA_RT_WS_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin
            - anon

  # Block access to /realtime/v1/api/openapi
  - name: realtime-v1-rest-openapi
    _comment: 'Realtime: /realtime/v1/api/openapi/* -> http://realtime:4000/api/openapi/* (blocked)'
    url: http://realtime-dev.supabase-realtime:4000/api/openapi
    protocol: http
    routes:
      - name: realtime-v1-rest-openapi
        strip_path: true
        paths:
          - /realtime/v1/api/openapi
    plugins:
      - name: request-termination
        config:
          status_code: 403
          message: "Access is forbidden."

  # Block access to /realtime/v1/api/tenants
  - name: realtime-v1-rest-tenants
    _comment: 'Realtime: /realtime/v1/api/tenants/* -> http://realtime:4000/api/tenants/* (blocked)'
    url: http://realtime-dev.supabase-realtime:4000/api/tenants
    protocol: http
    routes:
      - name: realtime-v1-rest-tenants
        strip_path: true
        paths:
          - /realtime/v1/api/tenants
    plugins:
      - name: request-termination
        config:
          status_code: 403
          message: "Access is forbidden."

  - name: realtime-v1-rest
    _comment: 'Realtime: /realtime/v1/api/* -> http://realtime:4000/api/*'
    url: http://realtime-dev.supabase-realtime:4000/api
    protocol: http
    routes:
      - name: realtime-v1-rest
        strip_path: true
        paths:
          - /realtime/v1/api
    plugins:
      - name: cors
      - name: key-auth
        config:
          hide_credentials: false
      - name: request-transformer
        config:
          add:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin
            - anon

  ## Storage API endpoint (with Authorization header transformation).
  ## No key-auth - S3 protocol requests don't carry an apikey header.
  ##
  ## The request-transformer translates opaque API keys to asymmetric JWTs
  ## and passes through existing Authorization headers (user JWTs, AWS SigV4).
  ## When no Authorization or apikey header is present (S3 presigned URLs),
  ## the Lua expression evaluates to nil which Kong renders as empty string.
  ## The post-function strips this empty header so Storage's S3 signature
  ## verification falls through to query-parameter parsing.
  - name: storage-v1
    _comment: 'Storage: /storage/v1/* -> http://storage:5000/*'
    url: http://storage:5000/
    routes:
      - name: storage-v1-all
        strip_path: true
        paths:
          - /storage/v1/
    plugins:
      - name: cors
      - name: request-transformer
        config:
          add:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
          replace:
            headers:
              - "Authorization: $LUA_AUTH_EXPR"
      - name: post-function
        config:
          access:
            - |
              local auth = kong.request.get_header("authorization")
              if auth == nil or auth == "" or auth:find("^%s*$") then
                kong.service.request.clear_header("authorization")
              end

  ## Edge Functions routes
  ##
  ## Functions header handling: opaque sb_ keys are translated to the pre-signed
  ## internal asymmetric JWT and injected as a raw `sb-api-key` header (no
  ## `Bearer` prefix), while `Authorization` is left untouched so user-session
  ## JWTs and legacy bearers flow through to the runtime.
  ##
  ## No `key-auth`: Functions is a passthrough that does NOT validate keys. Kong
  ## cannot reject an invalid key while still letting unauthenticated requests
  ## through (key-auth's `anonymous` fallback accepts missing AND invalid keys
  ## alike), so unknown/invalid keys simply pass to the runtime, which handles
  ## verify_jwt itself.
  ##
  ## The request-transformer strips any client-supplied `sb-api-key` (anti-spoof)
  ## and re-adds the translated value; on no match the Lua expression yields nil
  ## (an empty header) which the post-function then removes.
  ##
  ## No `cors`: functions handle CORS themselves, as on the platform. Preflights
  ## reach the function and its responses pass through unchanged.
  - name: functions-v1
    _comment: 'Edge Functions: /functions/v1/* -> http://functions:9000/*'
    url: http://functions:9000/
    # Limit inactivity between reads, leaving 10s for the runtime's 150s idle timeout.
    read_timeout: 160000
    routes:
      - name: functions-v1-all
        strip_path: true
        paths:
          - /functions/v1/
    plugins:
      - name: request-transformer
        config:
          remove:
            headers:
              - "sb-api-key"
          add:
            headers:
              - "sb-api-key:$LUA_FUNCTIONS_EXPR"
      - name: post-function
        config:
          access:
            - |
              local v = kong.request.get_header("sb-api-key")
              if v == nil or v == "" or v:find("^%s*$") then
                kong.service.request.clear_header("sb-api-key")
              end

  ## OAuth 2.0 Authorization Server Metadata (RFC 8414)
  - name: well-known-oauth
    _comment: 'Auth: /.well-known/oauth-authorization-server/auth/v1 -> http://auth:9999/.well-known/oauth-authorization-server'
    url: http://auth:9999/.well-known/oauth-authorization-server
    routes:
      - name: well-known-oauth
        strip_path: true
        paths:
          - /.well-known/oauth-authorization-server/auth/v1
    plugins:
      - name: cors

  ## Analytics routes
  ## Not used - Studio and Vector talk directly to analytics via Docker networking.
  ## If external access is needed, add routes with key-auth matching Logflare's x-api-key auth.
  # - name: analytics-v1-api
  #   _comment: 'Analytics: /analytics/v1/api/endpoints/* -> http://logflare:4000/api/endpoints/*'
  #   url: http://analytics:4000/api/endpoints
  #   routes:
  #     - name: analytics-v1-api
  #       strip_path: true
  #       paths:
  #         - /analytics/v1/api/endpoints/
  # - name: analytics-v1
  #   _comment: 'Analytics: /analytics/v1/* -> http://logflare:4000/*'
  #   url: http://analytics:4000/
  #   routes:
  #     - name: dashboard-v1-all
  #       strip_path: true
  #       paths:
  #         - /analytics/v1
  #   plugins:
  #     - name: cors
  #     - name: basic-auth
  #       config:
  #         hide_credentials: true

  ## Secure Database routes
  - name: meta
    _comment: 'pg-meta: /pg/* -> http://pg-meta:8080/*'
    url: http://meta:8080/
    routes:
      - name: meta-all
        strip_path: true
        paths:
          - /pg/
    plugins:
      - name: key-auth
        config:
          hide_credentials: false
      - name: acl
        config:
          hide_groups_header: true
          allow:
            - admin

  ## Block access to /api/mcp
  - name: mcp-blocker
    _comment: 'Block direct access to /api/mcp'
    url: http://studio:3000/api/mcp
    routes:
      - name: mcp-blocker-route
        strip_path: true
        paths:
          - /api/mcp
    plugins:
      - name: request-termination
        config:
          status_code: 403
          message: "Access is forbidden."

  ## MCP endpoint - local access
  - name: mcp
    _comment: 'MCP: /mcp -> http://studio:3000/api/mcp (local access)'
    url: http://studio:3000/api/mcp
    routes:
      - name: mcp
        strip_path: true
        paths:
          - /mcp
    plugins:
      # Block access to /mcp by default
      - name: request-termination
        config:
          status_code: 403
          message: "Access is forbidden."
      # Enable local access (danger zone!)
      # 1. Comment out the 'request-termination' section above
      # 2. Uncomment the entire section below, including 'deny'
      # 3. Add your local IPs to the 'allow' list
      #- name: cors
      #- name: ip-restriction
      #  config:
      #    allow:
      #      - 127.0.0.1
      #      - ::1
      #    deny: []

  ## Protected Dashboard - catch all remaining routes
  - name: dashboard
    _comment: 'Studio: /* -> http://studio:3000/*'
    url: http://studio:3000/
    routes:
      - name: dashboard-all
        strip_path: true
        paths:
          - /
    plugins:
      - name: cors
      - name: basic-auth
        config:
          hide_credentials: true
SB_VOLUMES_API_KONG_YML,
        'volumes/api/kong-entrypoint.sh' => <<<'SB_VOLUMES_API_KONG_ENTRYPOINT_SH'
#!/bin/sh
# Custom entrypoint for Kong that builds Lua expressions for request-transformer
# and performs environment variable substitution in the declarative config.

# Build Lua expressions for translating opaque API keys to asymmetric JWTs.
# When opaque keys are not configured (empty env vars), expressions fall through
# to legacy-only behavior - just passing apikey as-is.
#
# Full expression logic (when opaque keys are configured):
#   1. If Authorization header exists and is NOT an sb_ key -> pass through (user session JWT)
#   2. If apikey matches secret key -> set service_role asymmetric JWT internal "API key"
#   3. If apikey matches publishable key -> set anon asymmetric JWT internal "API key"
#   4. Fallback: pass apikey as-is (legacy HS256 JWT)

if [ -n "$SUPABASE_SECRET_KEY" ] && [ -n "$SUPABASE_PUBLISHABLE_KEY" ]; then
    # Opaque keys configured -> full translation expressions
    export LUA_AUTH_EXPR="\$((headers.authorization ~= nil and headers.authorization:sub(1, 10) ~= 'Bearer sb_' and headers.authorization) or (headers.apikey == '$SUPABASE_SECRET_KEY' and 'Bearer $SERVICE_ROLE_KEY_ASYMMETRIC') or (headers.apikey == '$SUPABASE_PUBLISHABLE_KEY' and 'Bearer $ANON_KEY_ASYMMETRIC') or headers.apikey)"

    # Realtime WebSocket: reads from query_params.apikey (supabase-js sends apikey
    # via query string), outputs to x-api-key header which Realtime checks first.
    export LUA_RT_WS_EXPR="\$((query_params.apikey == '$SUPABASE_SECRET_KEY' and '$SERVICE_ROLE_KEY_ASYMMETRIC') or (query_params.apikey == '$SUPABASE_PUBLISHABLE_KEY' and '$ANON_KEY_ASYMMETRIC') or query_params.apikey)"

    # Functions: translate opaque sb_ keys to the pre-signed internal asymmetric
    # JWT and emit it as a raw `sb-api-key` header (no Bearer prefix), leaving
    # Authorization untouched. The key is read from the apikey header or an
    # Authorization `Bearer sb_...` fallback (header only, matching platform
    # behavior. On no match the expression yields nil (rendered as an empty
    # header) which the route's post-function then strips.
    export LUA_FUNCTIONS_EXPR="\$((headers.apikey == '$SUPABASE_SECRET_KEY' and '$SERVICE_ROLE_KEY_ASYMMETRIC') or (headers.apikey == '$SUPABASE_PUBLISHABLE_KEY' and '$ANON_KEY_ASYMMETRIC') or (headers.authorization == 'Bearer $SUPABASE_SECRET_KEY' and '$SERVICE_ROLE_KEY_ASYMMETRIC') or (headers.authorization == 'Bearer $SUPABASE_PUBLISHABLE_KEY' and '$ANON_KEY_ASYMMETRIC') or nil)"
else
    # Legacy API keys, not sb_ API keys -> pass apikey through unchanged
    export LUA_AUTH_EXPR="\$((headers.authorization ~= nil and headers.authorization:sub(1, 10) ~= 'Bearer sb_' and headers.authorization) or headers.apikey)"
    export LUA_RT_WS_EXPR="\$(query_params.apikey)"

    # Functions: no opaque keys configured -> never set sb-api-key (the empty
    # value is stripped by the route's post-function).
    export LUA_FUNCTIONS_EXPR="\$(nil)"
fi

# Substitute environment variables in the Kong declarative config.
# Uses awk instead of eval/echo to preserve YAML quoting (eval strips double
# quotes, breaking "Header: value" patterns that YAML parses as mappings).
awk '{
  result = ""
  rest = $0
  while (match(rest, /\$[A-Za-z_][A-Za-z_0-9]*/)) {
    varname = substr(rest, RSTART + 1, RLENGTH - 1)
    if (varname in ENVIRON) {
      result = result substr(rest, 1, RSTART - 1) ENVIRON[varname]
    } else {
      result = result substr(rest, 1, RSTART + RLENGTH - 1)
    }
    rest = substr(rest, RSTART + RLENGTH)
  }
  print result rest
}' /config/api/kong.yml > "$KONG_DECLARATIVE_CONFIG"

# Remove empty key-auth credentials (unconfigured opaque keys)
sed -i '/^[[:space:]]*- key:[[:space:]]*$/d' "$KONG_DECLARATIVE_CONFIG"

exec /entrypoint.sh kong docker-start
SB_VOLUMES_API_KONG_ENTRYPOINT_SH,
        'volumes/db/realtime.sql' => <<<'SB_VOLUMES_DB_REALTIME_SQL'
\set pguser `echo "$POSTGRES_USER"`

create schema if not exists _realtime;
alter schema _realtime owner to :pguser;
SB_VOLUMES_DB_REALTIME_SQL,
        'volumes/db/webhooks.sql' => <<<'SB_VOLUMES_DB_WEBHOOKS_SQL'
BEGIN;
  -- Create pg_net extension
  CREATE EXTENSION IF NOT EXISTS pg_net SCHEMA extensions;
  -- Create supabase_functions schema
  CREATE SCHEMA supabase_functions AUTHORIZATION supabase_admin;
  GRANT USAGE ON SCHEMA supabase_functions TO postgres, anon, authenticated, service_role;
  ALTER DEFAULT PRIVILEGES IN SCHEMA supabase_functions GRANT ALL ON TABLES TO postgres, anon, authenticated, service_role;
  ALTER DEFAULT PRIVILEGES IN SCHEMA supabase_functions GRANT ALL ON FUNCTIONS TO postgres, anon, authenticated, service_role;
  ALTER DEFAULT PRIVILEGES IN SCHEMA supabase_functions GRANT ALL ON SEQUENCES TO postgres, anon, authenticated, service_role;
  -- supabase_functions.migrations definition
  CREATE TABLE supabase_functions.migrations (
    version text PRIMARY KEY,
    inserted_at timestamptz NOT NULL DEFAULT NOW()
  );
  -- Initial supabase_functions migration
  INSERT INTO supabase_functions.migrations (version) VALUES ('initial');
  -- supabase_functions.hooks definition
  CREATE TABLE supabase_functions.hooks (
    id bigserial PRIMARY KEY,
    hook_table_id integer NOT NULL,
    hook_name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    request_id bigint
  );
  CREATE INDEX supabase_functions_hooks_request_id_idx ON supabase_functions.hooks USING btree (request_id);
  CREATE INDEX supabase_functions_hooks_h_table_id_h_name_idx ON supabase_functions.hooks USING btree (hook_table_id, hook_name);
  COMMENT ON TABLE supabase_functions.hooks IS 'Supabase Functions Hooks: Audit trail for triggered hooks.';
  CREATE FUNCTION supabase_functions.http_request()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $function$
    DECLARE
      request_id bigint;
      payload jsonb;
      url text := TG_ARGV[0]::text;
      method text := TG_ARGV[1]::text;
      headers jsonb DEFAULT '{}'::jsonb;
      params jsonb DEFAULT '{}'::jsonb;
      timeout_ms integer DEFAULT 1000;
    BEGIN
      IF url IS NULL OR url = 'null' THEN
        RAISE EXCEPTION 'url argument is missing';
      END IF;

      IF method IS NULL OR method = 'null' THEN
        RAISE EXCEPTION 'method argument is missing';
      END IF;

      IF TG_ARGV[2] IS NULL OR TG_ARGV[2] = 'null' THEN
        headers = '{"Content-Type": "application/json"}'::jsonb;
      ELSE
        headers = TG_ARGV[2]::jsonb;
      END IF;

      IF TG_ARGV[3] IS NULL OR TG_ARGV[3] = 'null' THEN
        params = '{}'::jsonb;
      ELSE
        params = TG_ARGV[3]::jsonb;
      END IF;

      IF TG_ARGV[4] IS NULL OR TG_ARGV[4] = 'null' THEN
        timeout_ms = 1000;
      ELSE
        timeout_ms = TG_ARGV[4]::integer;
      END IF;

      CASE
        WHEN method = 'GET' THEN
          SELECT http_get INTO request_id FROM net.http_get(
            url,
            params,
            headers,
            timeout_ms
          );
        WHEN method = 'POST' THEN
          payload = jsonb_build_object(
            'old_record', OLD,
            'record', NEW,
            'type', TG_OP,
            'table', TG_TABLE_NAME,
            'schema', TG_TABLE_SCHEMA
          );

          SELECT http_post INTO request_id FROM net.http_post(
            url,
            payload,
            params,
            headers,
            timeout_ms
          );
        ELSE
          RAISE EXCEPTION 'method argument % is invalid', method;
      END CASE;

      INSERT INTO supabase_functions.hooks
        (hook_table_id, hook_name, request_id)
      VALUES
        (TG_RELID, TG_NAME, request_id);

      RETURN NEW;
    END
  $function$;
  -- Supabase super admin
  DO
  $$
  BEGIN
    IF NOT EXISTS (
      SELECT 1
      FROM pg_roles
      WHERE rolname = 'supabase_functions_admin'
    )
    THEN
      CREATE USER supabase_functions_admin NOINHERIT CREATEROLE LOGIN NOREPLICATION;
    END IF;
  END
  $$;
  GRANT ALL PRIVILEGES ON SCHEMA supabase_functions TO supabase_functions_admin;
  GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA supabase_functions TO supabase_functions_admin;
  GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA supabase_functions TO supabase_functions_admin;
  ALTER USER supabase_functions_admin SET search_path = "supabase_functions";
  ALTER table "supabase_functions".migrations OWNER TO supabase_functions_admin;
  ALTER table "supabase_functions".hooks OWNER TO supabase_functions_admin;
  ALTER function "supabase_functions".http_request() OWNER TO supabase_functions_admin;
  GRANT supabase_functions_admin TO postgres;
  -- http_request() is SECURITY DEFINER and runs as supabase_functions_admin. Serializing
  -- NEW/OLD can touch objects in the extensions schema (PostGIS geometry reads
  -- extensions.spatial_ref_sys), so the role needs USAGE on it.
  GRANT USAGE ON SCHEMA extensions TO supabase_functions_admin;
  -- Remove unused supabase_pg_net_admin role
  DO
  $$
  BEGIN
    IF EXISTS (
      SELECT 1
      FROM pg_roles
      WHERE rolname = 'supabase_pg_net_admin'
    )
    THEN
      REASSIGN OWNED BY supabase_pg_net_admin TO supabase_admin;
      DROP OWNED BY supabase_pg_net_admin;
      DROP ROLE supabase_pg_net_admin;
    END IF;
  END
  $$;
  -- pg_net grants when extension is already enabled
  DO
  $$
  BEGIN
    IF EXISTS (
      SELECT 1
      FROM pg_extension
      WHERE extname = 'pg_net'
    )
    THEN
      GRANT USAGE ON SCHEMA net TO supabase_functions_admin, postgres, anon, authenticated, service_role;
      ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
      ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
      ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
      ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
      REVOKE ALL ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
      REVOKE ALL ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
      GRANT EXECUTE ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
      GRANT EXECUTE ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
    END IF;
  END
  $$;
  -- Event trigger for pg_net
  CREATE OR REPLACE FUNCTION extensions.grant_pg_net_access()
  RETURNS event_trigger
  LANGUAGE plpgsql
  AS $$
  BEGIN
    IF EXISTS (
      SELECT 1
      FROM pg_event_trigger_ddl_commands() AS ev
      JOIN pg_extension AS ext
      ON ev.objid = ext.oid
      WHERE ext.extname = 'pg_net'
    )
    THEN
      GRANT USAGE ON SCHEMA net TO supabase_functions_admin, postgres, anon, authenticated, service_role;
      ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
      ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SECURITY DEFINER;
      ALTER function net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
      ALTER function net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) SET search_path = net;
      REVOKE ALL ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
      REVOKE ALL ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) FROM PUBLIC;
      GRANT EXECUTE ON FUNCTION net.http_get(url text, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
      GRANT EXECUTE ON FUNCTION net.http_post(url text, body jsonb, params jsonb, headers jsonb, timeout_milliseconds integer) TO supabase_functions_admin, postgres, anon, authenticated, service_role;
    END IF;
  END;
  $$;
  COMMENT ON FUNCTION extensions.grant_pg_net_access IS 'Grants access to pg_net';
  DO
  $$
  BEGIN
    IF NOT EXISTS (
      SELECT 1
      FROM pg_event_trigger
      WHERE evtname = 'issue_pg_net_access'
    ) THEN
      CREATE EVENT TRIGGER issue_pg_net_access ON ddl_command_end WHEN TAG IN ('CREATE EXTENSION')
      EXECUTE PROCEDURE extensions.grant_pg_net_access();
    END IF;
  END
  $$;
  INSERT INTO supabase_functions.migrations (version) VALUES ('20210809183423_update_grants');
  ALTER function supabase_functions.http_request() SECURITY DEFINER;
  ALTER function supabase_functions.http_request() SET search_path = supabase_functions;
  REVOKE ALL ON FUNCTION supabase_functions.http_request() FROM PUBLIC;
  GRANT EXECUTE ON FUNCTION supabase_functions.http_request() TO postgres, anon, authenticated, service_role;
COMMIT;
SB_VOLUMES_DB_WEBHOOKS_SQL,
        'volumes/db/roles.sql' => <<<'SB_VOLUMES_DB_ROLES_SQL'
-- NOTE: change to your own passwords for production environments
\set pgpass `echo "$POSTGRES_PASSWORD"`

ALTER USER authenticator WITH PASSWORD :'pgpass';
ALTER USER pgbouncer WITH PASSWORD :'pgpass';
ALTER USER supabase_auth_admin WITH PASSWORD :'pgpass';
ALTER USER supabase_functions_admin WITH PASSWORD :'pgpass';
ALTER USER supabase_storage_admin WITH PASSWORD :'pgpass';
SB_VOLUMES_DB_ROLES_SQL,
        'volumes/db/jwt.sql' => <<<'SB_VOLUMES_DB_JWT_SQL'
\set jwt_exp `echo "$JWT_EXP"`

ALTER DATABASE postgres SET "app.settings.jwt_exp" TO :'jwt_exp';
SB_VOLUMES_DB_JWT_SQL,
        'volumes/db/_supabase.sql' => <<<'SB_VOLUMES_DB__SUPABASE_SQL'
\set pguser `echo "$POSTGRES_USER"`

CREATE DATABASE _supabase WITH OWNER :pguser;
SB_VOLUMES_DB__SUPABASE_SQL,
        'volumes/db/logs.sql' => <<<'SB_VOLUMES_DB_LOGS_SQL'
\set pguser `echo "$POSTGRES_USER"`

\c _supabase
create schema if not exists _analytics;
alter schema _analytics owner to :pguser;
\c postgres
SB_VOLUMES_DB_LOGS_SQL,
        'volumes/db/pooler.sql' => <<<'SB_VOLUMES_DB_POOLER_SQL'
\set pguser `echo "$POSTGRES_USER"`

\c _supabase
create schema if not exists _supavisor;
alter schema _supavisor owner to :pguser;
\c postgres
SB_VOLUMES_DB_POOLER_SQL,
        'volumes/pooler/pooler.exs' => <<<'SB_VOLUMES_POOLER_POOLER_EXS'
{:ok, _} = Application.ensure_all_started(:supavisor)

{:ok, version} =
  case Supavisor.Repo.query!("select version()") do
    %{rows: [[ver]]} -> Supavisor.Helpers.parse_pg_version(ver)
    _ -> nil
  end

params = %{
  "external_id" => System.get_env("POOLER_TENANT_ID"),
  "db_host" => System.get_env("POSTGRES_HOST") || "db",
  "db_port" => System.get_env("POSTGRES_PORT"),
  "db_database" => System.get_env("POSTGRES_DB"),
  "require_user" => false,
  "auth_query" => "SELECT * FROM pgbouncer.get_auth($1)",
  "default_max_clients" => System.get_env("POOLER_MAX_CLIENT_CONN"),
  "default_pool_size" => System.get_env("POOLER_DEFAULT_POOL_SIZE"),
  "default_parameter_status" => %{"server_version" => version},
  "users" => [%{
    "db_user" => "pgbouncer",
    "db_password" => System.get_env("POSTGRES_PASSWORD"),
    "mode_type" => System.get_env("POOLER_POOL_MODE"),
    "pool_size" => System.get_env("POOLER_DEFAULT_POOL_SIZE"),
    "is_manager" => true
  }]
}

if !Supavisor.Tenants.get_tenant_by_external_id(params["external_id"]) do
  {:ok, _} = Supavisor.Tenants.create_tenant(params)
end
SB_VOLUMES_POOLER_POOLER_EXS,
        'volumes/functions/deno.jsonc' => <<<'SB_VOLUMES_FUNCTIONS_DENO_JSONC'
{
  "imports": {
    "@supabase/functions-js": "jsr:@supabase/functions-js@^2",
    "@supabase/server": "npm:@supabase/server@^1"
  }
}
SB_VOLUMES_FUNCTIONS_DENO_JSONC,
        'volumes/functions/main/index.ts' => <<<'SB_VOLUMES_FUNCTIONS_MAIN_INDEX_TS'
import * as jose from 'jsr:@panva/jose@6'
import { startMemoryPressureLoop } from './memory-pressure.ts'

console.log('main function started')
startMemoryPressureLoop()

addEventListener('unhandledrejection', (ev) => {
  // Without preventDefault(), a floating rejection destroys the main worker and the process exits.
  // Sync throws inside timer callbacks are still fatal; wrap them in try/catch.
  ev.preventDefault()
  console.error('unhandled promise rejection in main worker', ev.reason)
})

const MAX_WORKER_RETRIES = 3

const JWT_SECRET = Deno.env.get('JWT_SECRET')
const SUPABASE_JWKS = parseJwks(Deno.env.get('SUPABASE_JWKS'))
const LOCAL_JWKS = SUPABASE_JWKS ? jose.createLocalJWKSet(SUPABASE_JWKS) : null
const VERIFY_JWT = Deno.env.get('VERIFY_JWT') === 'true'

type AuthFailure = {
  code: RequestErrors
  message?: string
}

type FunctionFailure = {
  code: RequestErrors
  message: string
  status: number
}

export enum RequestErrors {
  InvalidLegacyJWT = 'UNAUTHORIZED_LEGACY_JWT',
  InvalidAsymmetricJWT = 'UNAUTHORIZED_ASYMMETRIC_JWT',
  InvalidTokenFormat = 'UNAUTHORIZED_INVALID_JWT_FORMAT',
  UnsupportedTokenAlgorithm = 'UNAUTHORIZED_UNSUPPORTED_TOKEN_ALGORITHM',
  MissingAuthHeader = 'UNAUTHORIZED_NO_AUTH_HEADER',
  NotFound = 'NOT_FOUND',
  BootError = 'BOOT_ERROR',
  EdgeFunctionError = 'EDGE_FUNCTION_ERROR',
  IdleTimeout = 'IDLE_TIMEOUT',
  WorkerResourceLimit = 'WORKER_RESOURCE_LIMIT',
  WorkerError = 'WORKER_ERROR',
  InvalidResponseStatusCode = 'INVALID_RESPONSE_STATUS_CODE',
}

// Errors from this router stay readable from browsers, as on the platform.
// Function responses keep the function's own CORS headers.
const ERROR_CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey',
  'Access-Control-Expose-Headers': 'sb-error-code',
}

function getFunctionErrorResponse({ code, message, status }: FunctionFailure): Response {
  return Response.json(
    { code, message },
    {
      status,
      headers: {
        ...ERROR_CORS_HEADERS,
        'sb-error-code': code,
      },
    }
  )
}

function onError(e: unknown): Response {
  const ref = crypto.randomUUID()
  console.error(`unhandled error in request handler (ref: ${ref})`, e)
  return getFunctionErrorResponse({
    code: RequestErrors.BootError,
    message: `Internal Server Error (ref: ${ref})`,
    status: 503,
  })
}

function handleWorkerResponse(response: Response): Response {
  if (response.status < 500) return response

  const headers = new Headers(response.headers)
  headers.set('sb-error-code', RequestErrors.EdgeFunctionError)

  const exposedHeaders = (headers.get('Access-Control-Expose-Headers') ?? '')
    .split(',')
    .map((name) => name.trim())
    .filter(Boolean)
  if (!exposedHeaders.some((name) => name.toLowerCase() === 'sb-error-code')) {
    exposedHeaders.push('sb-error-code')
  }
  headers.set('Access-Control-Expose-Headers', exposedHeaders.join(', '))

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  })
}

function resolveRuntimeError(e: unknown): FunctionFailure {
  // These error classes are supplied by Edge Runtime, rather than stock Deno.
  if (e instanceof Deno.errors.InvalidWorkerCreation) {
    return {
      code: RequestErrors.BootError,
      message: 'Function failed to start (please check logs)',
      status: 503,
    }
  }
  if (e instanceof Deno.errors.WorkerRequestCancelled) {
    return {
      code: RequestErrors.WorkerResourceLimit,
      message: 'Function failed due to not having enough compute resources (please check logs)',
      status: 546,
    }
  }
  if (e instanceof Deno.errors.WorkerRequestIdleTimeout) {
    return {
      code: RequestErrors.IdleTimeout,
      message: 'Request idle timeout limit (150s) reached',
      status: 504,
    }
  }
  // No dedicated runtime error class exists for invalid response statuses.
  // The Response constructor throws directly here or inside the user worker.
  if (
    (e instanceof RangeError || e instanceof Deno.errors.InvalidWorkerResponse) &&
    e.message.includes('is not equal to 101 and outside the range [200, 599]')
  ) {
    return {
      code: RequestErrors.InvalidResponseStatusCode,
      message: 'Function returned an invalid HTTP status code (please check logs)',
      status: 500,
    }
  }
  if (
    e instanceof Deno.errors.WorkerAlreadyRetired ||
    e instanceof Deno.errors.InvalidWorkerResponse
  ) {
    return {
      code: RequestErrors.WorkerError,
      message: 'Function exited due to an error (please check logs)',
      status: 500,
    }
  }
  return { code: RequestErrors.EdgeFunctionError, message: 'Internal Server Error', status: 500 }
}

// NOTE:(kallebysantos) We don't check for valid keys but just the bare array parsing,
// let this for 'jose' lib verification
export function parseJwks(raw: string | undefined): jose.JSONWebKeySet | null {
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw)
    if (parsed?.keys && Array.isArray(parsed.keys)) {
      return parsed as jose.JSONWebKeySet
    }
    return null
  } catch {
    return null
  }
}

/**
 * Extract JWT token from 'Authorization' header or fallback to 'sb-api-key' compatibility
 *
 * Parses the Authorization header to extract the Bearer token.
 * Expects format: "Bearer <token>"
 *
 * @param req - The HTTP request object
 * @returns The JWT token string or an authentication failure
 */
function extractBearerToken(authHeader: string | null): string | null {
  const tokenParts = (authHeader ?? '').trim().split(/\s+/)
  const [bearer, token] = tokenParts
  if (bearer.toLowerCase() !== 'bearer' || tokenParts.length !== 2 || !token) {
    return null
  }
  return token
}

function getAuthToken(req: Request): string | AuthFailure {
  const authHeader = req.headers.get('authorization')
  const sbApiKeyCompatibilityToken = req.headers.get('sb-api-key')

  if (!authHeader && !sbApiKeyCompatibilityToken) {
    return {
      code: RequestErrors.MissingAuthHeader,
      message: 'Missing authorization header',
    }
  }

  // NOTE:(kallebysantos) Compatibility mode is triggered when all conditions match:
  // - API proxy mints a temp token
  // - Original bearer is not present or is ApiKey
  const bearerToken = extractBearerToken(authHeader)
  const token = !bearerToken || bearerToken.startsWith('sb_')
    ? sbApiKeyCompatibilityToken
    : bearerToken

  if (!token) {
    return {
      code: RequestErrors.InvalidTokenFormat,
      message: 'Invalid JWT format',
    }
  }

  return token
}

function getAuthErrorResponse({ code, message = 'Invalid JWT' }: AuthFailure) {
  return Response.json(
    {
      code,
      message,
      // DEPRECATED: Retained for backward compatibility.
      msg: message,
    },
    {
      status: 401,
      headers: {
        ...ERROR_CORS_HEADERS,
        'sb-error-code': code,
      },
    }
  )
}

async function isValidLegacyJWT(jwt: string): Promise<AuthFailure | null> {
  if (!JWT_SECRET) {
    console.error('JWT_SECRET not available for HS256 token verification')
    return { code: RequestErrors.InvalidLegacyJWT }
  }

  const encoder = new TextEncoder();
  const secretKey = encoder.encode(JWT_SECRET);

  try {
    await jose.jwtVerify(jwt, secretKey);
  } catch (e) {
    console.error('Symmetric Legacy JWT verification error', e);
    return { code: RequestErrors.InvalidLegacyJWT }
  }
  return null
}

async function isValidJWT(jwt: string): Promise<AuthFailure | null> {
  if (!LOCAL_JWKS) {
    console.error('JWKS not available for ES256/RS256 token verification')
    return { code: RequestErrors.InvalidAsymmetricJWT }
  }

  try {
    await jose.jwtVerify(jwt, LOCAL_JWKS);
  } catch (e) {
    console.error('Asymmetric JWT verification error', e);
    return { code: RequestErrors.InvalidAsymmetricJWT }
  }

  return null
}

/**
 * Verify JWT token, handling both legacy (HS256) and newer (ES256/RS256) algorithms
 * 
 * This function automatically detects the algorithm used in the token and applies
 * the appropriate verification method:
 * - HS256: Uses JWT_SECRET (symmetric key)
 * - ES256/RS256: Uses JWKS endpoint (asymmetric public keys)
 * 
 * This fix ensures compatibility with both legacy tokens and newer asymmetric tokens,
 * resolving the "Key for the ES256 algorithm must be of type CryptoKey" error.
 * 
 * @param jwt - The JWT token string to verify
 * @returns Authentication failure details, or null when verification succeeds
 */
async function isValidHybridJWT(jwt: string): Promise<AuthFailure | null> {
  let jwtAlgorithm: string | undefined
  try {
    jwtAlgorithm = jose.decodeProtectedHeader(jwt).alg
  } catch (e) {
    console.error('JWT format error', e)
    return {
      code: RequestErrors.InvalidTokenFormat,
      message: 'Invalid JWT format',
    }
  }

  if (typeof jwtAlgorithm !== 'string' || !jwtAlgorithm) {
    return {
      code: RequestErrors.InvalidTokenFormat,
      message: 'Invalid JWT format',
    }
  }

  if (jwtAlgorithm === 'HS256') {
    console.log(`Legacy token type detected, attempting ${jwtAlgorithm} verification.`)

    return await isValidLegacyJWT(jwt)
  }

  if (jwtAlgorithm === 'ES256' || jwtAlgorithm === 'RS256') {
    return await isValidJWT(jwt)
  }

  return {
    code: RequestErrors.UnsupportedTokenAlgorithm,
    message: `Unsupported JWT algorithm ${jwtAlgorithm}`,
  }
}

Deno.serve({ onError }, async (req: Request) => {
  if (req.method !== 'OPTIONS' && VERIFY_JWT) {
    const token = getAuthToken(req)
    if (typeof token !== 'string') {
      return getAuthErrorResponse(token)
    }
    const authFailure = await isValidHybridJWT(token)
    if (authFailure) {
      return getAuthErrorResponse(authFailure)
    }
  }

  const url = new URL(req.url)
  const { pathname } = url
  const path_parts = pathname.split('/')
  const service_name = path_parts[1]

  if (!service_name || service_name === '') {
    return getFunctionErrorResponse({
      code: RequestErrors.NotFound,
      message: 'Requested function was not found',
      status: 404,
    })
  }

  const servicePath = `/home/deno/functions/${service_name}`
  console.error(`serving the request with ${servicePath}`)

  try {
    const serviceInfo = await Deno.stat(servicePath)
    if (!serviceInfo.isDirectory) {
      return getFunctionErrorResponse({
        code: RequestErrors.NotFound,
        message: 'Requested function was not found',
        status: 404,
      })
    }
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) {
      return getFunctionErrorResponse({
        code: RequestErrors.NotFound,
        message: 'Requested function was not found',
        status: 404,
      })
    }
    console.error(e)
    return getFunctionErrorResponse({
      code: RequestErrors.BootError,
      message: 'Function failed to start (please check logs)',
      status: 503,
    })
  }

  const memoryLimitMb = 256
  // Keep the wall clock above the 150s request idle timeout configured in Compose.
  const workerTimeoutMs = 400_000
  const requestAbsentTimeoutMs = 60_000
  const noModuleCache = false
  // Using a common Import Map for all functions 
  // to use a scope 'deno.json' it must be dinamically resolved base on the 'service_name'
  const importMapPath = `/home/deno/functions/deno.jsonc`
  // SUPABASE_FUNCTION_SLUG is listed after the container env snapshot so
  // nothing in it can shadow the value, and it is per-request because only this
  // worker knows which function the request resolved to.
  const envVarsObj = { ...Deno.env.toObject(), SUPABASE_FUNCTION_SLUG: service_name }
  const envVars = Object.keys(envVarsObj).map((k) => [k, envVarsObj[k]])

  const callWorker = async (req: Request, retriesLeft = MAX_WORKER_RETRIES): Promise<Response> => {
    // Preserve the body before fetch() can consume it, even on a failed attempt.
    // Must run before `new Request(req)` below, which takes over the body.
    // The unread retry branch can buffer the entire body in main-worker memory,
    // even when the first attempt succeeds.
    const retryReq = retriesLeft > 0 ? req.clone() : null

    try {
      const worker = await EdgeRuntime.userWorkers.create({
        servicePath,
        memoryLimitMb,
        workerTimeoutMs,
        context: { supervisor: { requestAbsentTimeoutMs } },
        noModuleCache,
        importMapPath,
        envVars,
      })
      // Gateway-minted internal JWT is for this router only; never expose it to user functions.
      const userReq = new Request(req)
      userReq.headers.delete('sb-api-key')
      EdgeRuntime.applySupabaseTag(req, userReq)
      return handleWorkerResponse(await worker.fetch(userReq))
    } catch (e) {
      // Retirement rejects before dispatch, so user code has not run yet.
      if (e instanceof Deno.errors.WorkerAlreadyRetired && retryReq) {
        console.warn(`${service_name}: worker retired before dispatch; retrying (${retriesLeft} left)`)
        // Request.clone() does not copy the tag that connects streaming to the client.
        EdgeRuntime.applySupabaseTag(req, retryReq)
        return await callWorker(retryReq, retriesLeft - 1)
      }

      console.error(e)
      return getFunctionErrorResponse(resolveRuntimeError(e))
    }
  }

  return await callWorker(req)
})
SB_VOLUMES_FUNCTIONS_MAIN_INDEX_TS,
        'volumes/functions/main/memory-pressure.ts' => <<<'SB_VOLUMES_FUNCTIONS_MAIN_MEMORY_PRESSURE_TS'
const CHECK_INTERVAL_MS = 1_000
const PRESSURE_THRESHOLD = 0.8
const CLEANUP_TIMEOUT_MS = 1_000
const RETRY_INTERVAL_MS = 5_000

function parseBytes(value: string, minimum: number): number {
  const bytes = Number(value)
  if (!/^\d+$/.test(value) || !Number.isSafeInteger(bytes) || bytes < minimum) {
    throw new Error('Invalid cgroup memory value')
  }
  return bytes
}

export function startMemoryPressureLoop(): void {
  let errorReported = false
  let activeReported = false

  const tick = async () => {
    let delay = CHECK_INTERVAL_MS
    try {
      const max = (await Deno.readTextFile('/sys/fs/cgroup/memory.max')).trim()
      if (max === 'max') {
        console.warn('Memory pressure monitor disabled: a finite memory limit is required')
        return
      }
      const current = (await Deno.readTextFile('/sys/fs/cgroup/memory.current')).trim()
      const stat = await Deno.readTextFile('/sys/fs/cgroup/memory.stat')
      const limitBytes = parseBytes(max, 1)
      const currentBytes = parseBytes(current, 0)
      const inactiveFileBytes = parseBytes(stat.match(/^inactive_file[ \t]+(\S+)[ \t]*$/m)?.[1] ?? '', 0)
      // Exclude inactive file cache that the kernel can reclaim under pressure.
      const workingSetBytes = Math.max(0, currentBytes - inactiveFileBytes)
      // Logged once so operators (and test-container-logs.sh) can confirm monitoring is on.
      if (!activeReported) {
        console.log(`Memory pressure monitor active (limit ${limitBytes} bytes)`)
        activeReported = true
      }

      if (workingSetBytes / limitBytes >= PRESSURE_THRESHOLD) {
        delay = RETRY_INTERVAL_MS
        const acknowledged = await EdgeRuntime.userWorkers.tryCleanupIdleWorkers(CLEANUP_TIMEOUT_MS)
        EdgeRuntime.miCollect()
        if (acknowledged > 0) {
          console.warn(
            `Memory pressure: working set ${workingSetBytes} / ${limitBytes} bytes before cleanup; ` +
              `${acknowledged} worker(s) acknowledged retirement`
          )
        }
      }
      errorReported = false
    } catch (error) {
      if (error instanceof Deno.errors.NotFound || error instanceof Deno.errors.PermissionDenied) {
        console.warn(`Memory pressure monitor disabled: ${error}`)
        return
      }
      delay = RETRY_INTERVAL_MS
      if (!errorReported) {
        console.warn(`Memory pressure monitor failed; retrying: ${error}`)
        errorReported = true
      }
    }
    // Schedule only after this cycle finishes: no overlapping work or queued ticks.
    setTimeout(() => void tick(), delay)
  }

  setTimeout(() => void tick(), CHECK_INTERVAL_MS)
}
SB_VOLUMES_FUNCTIONS_MAIN_MEMORY_PRESSURE_TS,
        'volumes/functions/hello/index.ts' => <<<'SB_VOLUMES_FUNCTIONS_HELLO_INDEX_TS'
// Follow this setup guide to integrate the Deno language server with your editor:
// https://deno.land/manual/getting_started/setup_your_environment
// This enables autocomplete, go to definition, etc.

// Setup type definitions for built-in Supabase Runtime APIs
import "@supabase/functions-js/edge-runtime.d.ts"
import { withSupabase } from "@supabase/server"

// Logs are visible from 'functions' container inspector
console.log("Hello from Functions!");

// This endpoint uses 'publishable' | 'secret' access, apiKey is required.
// Use publishable for Client-facing, key-validated endpoints
// Use secret for Server-to-server, internal calls
export default {
  fetch: withSupabase({ auth: ["publishable", "secret"] }, async (req, ctx) => {
    // Called by another service with a secret key
    // ctx.supabaseAdmin bypasses RLS — use for privileged operations
    /*
    if (ctx.authMode === "secret") {
      const { user_id } = await req.json();
      const { data } = await ctx.supabaseAdmin.auth.admin.getUserById(user_id);

      return Response.json({
        email: data?.user?.email,
      });
    }
    */

    return Response.json({ message: "Hello from Edge Functions!" });
  }),
};

// To invoke:
// curl 'http://localhost:<API_GW_HTTP_PORT>/functions/v1/hello' \
//   --header 'apiKey: <sb_publishable/sb_secret key>'

SB_VOLUMES_FUNCTIONS_HELLO_INDEX_TS,
    ],
];
