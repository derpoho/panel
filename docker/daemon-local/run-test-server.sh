#!/usr/bin/env bash
# Copy the one-time token from the dashboard command and pass it as argument 1.
set -euo pipefail

# The test node runs at the Mac's own architecture.
case "$(uname -m)" in arm64|aarch64) ARCH=arm64 ;; *) ARCH=amd64 ;; esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ "${1:-}" = "--token-file" ]; then
  IFS= read -r TOKEN < "${2:?provide a token file}" || [ -n "$TOKEN" ]
else
  TOKEN="${1:-}"
fi
if [[ ! "$TOKEN" =~ ^[a-f0-9]{64}$ ]]; then
  echo 'Usage: docker/daemon-local/run-test-server.sh <64-character-token>' >&2
  exit 2
fi
if docker container inspect serveros-daemon-test >/dev/null 2>&1; then
  echo 'serveros-daemon-test is already running. Stop or reset it first.' >&2
  exit 1
fi
printf '%s' "$TOKEN" > "$ROOT/storage/app/private/daemon-local-token"
chmod 0600 "$ROOT/storage/app/private/daemon-local-token"

docker run --rm --name serveros-daemon-test --platform "linux/$ARCH" \
  --add-host=serveros.test:host-gateway \
  --add-host=gateway.serveros.test:host-gateway \
  -e SERVEROS_LOCAL_PANEL_CA=/usr/local/share/ca-certificates/herd-ca.crt \
  -e SERVEROS_ENROL_TOKEN="$TOKEN" \
  -v "$ROOT/docker/test-node/herd-ca.pem:/usr/local/share/ca-certificates/herd-ca.crt:ro" \
  -v "$ROOT/docker/daemon-local/fake-systemctl:/usr/local/bin/systemctl:ro" \
  rust:1-bookworm bash -lc '
    set -euo pipefail
    update-ca-certificates >/dev/null
    mkdir -p /run/systemd/system
    python3 -m http.server 8080 >/tmp/sample-service.log 2>&1 &
    curl -fsSL https://serveros.test/install.sh | bash -s -- \
      --token="$SERVEROS_ENROL_TOKEN" \
      --panel=https://serveros.test \
      --releases=https://serveros.test/releases/daemon
    exec /usr/local/bin/serverosd run
  '
