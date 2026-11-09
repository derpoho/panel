#!/usr/bin/env bash
# Make services on the local test node reachable from this Mac.
#
# Services only listen on the node's own 127.0.0.1, which the Mac can't see.
# This installs Caddy on the node with one rule: http://<port>.localhost
# proxies to the node's 127.0.0.1:<port>. The node's port 80 is published on
# the Mac (NODE_CADDY_PORT, 8088 here), so Umami on 3000 opens at
# http://3000.localhost:8088 with nothing to forward per service.
#
# Caddy comes from the same apt repository the daemon uses, so a later
# deploy with a domain finds it installed, keeps this Caddyfile and only
# appends its import line.
set -euo pipefail

NODE="${1:-serveros-test-node}"

docker exec -i "$NODE" bash -s <<'SCRIPT'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
if ! command -v caddy >/dev/null 2>&1; then
  apt-get update -q
  apt-get install -y -q debian-keyring debian-archive-keyring apt-transport-https curl gnupg
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
    | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
    > /etc/apt/sources.list.d/caddy-stable.list
  chmod o+r /usr/share/keyrings/caddy-stable-archive-keyring.gpg /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
  apt-get install -y -q caddy
fi

mkdir -p /etc/caddy/serveros.d
imports="$(grep -h '^import ' /etc/caddy/Caddyfile 2>/dev/null || true)"
cat > /etc/caddy/Caddyfile <<'CADDY'
# Local test node: http://<port>.localhost on the Mac reaches 127.0.0.1:<port> here.
http://*.localhost {
	@port header_regexp port Host ^([0-9]{2,5})\.localhost(:[0-9]+)?$
	handle @port {
		reverse_proxy 127.0.0.1:{re.port.1}
	}
	handle {
		respond "Open http://<port>.localhost to reach a service on this machine." 404
	}
}
CADDY
[ -z "$imports" ] || printf '%s\n' "$imports" >> /etc/caddy/Caddyfile
caddy fmt --overwrite /etc/caddy/Caddyfile >/dev/null 2>&1 || true
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable caddy >/dev/null 2>&1
systemctl restart caddy
SCRIPT

echo "Caddy on $NODE now serves http://<port>.localhost (port 80 on the node)."
