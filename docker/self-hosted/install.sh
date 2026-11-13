#!/usr/bin/env bash
# Install ServerOS on your own server, with Docker:
#
#   curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | sudo bash -s -- --domain=panel.example.com --email=you@example.com
#
# or from a checkout: sudo bash docker/self-hosted/install.sh --domain=... --email=...
#
# It runs the official image, ghcr.io/serveroshq/panel: the open source
# panel (AGPL-3.0) plus the paid features, in the commercial panel-ee
# package under its own license. Without a license key it works exactly
# like the community edition; add a key in Settings › License to turn the
# paid features on. --image runs another, like a community edition you
# built from source (docs/self-hosting.md, "Editions").
#
# The panel answers on the domain and each workspace on a subdomain of it,
# so before you start point both at this server: an A (or AAAA) record for
# panel.example.com and one for *.panel.example.com. Ports 80 and 443 (the
# panel, with free Let's Encrypt certificates) and 8443 (where your
# machines connect) must be open.
#
# Behind a Cloudflare Tunnel instead (--tunnel-token), ports 80 and 443
# stay closed: cloudflared carries the panel's traffic and Cloudflare does
# HTTPS. Machines still connect to the gateway directly, on a name of its
# own that points at this server, DNS only (--gateway-host), port 8443.
#
# Options:
#   --domain=NAME      the panel's address (required)
#   --email=ADDRESS    for Let's Encrypt's expiry notices (required, unless
#                      behind a tunnel)
#   --tunnel-token=TOKEN
#                      run behind this Cloudflare Tunnel (the token from the
#                      tunnel's install command in Cloudflare, Networking > Tunnels)
#   --gateway-host=NAME
#                      where machines connect, behind a tunnel (like
#                      gateway.example.com, DNS only, pointing here)
#   --dir=PATH         where it lives (default /opt/serveros)
#   --version=X.Y.Z    an image version instead of the latest
#   --image=NAME       another image (default ghcr.io/serveroshq/panel,
#                      the official one); switches an install to it
#
# Running it again keeps your settings and data, and brings the install up
# to date. Afterwards, serveros-panel does upgrades and backups.
set -euo pipefail

DOMAIN="${SERVEROS_DOMAIN:-}"
EMAIL="${ACME_EMAIL:-}"
DIR="${SERVEROS_DIR:-/opt/serveros}"
IMAGE_NAME="ghcr.io/serveroshq/panel"
VERSION="latest"
IMAGE_GIVEN=0
TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN:-}"
GATEWAY_HOST="${SERVEROS_GATEWAY_HOST:-}"

say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33mwarning:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#--domain=}" ;;
        --email=*) EMAIL="${arg#--email=}" ;;
        --dir=*) DIR="${arg#--dir=}" ;;
        --version=*) VERSION="${arg#--version=}"; VERSION="${VERSION#v}" ;;
        --image=*) IMAGE_NAME="${arg#--image=}"; IMAGE_GIVEN=1 ;;
        --tunnel-token=*) TUNNEL_TOKEN="${arg#--tunnel-token=}" ;;
        --gateway-host=*) GATEWAY_HOST="${arg#--gateway-host=}" ;;
        -h|--help) sed -n '2,45p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) fail "unknown option $arg (see --help)" ;;
    esac
done
IMAGE="${IMAGE_NAME}:${VERSION}"

[ "$(id -u)" -eq 0 ] || fail "run it as root (with sudo)."
[ "$(uname -s)" = "Linux" ] || fail "ServerOS self-hosting runs on Linux."

ask() {
    # ask VAR "Question": from the terminal, even when piped from curl.
    local answer=""
    if [ -r /dev/tty ]; then
        read -r -p "$2 " answer < /dev/tty || true
    fi
    printf -v "$1" '%s' "$answer"
}

