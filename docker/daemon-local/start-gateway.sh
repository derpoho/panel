#!/usr/bin/env bash
set -euo pipefail

# The test node runs at the Mac's own architecture.
case "$(uname -m)" in arm64|aarch64) ARCH=arm64 ;; *) ARCH=amd64 ;; esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CERTS="$ROOT/storage/app/private/gateway"
test -x "$CERTS/serveros-gateway"
test -f "$CERTS/local.env"

docker rm -f serveros-gateway-local >/dev/null 2>&1 || true
docker run -d --rm --name serveros-gateway-local --platform "linux/$ARCH" \
  --add-host=serveros.test:host-gateway \
  -p 8443:8443 \
  -p 127.0.0.1:8090:8090 \
  --env-file "$CERTS/local.env" \
  -v "$CERTS:/certs:ro" \
  -v "$ROOT/docker/test-node/herd-ca.pem:/tmp/herd-ca.pem:ro" \
  rust:1-bookworm /certs/serveros-gateway >/dev/null
echo 'Local daemon gateway is listening on gateway.serveros.test:8443 inside Docker.'
