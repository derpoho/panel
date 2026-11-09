#!/bin/sh
# What each container of the self-hosted panel runs, by its command:
#
#   web        migrations, the daemon CA and the gateway's certificate,
#              then the web server (ports 80 and 443); until the install
#              is set up, it prints the web installer's setup code
#   queue      the queue worker
#   scheduler  the scheduler (monitors, backups, workflows and the rest)
#   gateway    the gateway the daemons connect to (port 8443)
#   gateway-certificate
#              a new certificate for the gateway if its own runs out within
#              30 days; prints "issued" when it made one (serveros-panel
#              nightly then restarts the gateway onto it), "current" if not
#
# Anything else runs as given, e.g. `php artisan about`.
set -eu

log() { printf '[serveros] %s\n' "$*" >&2; }

# Storage is a volume: a new one starts empty.
prepare_storage() {
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/framework/testing storage/logs \
        bootstrap/cache
}

wait_for_database() {
    tries=0
    until php -r '
        $c = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "db", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "serveros");
        try { new PDO($c, getenv("DB_USERNAME") ?: "serveros", getenv("DB_PASSWORD") ?: ""); } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            log "the database didn't answer for 2 minutes; check the db container (docker compose logs db)"
            exit 1
        fi
        sleep 2
    done
}

# Config, routes, views and events cached for this container's settings.
optimize() {
    php artisan optimize --no-interaction >/dev/null
}

# The CA the panel signs each machine's certificate with, made once and
# kept in storage (back it up: machines can't reconnect without it), and
# the gateway's certificate from it, made again a month before it runs out.
# ISSUED is 1 once it has made a new one.
ISSUED=0
daemon_certificates() {
    if [ ! -f "${DAEMON_CA_CERT:-storage/app/private/daemon-ca.crt}" ]; then
        php artisan daemon:ca init --no-interaction
    fi

    host="${SERVEROS_DOMAIN:-}"
    [ -n "$host" ] || return 0
    cert=/gateway/gateway.crt
    if [ -f "$cert" ] && php -r '
        $c = openssl_x509_parse((string) file_get_contents($argv[1]));
        $names = (string) ($c["extensions"]["subjectAltName"] ?? "");
        exit($c !== false && $c["validTo_time_t"] > time() + 30 * 86400 && str_contains($names, "DNS:".$argv[2]) ? 0 : 1);
    ' "$cert" "$host"; then
        return 0
    fi
    log "issuing the gateway's certificate for $host"
    rm -f /gateway/gateway.crt /gateway/gateway.key /gateway/ca.crt
    php artisan daemon:ca issue-server --host="$host" --out=/gateway --no-interaction
    chmod 0640 /gateway/gateway.key
    ISSUED=1
}

role="${1:-web}"
case "$role" in
    web)
        prepare_storage
        wait_for_database
        php artisan migrate --force --no-interaction
        daemon_certificates
        php artisan storage:link --no-interaction >/dev/null 2>&1 || true
        optimize
        log "ServerOS $(cat VERSION 2>/dev/null || echo dev) is starting on https://${SERVEROS_DOMAIN:-localhost}"
        # Not set up yet: the setup code the installer asks for, in the log.
        php artisan install:code --banner --no-interaction >&2 || true
        exec frankenphp run --config /etc/caddy/Caddyfile
        ;;
    queue)
        prepare_storage
        wait_for_database
        optimize
        exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600 --no-interaction
        ;;
    scheduler)
        prepare_storage
        wait_for_database
        optimize
        # Settings read afresh each minute, so what the web installer saves
        # applies without a restart.
        php artisan config:clear --no-interaction >/dev/null
        exec php artisan schedule:work --no-interaction
        ;;
    gateway)
        tries=0
        until [ -f /gateway/gateway.crt ] && [ -f /gateway/gateway.key ]; do
            tries=$((tries + 1))
            if [ "$tries" -ge 90 ]; then
                log "no gateway certificate in /gateway yet; the web container makes it as it starts"
                exit 1
            fi
            sleep 2
        done
        export GATEWAY_CA="${GATEWAY_CA:-/gateway/ca.crt}"
        export GATEWAY_CERT="${GATEWAY_CERT:-/gateway/gateway.crt}"
        export GATEWAY_KEY="${GATEWAY_KEY:-/gateway/gateway.key}"
        export GATEWAY_SECRET="${GATEWAY_SECRET:-${DAEMON_GATEWAY_SECRET:-}}"
        # Only for a panel certificate from Caddy's own CA (local_certs).
        [ -n "${GATEWAY_PANEL_CA:-}" ] || unset GATEWAY_PANEL_CA
        exec serveros-gateway
        ;;
    gateway-certificate)
        # Logs go to stderr, so the answer is the only thing on stdout.
        daemon_certificates >&2
        if [ "$ISSUED" = 1 ]; then echo issued; else echo current; fi
        ;;
    *)
        exec "$@"
        ;;
esac