# An existing install keeps its settings.
if [ -f "$DIR/.env" ]; then
    existing() { grep -E "^$1=" "$DIR/.env" | tail -n1 | cut -d= -f2- || true; }
    DOMAIN="${DOMAIN:-$(existing SERVEROS_DOMAIN)}"
    EMAIL="${EMAIL:-$(existing ACME_EMAIL)}"
    TUNNEL_TOKEN="${TUNNEL_TOKEN:-$(existing CLOUDFLARE_TUNNEL_TOKEN)}"
    GATEWAY_HOST="${GATEWAY_HOST:-$(existing SERVEROS_GATEWAY_HOST)}"
fi
# The whole install command pasted: the token is its last word.
TUNNEL_TOKEN="$(printf '%s' "$TUNNEL_TOKEN" | awk '{print $NF}')"
TUNNEL=0
[ -z "$TUNNEL_TOKEN" ] || TUNNEL=1

is_domain() { printf '%s' "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; }
bare() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -e 's|^https\?://||' -e 's|/.*$||' -e 's|:[0-9]*$||'; }

[ -n "$DOMAIN" ] || ask DOMAIN "The panel's domain (like panel.example.com):"
DOMAIN="$(bare "$DOMAIN")"
is_domain "$DOMAIN" || fail "\"$DOMAIN\" isn't a domain name. Use --domain=panel.example.com"
if [ "$TUNNEL" = 1 ]; then
    printf '%s' "$TUNNEL_TOKEN" | grep -Eq '^[A-Za-z0-9+/=_-]{40,4096}$' \
        || fail "that isn't a Cloudflare Tunnel token. Copy it from the tunnel's install command in Cloudflare (Networking > Tunnels)."
    [ -n "$GATEWAY_HOST" ] || ask GATEWAY_HOST "Where machines connect, a name pointing straight at this server, DNS only (like gateway.example.com):"
    GATEWAY_HOST="$(bare "$GATEWAY_HOST")"
    is_domain "$GATEWAY_HOST" || fail "give the gateway a name with --gateway-host=gateway.example.com"
    [ "$GATEWAY_HOST" != "$DOMAIN" ] || fail "machines can't connect through the tunnel, so the gateway needs a name of its own, not $DOMAIN (like gateway.example.com)."
else
    [ -n "$EMAIL" ] || ask EMAIL "Your email, for Let's Encrypt:"
    printf '%s' "$EMAIL" | grep -q '@' || fail "give an email address with --email=you@example.com"
fi

# Docker, with Compose.
if ! command -v docker >/dev/null 2>&1; then
    say "Installing Docker"
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 isn't installed (the docker-compose-plugin package)."
systemctl enable --now docker >/dev/null 2>&1 || true

# Ports another program already holds.
if command -v ss >/dev/null 2>&1 && [ ! -f "$DIR/compose.yaml" ]; then
    # Behind a tunnel the panel only listens on this server, on 18080 and 18443.
    ports="80 443 8443"
    [ "$TUNNEL" = 0 ] || ports="18080 18443 8443"
    for port in $ports; do
        if ss -ltnH "sport = :$port" | grep -q .; then
            fail "port $port is already in use ($(ss -ltnpH "sport = :$port" | sed -n 's/.*users:((\"\([^\"]*\)\".*/\1/p' | head -n1)). ServerOS needs $(printf '%s' "$ports" | sed 's/ /, /g')."
        fi
    done
fi

# DNS: a warning only, since it may still be on its way.
public_ip="$(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || true)"
check_dns() {
    local name="$1" found
    found="$(getent ahostsv4 "$name" 2>/dev/null | awk 'NR==1 {print $1}')"
    if [ -z "$found" ]; then
        warn "$name doesn't resolve yet. Add an A record pointing at ${public_ip:-this server}."
    elif [ -n "$public_ip" ] && [ "$found" != "$public_ip" ]; then
        warn "$name points at $found, not this server ($public_ip)."
    fi
}
if [ "$TUNNEL" = 1 ]; then
    # The panel's names come from the tunnel's routes (set up
    # below); the gateway's must point here without Cloudflare's proxy.
    check_dns "$GATEWAY_HOST"
else
    check_dns "$DOMAIN"
    check_dns "serveros-check-$RANDOM.$DOMAIN"
fi

