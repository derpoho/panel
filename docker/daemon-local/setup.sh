#!/usr/bin/env bash
# Configure the local Laravel panel and certificates after build.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

test -f public/releases/daemon/stable/VERSION || { echo 'Run docker/daemon-local/build.sh first.' >&2; exit 1; }
test -x storage/app/private/gateway/serveros-gateway || { echo 'Local gateway binary is missing.' >&2; exit 1; }

if [ ! -f storage/app/private/daemon-ca.crt ]; then
  php artisan daemon:ca init
fi
if [ ! -f storage/app/private/gateway/gateway.crt ]; then
  php artisan daemon:ca issue-server --host=gateway.serveros.test --out=storage/app/private/gateway
fi

php -r '
  $path = ".env";
  $body = file_get_contents($path);
  if ($body === false) { throw new RuntimeException("Missing .env"); }
  preg_match("/^DAEMON_GATEWAY_SECRET=(.+)$/m", $body, $found);
  $secret = $found[1] ?? bin2hex(random_bytes(32));
  foreach ([
    "DAEMON_GATEWAY_SECRET" => $secret,
    "DAEMON_GATEWAY_HOST" => "gateway.serveros.test:8443",
    "DAEMON_PANEL_URL" => "https://serveros.test",
    "DAEMON_INSTALL_URL" => "https://serveros.test/install.sh",
    "DAEMON_RELEASES_URL" => "https://serveros.test/releases/daemon",
    "DAEMON_INSTALL_READY" => "true",
    "DAEMON_COMMANDS_ENABLED" => "true",
    "DAEMON_GATEWAY_INTERNAL_URL" => "http://127.0.0.1:8090",
    "DAEMON_LOCAL_NODE_PORT" => "8088",
  ] as $key => $value) {
    $line = $key."=".$value;
    $body = preg_match("/^".preg_quote($key, "/")."=.*/m", $body)
      ? preg_replace("/^".preg_quote($key, "/")."=.*/m", $line, $body)
      : rtrim($body)."\n".$line."\n";
  }
  file_put_contents($path, $body);
  $gateway = "storage/app/private/gateway/local.env";
  file_put_contents($gateway, "GATEWAY_LISTEN=0.0.0.0:8443\nGATEWAY_INTERNAL=0.0.0.0:8090\nGATEWAY_CA=/certs/ca.crt\nGATEWAY_CERT=/certs/gateway.crt\nGATEWAY_KEY=/certs/gateway.key\nGATEWAY_PANEL_URL=https://serveros.test\nGATEWAY_PANEL_CA=/tmp/herd-ca.pem\nGATEWAY_SECRET=$secret\n");
  chmod($gateway, 0600);
' 

php artisan migrate --force
php artisan config:clear
echo 'Local daemon release and panel are configured. Run docker/daemon-local/start-gateway.sh next.'
