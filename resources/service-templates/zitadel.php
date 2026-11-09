<?php

return [
    'name' => 'Zitadel',
    'category' => 'Infrastructure',
    'description' => 'Identity and access management with OIDC, SAML, passkeys and multiple tenants.',
    'icon' => 'shield',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/debug/healthz',
    'notes' => 'It needs a domain with HTTPS, and the domain setting must match it. Open /ui/console and sign in as zitadel-admin@zitadel.YOUR-DOMAIN with the password Password1! and you will be asked to change it. It runs the API, the login app and Postgres behind a small Caddy proxy.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'required' => true, 'hint' => 'The domain you give this service, like auth.example.com'],
        ['key' => 'MASTERKEY', 'label' => 'Master key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'COOKIE_SECRET', 'label' => 'Login cookie secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  proxy:
    image: caddy:2
    restart: unless-stopped
    depends_on: [zitadel, login]
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
  zitadel:
    image: ghcr.io/zitadel/zitadel:latest
    restart: unless-stopped
    user: "0"
    depends_on: [db]
    command: start-from-init --masterkey "${MASTERKEY}"
    environment:
      ZITADEL_PORT: 8080
      ZITADEL_EXTERNALDOMAIN: ${DOMAIN}
      ZITADEL_EXTERNALPORT: 443
      ZITADEL_EXTERNALSECURE: "true"
      ZITADEL_TLS_ENABLED: "false"
      ZITADEL_DATABASE_POSTGRES_DSN: postgresql://postgres:${DB_PASSWORD}@db:5432/zitadel?sslmode=disable
      ZITADEL_FIRSTINSTANCE_ORG_HUMAN_PASSWORDCHANGEREQUIRED: "true"
      ZITADEL_FIRSTINSTANCE_LOGINCLIENTPATPATH: /zitadel/bootstrap/login-client.pat
      ZITADEL_FIRSTINSTANCE_ORG_LOGINCLIENT_MACHINE_USERNAME: login-client
      ZITADEL_FIRSTINSTANCE_ORG_LOGINCLIENT_MACHINE_NAME: Automatically Initialized IAM_LOGIN_CLIENT
      ZITADEL_FIRSTINSTANCE_ORG_LOGINCLIENT_PAT_EXPIRATIONDATE: "2099-01-01T00:00:00Z"
      ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED: "true"
      ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_BASEURI: https://${DOMAIN}/ui/v2/login/
      ZITADEL_OIDC_DEFAULTLOGINURLV2: https://${DOMAIN}/ui/v2/login/login?authRequest=
      ZITADEL_OIDC_DEFAULTLOGOUTURLV2: https://${DOMAIN}/ui/v2/login/logout?post_logout_redirect=
      ZITADEL_SAML_DEFAULTLOGINURLV2: https://${DOMAIN}/ui/v2/login/login?samlRequest=
    volumes:
      - bootstrap:/zitadel/bootstrap
  login:
    image: ghcr.io/zitadel/zitadel-login:latest
    restart: unless-stopped
    user: "0"
    depends_on: [zitadel]
    environment:
      ZITADEL_API_URL: http://zitadel:8080
      NEXT_PUBLIC_BASE_PATH: /ui/v2/login
      ZITADEL_SERVICE_USER_TOKEN_FILE: /zitadel/bootstrap/login-client.pat
      ZITADEL_SESSION_COOKIE_SECRET: ${COOKIE_SECRET}
      CUSTOM_REQUEST_HEADERS: Host:${DOMAIN},X-Forwarded-Proto:https
    volumes:
      - bootstrap:/zitadel/bootstrap:ro
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: zitadel
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  bootstrap:
  db:
YAML,
        'Caddyfile' => <<<'CADDY'
{
	admin off
	auto_https off
}

:8080 {
	@root path /
	rewrite @root /ui/v2/login/

	handle /ui/v2/login* {
		reverse_proxy login:3000
	}

	handle {
		reverse_proxy h2c://zitadel:8080
	}
}
CADDY,
    ],
];