mkdir -p "$DIR"
chmod 700 "$DIR"
cd "$DIR"

random() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

if [ ! -f .env ]; then
    say "Writing $DIR/.env"
    umask 077
    cat > .env <<EOF
# ServerOS, self-hosted. Change a setting, then: docker compose up -d
SERVEROS_DOMAIN=$DOMAIN
ACME_EMAIL=$EMAIL
SERVEROS_IMAGE=$IMAGE

# Generated for this install. Keep a copy somewhere safe: backups need
# APP_KEY to be read back.
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
DB_PASSWORD=$(random 24)
DAEMON_GATEWAY_SECRET=$(random 32)

# The key ServerOS signs daemon releases with. Machines check the daemon
# they download against it before installing it.
DAEMON_RELEASE_PUBKEY=5245716675785d7bf5dc36d783fa5c690c705da4d542082f398e3e79543d06f8

# Email, for invitations, alerts and password resets: set it up in the
# web installer, or here. Until it's set up, emails are only written to
# the log (docker compose logs queue).
# MAIL_MAILER=smtp
# MAIL_HOST=smtp.example.com
# MAIL_PORT=587
# MAIL_USERNAME=
# MAIL_PASSWORD=
# MAIL_FROM_ADDRESS=serveros@example.com
# MAIL_FROM_NAME=ServerOS

# Once a day the panel sends serveros.com an anonymous ping: a random id for
# this install, its version, whether it's licensed and how many workspaces,
# people and machines it has. Nothing that says who or where you are.
# See exactly what it sends: serveros-panel artisan install:ping --show
# Turn it off:
# SERVEROS_TELEMETRY=false
EOF
    umask 022
elif [ "$VERSION" != "latest" ] || [ "$IMAGE_GIVEN" = 1 ] || ! grep -q '^SERVEROS_IMAGE=' .env; then
    if grep -q '^SERVEROS_IMAGE=' .env; then
        sed -i "s|^SERVEROS_IMAGE=.*|SERVEROS_IMAGE=$IMAGE|" .env
    else
        printf 'SERVEROS_IMAGE=%s\n' "$IMAGE" >> .env
    fi
fi
IMAGE="$(grep -E '^SERVEROS_IMAGE=' .env | tail -n1 | cut -d= -f2-)"

# set_env KEY VALUE: replaces the setting, or adds it.
set_env() {
    local rest
    rest="$(grep -vE "^$1=" .env || true)"
    umask 077
    printf '%s\n%s=%s\n' "$rest" "$1" "$2" > .env.new && mv .env.new .env
    umask 022
}
if [ "$TUNNEL" = 1 ]; then
    # cloudflared (COMPOSE_PROFILES=tunnel) carries the panel's traffic, so the
    # panel only listens on this server. The gateway reaches the panel inside
    # Docker with Caddy's own certificate (local_certs), since Let's Encrypt
    # can't reach it here.
    set_env SERVEROS_TUNNEL cloudflare
    set_env CLOUDFLARE_TUNNEL_TOKEN "$TUNNEL_TOKEN"
    set_env SERVEROS_GATEWAY_HOST "$GATEWAY_HOST"
    set_env COMPOSE_PROFILES tunnel
    set_env HTTP_PORT 127.0.0.1:18080
    set_env HTTPS_PORT 127.0.0.1:18443
    set_env CADDY_GLOBAL_OPTIONS local_certs
    set_env GATEWAY_PANEL_CA /gateway/panel-ca.crt
fi
grep -q '^DAEMON_RELEASE_PUBKEY=' .env || printf 'DAEMON_RELEASE_PUBKEY=%s\n' "5245716675785d7bf5dc36d783fa5c690c705da4d542082f398e3e79543d06f8" >> .env

say "Pulling $IMAGE"
if ! docker pull "$IMAGE"; then
    # An image built on this server (docker build) has nowhere to come from.
    docker image inspect "$IMAGE" >/dev/null 2>&1 || fail "couldn't pull $IMAGE, and there's no image by that name here."
    warn "couldn't pull $IMAGE: using the one already here."
