#!/usr/bin/env bash
# Install ServerOS on your own server, with Docker, from a checkout:
#
#   sudo bash docker/self-hosted/install.sh --domain=panel.example.com --email=you@example.com
#
# Not published yet: the image (ghcr.io/serveroshq/panel) will be built
# from the public serveroshq/panel repository once it's out, and this
# script served from there. Until then, build the image yourself and pass
# it with --image and --version (docs/self-hosting.md).
#
# The panel answers on the domain and each workspace on a subdomain of it,
# so before you start point both at this server: an A (or AAAA) record for
# panel.example.com and one for *.panel.example.com. Ports 80 and 443 (the
# panel, with free Let's Encrypt certificates) and 8443 (where your
# machines connect) must be open.
#
# Options:
#   --domain=NAME      the panel's address (required)
#   --email=ADDRESS    for Let's Encrypt's expiry notices (required)
#   --dir=PATH         where it lives (default /opt/serveros)
#   --version=X.Y.Z    an image version instead of the latest
#   --image=NAME       another image (default ghcr.io/serveroshq/panel)
#
# Running it again keeps your settings and data, and brings the install up
# to date. Afterwards, serveros-panel does upgrades and backups.
set -euo pipefail

DOMAIN="${SERVEROS_DOMAIN:-}"
EMAIL="${ACME_EMAIL:-}"
DIR="${SERVEROS_DIR:-/opt/serveros}"
IMAGE_NAME="ghcr.io/serveroshq/panel"
VERSION="latest"

say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33mwarning:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#--domain=}" ;;
        --email=*) EMAIL="${arg#--email=}" ;;
        --dir=*) DIR="${arg#--dir=}" ;;
        --version=*) VERSION="${arg#--version=}"; VERSION="${VERSION#v}" ;;
        --image=*) IMAGE_NAME="${arg#--image=}" ;;
        -h|--help) sed -n '2,25p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'; exit 0 ;;
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
fi
[ -n "$DOMAIN" ] || ask DOMAIN "The panel's domain (like panel.example.com):"
[ -n "$EMAIL" ] || ask EMAIL "Your email, for Let's Encrypt:"
DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -e 's|^https\?://||' -e 's|/.*$||')"
printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$' \
    || fail "\"$DOMAIN\" isn't a domain name. Use --domain=panel.example.com"
printf '%s' "$EMAIL" | grep -q '@' || fail "give an email address with --email=you@example.com"

# Docker, with Compose.
if ! command -v docker >/dev/null 2>&1; then
    say "Installing Docker"
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 isn't installed (the docker-compose-plugin package)."
systemctl enable --now docker >/dev/null 2>&1 || true

# Ports another program already holds.
if command -v ss >/dev/null 2>&1 && [ ! -f "$DIR/compose.yaml" ]; then
    for port in 80 443 8443; do
        if ss -ltnH "sport = :$port" | grep -q .; then
            fail "port $port is already in use ($(ss -ltnpH "sport = :$port" | sed -n 's/.*users:((\"\([^\"]*\)\".*/\1/p' | head -n1)). ServerOS needs 80, 443 and 8443."
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
check_dns "$DOMAIN"
check_dns "serveros-check-$RANDOM.$DOMAIN"

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
elif [ "$VERSION" != "latest" ] || ! grep -q '^SERVEROS_IMAGE=' .env; then
    if grep -q '^SERVEROS_IMAGE=' .env; then
        sed -i "s|^SERVEROS_IMAGE=.*|SERVEROS_IMAGE=$IMAGE|" .env
    else
        printf 'SERVEROS_IMAGE=%s\n' "$IMAGE" >> .env
    fi
fi
IMAGE="$(grep -E '^SERVEROS_IMAGE=' .env | tail -n1 | cut -d= -f2-)"

say "Pulling $IMAGE"
if ! docker pull "$IMAGE"; then
    # An image built on this server (docker build) has nowhere to come from.
    docker image inspect "$IMAGE" >/dev/null 2>&1 || fail "couldn't pull $IMAGE, and there's no image by that name here."
    warn "couldn't pull $IMAGE: using the one already here."
fi
# The compose file and the serveros-panel command come from the image, so
# they always match the version that runs.
docker run --rm --entrypoint cat "$IMAGE" /app/docker/self-hosted/compose.yaml > compose.yaml
docker run --rm --entrypoint cat "$IMAGE" /app/docker/self-hosted/serveros-panel > /usr/local/bin/serveros-panel
chmod 755 /usr/local/bin/serveros-panel

say "Starting ServerOS"
docker compose up -d --remove-orphans

tries=0
until docker compose exec -T web curl -fsS http://127.0.0.1:8081/up >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 150 ] || fail "the panel didn't start in 5 minutes. See what happened: serveros-panel logs web"
    sleep 2
done

# Every night (serveros-panel nightly): a new certificate for the gateway
# a month before its own runs out, then a backup of the database and
# storage, the last 14 kept. Written each time, so running the script
# again brings the schedule up to date.
if [ -d /etc/cron.d ]; then
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

  Make sure DNS has both $DOMAIN and *.$DOMAIN pointing here, and that
  ports 80, 443 and 8443 are open to the internet.

  Settings:   $DIR/.env (email, for one), then: cd $DIR && docker compose up -d
  Upgrades:   serveros-panel upgrade
  Backups:    serveros-panel backup (every night at 03:17 too, into $DIR/backups)
  Gateway:    its certificate is renewed a month before it runs out (checked every night)
  Help:       serveros-panel help

EOF
