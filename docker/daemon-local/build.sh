#!/usr/bin/env bash
# Build a Docker-only Linux release (for this Mac's CPU) from the private daemon repository.
set -euo pipefail

# The test node runs at the Mac's own architecture.
case "$(uname -m)" in arm64|aarch64) ARCH=arm64 ;; *) ARCH=amd64 ;; esac

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SOURCE="${SERVEROS_DAEMON_SOURCE:-/private/tmp/serveros-daemon}"
VERSION=v0.0.0-local

if [ ! -d "$SOURCE/.git" ]; then
  # Private repo: needs GitHub access (gh auth login, or an SSH key).
  git clone https://github.com/serveroshq/daemon.git "$SOURCE"
fi

# The only change not in the daemon repo: let enrolment pin the local
# panel's CA (Herd's), through SERVEROS_LOCAL_PANEL_CA. Everything else that
# used to be a patch here (services, Caddy, backups...) is upstream now.
if ! git -C "$SOURCE" apply --reverse --check "$ROOT/docker/daemon-local/panel-ca.patch" >/dev/null 2>&1; then
  git -C "$SOURCE" apply "$ROOT/docker/daemon-local/panel-ca.patch"
fi

docker run --rm --platform "linux/$ARCH" \
  -e SERVEROS_VERSION="$VERSION" \
  -e SERVEROS_CHANNEL=dev \
  -e SERVEROS_PANEL_URL=https://serveros.test \
  -v "$SOURCE:/src" \
  -v serveros-cargo-registry:/usr/local/cargo/registry \
  -w /src rust:1-bookworm \
  cargo build --release -p serverosd -p serveros-gateway

RELEASE="$ROOT/public/releases/daemon/$VERSION"
mkdir -p "$RELEASE" "$ROOT/public/releases/daemon/stable" "$ROOT/storage/app/private/gateway"
cp "$SOURCE/target/release/serverosd" "$RELEASE/serverosd-$VERSION-linux-$ARCH"
cp "$SOURCE/target/release/serveros-gateway" "$ROOT/storage/app/private/gateway/serveros-gateway"
(
  cd "$RELEASE"
  shasum -a 256 "serverosd-$VERSION-linux-$ARCH" > SHA256SUMS
)
printf '%s\n' "$VERSION" > "$ROOT/public/releases/daemon/stable/VERSION"
echo "Local daemon release is served at https://serveros.test/releases/daemon/$VERSION/"