fi
# The compose file and the serveros-panel command come from the image, so
# they always match the version that runs.
image_compose="$(docker run --rm --entrypoint cat "$IMAGE" /app/docker/self-hosted/compose.yaml)"
# An image from before tunnels (1.1.0 and older) has no cloudflared and can't
# serve the panel to one, so it would only fail to start.
if [ "$TUNNEL" = 1 ] && ! printf '%s' "$image_compose" | grep -q cloudflared; then
    fail "$IMAGE doesn't support Cloudflare Tunnels (1.2.0 and later do). Run the script again with --version=1.2.0, or a newer one."
fi
printf '%s\n' "$image_compose" > compose.yaml
docker run --rm --entrypoint cat "$IMAGE" /app/docker/self-hosted/serveros-panel > /usr/local/bin/serveros-panel
chmod 755 /usr/local/bin/serveros-panel
# Only a compose file that gives Redis the password can have one.
if grep -q requirepass compose.yaml && ! grep -q '^REDIS_PASSWORD=.' .env; then
    sed -i '/^REDIS_PASSWORD=/d' .env
    printf 'REDIS_PASSWORD=%s\n' "$(random 24)" >> .env
fi

say "Starting ServerOS"
docker compose up -d --remove-orphans

tries=0
until docker compose exec -T web curl -fsS http://127.0.0.1:8081/up >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 150 ] || fail "the panel didn't start in 5 minutes. See what happened: serveros-panel logs web"
    sleep 2
done
docker compose exec -T web php artisan daemon:sync-releases --no-interaction >/dev/null 2>&1 \
    || warn "couldn't fetch the daemon releases from serveros.com yet. The panel tries again every hour; until then, machines can't connect."

# Every night (serveros-panel nightly): a new certificate for the gateway
# a month before its own runs out, then a backup of the database and
# storage, the last 14 kept. Written each time, so running the script
# again brings the schedule up to date.
if grep -q '^    schedule) schedule ;;' /usr/local/bin/serveros-panel; then
    SERVEROS_DIR="$DIR" /usr/local/bin/serveros-panel schedule
elif [ -d /etc/cron.d ]; then
    cat > /etc/cron.d/serveros-panel <<EOF
# ServerOS: the gateway's certificate checked, then a backup into $DIR/backups (serveros-panel nightly).
SERVEROS_DIR=$DIR
17 3 * * * root /usr/local/bin/serveros-panel nightly >> /var/log/serveros-panel-nightly.log 2>&1
EOF
fi

# Until it's set up, the web installer asks for this.
code="$(docker compose exec -T web php artisan install:code --plain 2>/dev/null | tr -d '\r' | tail -n1 || true)"
if [ -n "$code" ]; then
    next="Open https://$DOMAIN to set it up, and enter this setup code:

      $code

  (see it again with: serveros-panel setup-code)"
else
    next="Open https://$DOMAIN and sign in."
fi

cat <<EOF

  ServerOS is running.

  $next

$(if [ "$TUNNEL" = 1 ]; then cat <<TUNNEL_EOF
  In Cloudflare (Networking > Tunnels > your tunnel > Routes), add a
  published application for each of these, with the service URL
  http://web:80:

      $DOMAIN
      *.$DOMAIN

  Machines connect to $GATEWAY_HOST:8443 directly: give it an A record
  pointing at ${public_ip:-this server}, DNS only (grey cloud), and keep
  port 8443 open. Ports 80 and 443 can stay closed.
TUNNEL_EOF
else cat <<DIRECT_EOF
  Make sure DNS has both $DOMAIN and *.$DOMAIN pointing here, and that
  ports 80, 443 and 8443 are open to the internet.
DIRECT_EOF
fi)

  Settings:   $DIR/.env (email, for one), then: cd $DIR && docker compose up -d
  Upgrades:   serveros-panel upgrade
  Backups:    serveros-panel backup (every night at 03:17 too, into $DIR/backups)
  Gateway:    its certificate is renewed a month before it runs out (checked every night)
  Help:       serveros-panel help

EOF
