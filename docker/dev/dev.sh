#!/usr/bin/env bash
# Run the ServerOS panel in Docker on Linux. See docker/dev/README.md.
#
#   docker/dev/dev.sh setup        first time: .env, dependencies, database
#   docker/dev/dev.sh up           start the panel, Vite, queue and scheduler
#   docker/dev/dev.sh down         stop them
#   docker/dev/dev.sh logs [svc]   follow the logs
#   docker/dev/dev.sh shell        a shell in the container
#   docker/dev/dev.sh artisan ...  php artisan ...
#   docker/dev/dev.sh composer ... / npm ... / test ... / check
set -euo pipefail

cd "$(dirname "$0")"
root=$(cd ../.. && pwd)

export UID GID
GID=$(id -g)
compose=(docker compose -f compose.yaml)

# A one-off command in the dev image, in the project.
run() {
    "${compose[@]}" run --rm --no-deps panel "$@"
}

# Set KEY=value in .env, replacing the line (commented out or not) if there is one.
env_set() {
    local key=$1 value=$2 file="$root/.env"
    if grep -qE "^#? ?${key}=" "$file"; then
        sed -i -E "s|^#? ?${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

setup() {
    "${compose[@]}" build

    if [ ! -f "$root/.env" ]; then
        cp "$root/.env.example" "$root/.env"
        echo "Created .env from .env.example"
    fi
    # Browsers send *.localhost to 127.0.0.1 by themselves, so workspace
    # subdomains work without touching /etc/hosts.
    env_set APP_URL http://serveros.localhost:8000
    env_set APP_DOMAIN serveros.localhost
    env_set SESSION_DOMAIN .serveros.localhost

    run composer install
    grep -qE '^APP_KEY=.+' "$root/.env" || run php artisan key:generate
    [ -f "$root/database/database.sqlite" ] || touch "$root/database/database.sqlite"
    run php artisan migrate --force
    run php artisan storage:link --force
    run npm install

    echo
    echo "Set up. Start it with: docker/dev/dev.sh up"
}

case "${1:-}" in
    setup) setup ;;
    up)
        "${compose[@]}" up -d
        echo "Panel: http://serveros.localhost:8000  (logs: docker/dev/dev.sh logs)"
        ;;
    down) "${compose[@]}" down ;;
    restart) "${compose[@]}" restart ;;
    logs) shift; "${compose[@]}" logs -f "$@" ;;
    ps) "${compose[@]}" ps ;;
    build) "${compose[@]}" build --pull ;;
    shell) run bash ;;
    artisan) shift; run php artisan "$@" ;;
    composer) shift; run composer "$@" ;;
    npm) shift; run npm "$@" ;;
    test) shift; run php artisan test --compact "$@" ;;
    check) run composer run ci:check ;;
    *)
        sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
