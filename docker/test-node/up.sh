#!/usr/bin/env bash
# Build and start the local test node: a systemd Ubuntu box in Docker that
# you connect to the local panel like any VPS. See docker/test-node/README.md.
#
#   docker/test-node/up.sh            # build (if needed) and start
#   docker/test-node/up.sh --rebuild  # throw the node away and start fresh
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
NAME="${SERVEROS_NODE_NAME:-serveros-test-node}"
PORT="${SERVEROS_NODE_PORT:-8088}"
IMAGE=serveros-test-node
HERD_CA="$HOME/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem"

if [ ! -f "$HERD_CA" ]; then
  echo "Herd's CA isn't at $HERD_CA. Is Herd installed, with serveros.test secured (herd secure serveros)?" >&2
  exit 1
fi
cp "$HERD_CA" "$DIR/herd-ca.pem"

if [ "${1:-}" = "--rebuild" ]; then
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker volume rm "$NAME-docker" >/dev/null 2>&1 || true
fi

if docker container inspect "$NAME" >/dev/null 2>&1; then
  docker start "$NAME" >/dev/null
  echo "$NAME is running (it already existed). Use --rebuild for a fresh one."
  exit 0
fi

docker build -t "$IMAGE" "$DIR"

# Privileged with its own cgroup namespace so systemd and Docker run inside.
# Port 80 inside (Caddy, see docker/daemon-local/local-proxy.sh) is published
# on the Mac, so services open at http://<port>.localhost:$PORT.
docker run -d --name "$NAME" --hostname "$NAME" \
  --privileged --cgroupns=private \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
  -v "$NAME-docker:/var/lib/docker" \
  -p "$PORT:80" \
  "$IMAGE" >/dev/null

echo "Started $NAME. Next:"
echo "  1. In the panel: Machines > Connect server, and copy the command."
echo "  2. docker exec -it $NAME bash   and paste it there."
echo "  3. docker/daemon-local/local-proxy.sh $NAME   (open services at http://<port>.localhost:$PORT)"
