# Local test node

A fake VPS for testing ServerOS end to end on a Mac: an Ubuntu 24.04 container
running real systemd, with its own Docker (for deploys), SSH (for backup
receivers) and, once you connect it, the ServerOS daemon. You connect it to
your local panel exactly like a real server.

```
Mac
├── Herd                    https://serveros.test             the panel
├── serveros-gateway-local  gateway.serveros.test:8443        daemons connect here (mutual TLS)
└── serveros-test-node      Ubuntu + systemd + serverosd      the "VPS"
      ├── Docker            deploys run here, inside the node
      ├── Caddy :80         published on the Mac as :8088
      └── /etc/hosts        serveros.test → the Mac (fixed on every boot)
```

The node reaches the panel and gateway through Docker's `host.docker.internal`.
It trusts your Herd certificate, so `https://serveros.test` works from inside
it, and the daemon pins that same certificate when it enrols
(`SERVEROS_LOCAL_PANEL_CA`, from `docker/daemon-local/panel-ca.patch`).

## What you need

- macOS with [Herd](https://herd.laravel.com) and [Docker Desktop](https://www.docker.com/products/docker-desktop/).
- The panel running locally at `https://serveros.test` (see the main README,
  "Running it locally"), secured: `herd secure serveros`.
- GitHub access to the private `serveroshq/daemon` repo (`gh auth login`), to
  build the daemon.

## The short way: Artisan

Two local-only commands wrap the scripts below:

```bash
php artisan dev:daemon   # build.sh (skipped if already built; --rebuild forces it), setup.sh, start-gateway.sh
php artisan dev:node     # a fresh serveros-test-node, like up.sh
```

Then connect it as in [Connect it](#connect-it), using `php artisan dev:node
shell` for the shell. The other actions:

- `dev:node up <name>` starts another node, `serveros-<name>`. Only the first
  gets port 8088 for service links.
- `dev:node up --connect` skips the panel and connects the node straight away.
- `dev:node proxy` runs local-proxy.sh on the node.
- `dev:node list` shows each node and the workspace it's connected to.
- `dev:node down <name>` removes the container, its Docker volume and its
  machine in the panel.

They work on nodes started by `up.sh` too.

## One-time setup

From the project root:

```bash
docker/daemon-local/build.sh
```

Clones the daemon to `/private/tmp/serveros-daemon` (or `SERVEROS_DAEMON_SOURCE`),
applies the local CA patch and builds `serverosd` and `serveros-gateway` for your
Mac's CPU in Docker. The first build takes a few minutes. The daemon lands in
`public/releases/daemon/v0.0.0-local/`, which the install command downloads from.

```bash
docker/daemon-local/setup.sh
```

Creates the panel's daemon CA and the gateway's certificate, fills in the
`DAEMON_*` settings in `.env` (it keeps an existing CA and secret), and runs
migrations.

```bash
docker/daemon-local/start-gateway.sh
```

Starts the gateway container. Run it again after restarting Docker.

```bash
docker/test-node/up.sh
```

Copies your Herd certificate in, builds the node image and starts
`serveros-test-node`.

## Connect it

1. In the panel, go to **Machines → Connect server** and copy the command.
2. Open a shell on the node and paste the command as it is (it uses `sudo bash`,
   which is fine):

    ```bash
    docker exec -it serveros-test-node bash
    ```

3. Within a few seconds the machine shows as online in the panel, and its first
   scan lists what's running.

To open services you deploy (they listen on the node's own `127.0.0.1`), put
Caddy in front of them once:

```bash
docker/daemon-local/local-proxy.sh
```

Then any port opens at `http://<port>.localhost:8088`, for example Umami on 3000
at http://3000.localhost:8088. With `DAEMON_LOCAL_NODE_PORT=8088` in `.env`
(`setup.sh` sets it), the panel links to these addresses itself.

## Day to day

| To                                    | Run                                                                                                                                                                                                                                    |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Start the node after a Docker restart | `docker/test-node/up.sh`                                                                                                                                                                                                               |
| Start the gateway after a restart     | `docker/daemon-local/start-gateway.sh`                                                                                                                                                                                                 |
| Get a shell on the node               | `docker exec -it serveros-test-node bash`                                                                                                                                                                                              |
| Daemon status and logs                | `serverosd status`, `journalctl -u serverosd -f` (on the node)                                                                                                                                                                         |
| Ship a daemon change                  | `docker/daemon-local/build.sh`, then on the node: `curl -fsSL https://serveros.test/releases/daemon/v0.0.0-local/serverosd-v0.0.0-local-linux-$(dpkg --print-architecture) -o /usr/local/bin/serverosd && systemctl restart serverosd` |
| Throw the node away and start fresh   | `docker/test-node/up.sh --rebuild`, then delete the machine in the panel and connect again                                                                                                                                             |
| A second node                         | `SERVEROS_NODE_NAME=serveros-test-node-2 SERVEROS_NODE_PORT=8089 docker/test-node/up.sh`                                                                                                                                               |

## When it doesn't work

- **The install command can't reach `serveros.test`:** check `getent hosts
serveros.test` on the node. It should be Docker's host address. If not, run
  `systemctl restart serveros-local-hosts`.
- **Certificate errors from curl or the daemon:** your Herd certificate changed
  (for example after reinstalling Herd). Run `docker/test-node/up.sh --rebuild`.
- **The machine never comes online:** is the gateway running (`docker ps`)? Then
  check `journalctl -u serverosd -n 50` on the node.
- **"systemd was not found":** you ran the command somewhere other than this
  node. The installer needs systemd.
- **Deploys can't start containers:** the node's Docker keeps its data on the
  `serveros-test-node-docker` volume. `--rebuild` resets it.
