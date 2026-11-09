# Self-hosting ServerOS

> **Not available yet.** There's no published image (`ghcr.io/serveroshq/panel`) or one-line install script yet. Until there is, the steps below work with an image you build yourself (see [Building the image](#building-the-image)).

You can run the ServerOS panel on your own server, with Docker. It's the same panel as serveros.com, without the billing: every workspace on your install is active, with no plan, trial or card. You pay for the server; ServerOS costs nothing.

The features that are paid on serveros.com (single sign-on, SCIM, custom roles, your own domains, your own log storage and the fleet status page) aren't in the free edition. They'll come with a license.

## What you need

- A Linux server you can reach from the internet, with 2 CPUs, 4 GB of memory and 20 GB of disk to start with. Any distribution Docker runs on is fine.
- A domain for the panel, like `panel.example.com`, and access to its DNS.
- Ports 80 and 443 open (the panel, with free Let's Encrypt certificates) and 8443 (where your machines connect).

The panel answers on the domain, and each workspace on a subdomain of it (`acme.panel.example.com`). So before you install, add two DNS records pointing at the server:

| Type | Name                  | Value                |
| ---- | --------------------- | -------------------- |
| A    | `panel.example.com`   | the server's address |
| A    | `*.panel.example.com` | the server's address |

(Add AAAA records too if the server has IPv6.) Certificates are made for each name the first time someone visits it, so there's nothing to set up for them.

## Installing

Installing has two parts: putting ServerOS on the server, then setting it up in your browser with the web installer. Until it's set up, every page of the panel opens the installer, and the installer asks first for a **setup code**: a one-time code only someone who can get into the server can see. Once you finish, the installer is gone for good and the code stops working.

### With Docker (recommended)

On the server, as root, from a checkout of the repository. Until the image is published, build it on the server first (see [Building the image](#building-the-image)) and point the script at it with `--image` and `--version` (any name and tag you built it as):

```sh
git clone https://github.com/serveroshq/panel.git && cd panel
docker build -f docker/self-hosted/Dockerfile -t serveros/panel:dev .
sudo bash docker/self-hosted/install.sh --domain=panel.example.com --email=you@example.com --image=serveros/panel --version=dev
```

Once there's a published image, the last line alone is enough.

The script installs Docker if it isn't there, writes the settings to `/opt/serveros/.env` (with new keys and passwords), starts ServerOS and waits for it to answer. It takes a few minutes the first time. Its last lines show the setup code.

Then open `https://panel.example.com`, enter the code, and go through the steps:

1. **Server check.** Docker has everything, so it's all green.
2. **Database.** The install brings its own PostgreSQL, already set up, so this one is done for you.
3. **Address.** Set by the install (`--domain`). The installer checks that it and `*.panel.example.com` point at the server.
4. **Email.** Your SMTP server, with a test email before it's saved. You can skip it and come back to it later in `/opt/serveros/.env` (see [Email](#email)).
5. **Your account and first workspace.** You're the owner of the install. Your account doesn't need its email checked, so you can sign in before email is set up.
6. **Finish.** You land in your workspace, on a short welcome: what you run, what your machines collect, and the command to connect your first one. There's no plan step.

### On a plain server

You'll need PHP 8.4 (with the bcmath, ctype, curl, dom, fileinfo, intl, mbstring, openssl, pdo, tokenizer, xml and zip extensions, and pdo_pgsql for PostgreSQL), Composer, Node 22, a web server (nginx with PHP-FPM, or Caddy) and a database: PostgreSQL is best, MySQL, MariaDB and SQLite work too.

1. Put the code in place and build it, as the web server's user:

    ```sh
    git clone https://github.com/serveroshq/panel.git /var/www/serveros && cd /var/www/serveros
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    cp .env.example .env
    sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/' .env
    ```

    A checkout is the self-hosted edition unless `.env` says otherwise (`SERVEROS_EDITION=self-hosted` is the default).

2. Let PHP write `storage`, `bootstrap/cache` and `.env` (the installer saves its settings there):

    ```sh
    chown -R www-data:www-data storage bootstrap/cache .env
    ```

3. Point the web server at `/var/www/serveros/public`, for `panel.example.com` and `*.panel.example.com`, with HTTPS. With Caddy:

    ```
    panel.example.com, *.panel.example.com {
        root * /var/www/serveros/public
        php_fastcgi unix//run/php/php8.4-fpm.sock
        file_server
    }
    ```

    (A wildcard certificate needs Caddy's DNS challenge; with nginx, use certbot the same way.)

4. Make an empty database and a user for it, for example on PostgreSQL:

    ```sh
    sudo -u postgres createuser --pwprompt serveros
    sudo -u postgres createdb --owner=serveros serveros
    ```

5. Keep a queue worker running (a systemd service running `php artisan queue:work --tries=3` in `/var/www/serveros`), and add the scheduler to the web server user's crontab:

    ```
    * * * * * cd /var/www/serveros && php artisan schedule:run >> /dev/null 2>&1
    ```

6. Get the setup code, as the web server's user:

    ```sh
    sudo -u www-data php artisan install:code
    ```

7. Open `https://panel.example.com`, enter the code and go through the steps. The server check says what's missing, with the fix. In the database step you pick PostgreSQL, MySQL or MariaDB, or SQLite, the installer tries the connection, saves it to `.env` and sets up the tables, with a bar as it goes. The address step saves `APP_URL` and checks it points here. Email and your account are as with Docker.

If there's no `APP_KEY` in `.env`, the installer makes one first. It only ever changes its own settings in `.env` (the database, the address and email), keeping every other line as it was, and leaves the file readable by its owner only. Run `php artisan` as the web server's user from then on.

### Getting the code again

The code is the same until you finish. To see it again:

```sh
serveros-panel setup-code                   # Docker
sudo -u www-data php artisan install:code   # a plain server, in the panel's folder
```

The web container's log shows it too, each time it starts (`serveros-panel logs web`). `--new` makes a new code, and anyone who had entered the old one has to enter the new one. Five wrong codes from one address and it waits ten minutes before trying again.

The code is kept in `storage/app/private/install`, as a hash to check against and a copy encrypted with `APP_KEY` so the commands above can show it. Whether the install is set up is kept in the database. Finishing deletes the code.

Installs set up before the web installer existed carry on as they are, and an install with accounts already counts as set up.

Anyone who can open the panel can make an account and their own workspace, as on serveros.com. Invite your team from Settings › Members.

## What runs

Everything is in `/opt/serveros`: `compose.yaml` and `.env`. One image (`ghcr.io/serveroshq/panel`) runs every part of the panel, each in its own container:

| Container   | What it does                                                                    |
| ----------- | ------------------------------------------------------------------------------- |
| `web`       | The panel, and HTTPS for it (Caddy). Runs the database migrations as it starts. |
| `queue`     | Background work: jobs for your machines, emails, alerts.                        |
| `scheduler` | Monitors, backups, workflows, updates and the rest of what runs on a timer.     |
| `gateway`   | Where your machines' daemons connect (port 8443).                               |
| `db`        | PostgreSQL 16.                                                                  |
| `redis`     | Redis, for the cache and the queue.                                             |

The data is in Docker volumes: `serveros_database` (PostgreSQL), `serveros_storage` (uploads, and the key your machines' certificates are signed with), `serveros_redis` and `serveros_caddy-data` (the HTTPS certificates).

## Connecting machines

Connect machines from Machines › Connect machine, as on serveros.com. The command it gives you downloads the daemon from releases.serveros.com and connects it to your panel at `panel.example.com:8443`. Each machine needs to reach that port, and your panel's domain on 443.

The panel signs each machine's certificate with its own key, made the first time it starts and kept in storage. **Keep backups of it**: without it, your machines can't reconnect, and have to be connected again.

## Email

Until you set up email, the panel only writes emails to its log (`serveros-panel logs queue`), so invitations, alerts and password resets don't arrive. Your owner account doesn't need one to get in. The web installer can set it up, with a test email first. Later, add your SMTP server to `/opt/serveros/.env`:

```sh
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=you@example.com
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=serveros@example.com
MAIL_FROM_NAME=ServerOS
```

Then apply it:

```sh
cd /opt/serveros && docker compose up -d
```

Any other setting in `config/` that reads an environment variable can go in `.env` the same way.

## What your install sends

Once a day, at a minute picked from its install id, your install sends serveros.com an anonymous ping, so we know how many installs there are and which versions they run. It's a small JSON POST to `https://serveros.com/api/installs/ping`, and this is all of it:

| Field             | What it is                                                                                                                                      |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `install_id`      | A random id (a UUID) made for your install the first time it starts, kept in its database. It isn't made from anything about you or the server. |
| `version`         | The panel's version, like `1.2.0`.                                                                                                              |
| `edition`         | `self-hosted`.                                                                                                                                  |
| `licensed`        | `true` if a workspace on the install has a good ServerOS Business license, otherwise `false`.                                                   |
| `workspaces`      | How many workspaces there are.                                                                                                                  |
| `users`           | How many people have an account.                                                                                                                |
| `machines`        | How many machines are connected.                                                                                                                |
| `active_machines` | How many of them were seen in the last 7 days.                                                                                                  |

Nothing else: no names, emails, domains, hostnames, IP addresses or workspace names. serveros.com doesn't keep the address the ping comes from, or its headers. If it can't be sent, nothing happens: it gives up after a few seconds, writes a line to the log and tries again the next day.

To see exactly what your install would send, without sending it:

```sh
serveros-panel artisan install:ping --show
```

`serveros-panel artisan install:ping` sends it now.

**To turn it off**, add this to `/opt/serveros/.env`, then `cd /opt/serveros && docker compose up -d`:

```sh
SERVEROS_TELEMETRY=false
```

On a plain server, add the same line to the panel's `.env` (and run `php artisan config:cache` again if you cache the config). With it off, nothing is sent, and `install:ping` won't send it either. If you activate a license key, serveros.com's license server still knows that key is in use, since activating and the daily license check-in go there too.

## Upgrading

```sh
serveros-panel upgrade
```

That backs up first, pulls the newest image, and restarts on it. The database migrations run as the panel starts. `serveros-panel upgrade 1.2.0` moves to a version of your choosing (it's kept in `.env` as `SERVEROS_IMAGE`, so a later `serveros-panel upgrade` stays on it until you choose another).

Running the install command again brings it up to date too, keeping your settings, but without the backup first.

On a plain server, back up first (below), then as the web server's user in the panel's folder:

```sh
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

## Every night

At 03:17 the install's cron (`/etc/cron.d/serveros-panel`) runs `serveros-panel nightly`, which logs to `/var/log/serveros-panel-nightly.log`. It does two things, each even if the other fails:

- **The gateway's certificate.** Your machines check the gateway against a certificate the panel makes for it, good for a year. Once it has less than 30 days left, a new one is made and the gateway restarts onto it, so machines reconnect within a few seconds. `serveros-panel gateway-certificate` does just this; the web container also checks as it starts.
- **A backup** (below).

If you run the compose file without the install script, run `serveros-panel nightly` (or `docker compose exec web serveros-entrypoint gateway-certificate`, then `docker compose restart gateway` when it prints `issued`) once a day yourself.

## Backups

The install backs up every night at 03:17 into `/opt/serveros/backups`, keeping the last 14. To make one now:

```sh
serveros-panel backup
```

Each backup is a folder with three files:

- `database.dump`: the whole database (`pg_dump`, so it's safe while the panel runs).
- `storage.tar.gz`: uploads and the key your machines' certificates are signed with.
- `env`: your `.env`, with `APP_KEY`, which the database's secrets can't be read without.

Copy them somewhere off the server, like any backup. To put one back (it replaces what's there now):

```sh
serveros-panel restore 20261109-031700
```

If you move to a new server, install there first with the same domain, copy the backup's `env` over `/opt/serveros/.env`, run `docker compose up -d` in `/opt/serveros`, then restore.

On a plain server, back up the same three things yourself: the database (`pg_dump` or `mysqldump`, or the SQLite file), the panel's `storage` folder, and its `.env`.

## Day to day

```sh
serveros-panel status            # what's running
serveros-panel logs web          # follow one container's logs
serveros-panel artisan about     # an artisan command in the panel
serveros-panel restart
```

## When something's wrong

- **The browser says the certificate is wrong, or the page won't load.** Check both DNS records point at the server, and that ports 80 and 443 are open: Let's Encrypt checks the name from the internet before it gives a certificate. `serveros-panel logs web` says what it tried.
- **Machines won't connect.** Port 8443 must be open, and `serveros-panel logs gateway` should show the gateway listening. The install command only works for 15 minutes: make a new one if it's older.
- **Emails don't arrive.** See [Email](#email); until it's set up, they're only in the log.

## Trying it on your own computer

For a look before you put it on a server, run it with Caddy's own certificates instead of Let's Encrypt's. In a folder with `compose.yaml` from `docker/self-hosted`, make a `.env` like this:

```sh
SERVEROS_DOMAIN=serveros.localhost
ACME_EMAIL=you@example.com
SERVEROS_IMAGE=serveros/panel:dev   # the image you built (Building the image)
CADDY_GLOBAL_OPTIONS=local_certs
GATEWAY_PANEL_CA=/data/caddy/pki/authorities/local/root.crt
APP_KEY=base64:...      # head -c 32 /dev/urandom | base64
DB_PASSWORD=...         # anything long
DAEMON_GATEWAY_SECRET=... # anything 16 characters or longer
```

Then `docker compose up -d` and open https://serveros.localhost (your browser will warn about the certificate, since it's Caddy's own). Browsers send every `*.localhost` name to your computer, so workspaces work too.

## Building the image

It's built from a checkout of this repository with `docker/self-hosted/Dockerfile`:

```sh
docker build -f docker/self-hosted/Dockerfile \
  --build-arg SERVEROS_VERSION=dev \
  -t serveros/panel:dev .
```

A checkout that requires extension packages from private repositories (see `docs/extensions.md`) also needs a Composer auth JSON that can read them, as a build secret: add `--secret id=composer_auth,env=COMPOSER_AUTH`. The packages in `REMOVE_PACKAGES` are left out of the image: by default `serveroshq/serveros.com`, which holds what only serveros.com runs (its website, Cloud and billing), so a self-hosted install has none of it. Any other package stays in, and an image with a private package's code in it is for your own use, not for publishing. The gateway is built from the daemon's repository at the tag in `DAEMON_REF`.

### Publishing it

There's no publishing workflow yet. This one, as `.github/workflows/self-hosted-image.yml` and run by hand, builds the image for amd64 and arm64 and pushes it to `ghcr.io/serveroshq/panel`, tagged with the version and, unless it's a prerelease, `latest`:

```yaml
name: Self-hosted image

on:
    workflow_dispatch:
        inputs:
            version:
                description: 'Version to publish, like 1.0.0 (no v)'
                required: true
            prerelease:
                description: 'A prerelease: not tagged latest'
                type: boolean
                default: false

permissions:
    contents: read
    packages: write

jobs:
    image:
        runs-on: ubuntu-latest
        steps:
            - name: Checkout code
              uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
              with:
                  persist-credentials: false

            - uses: docker/setup-qemu-action@99012661954931238ded8c8b007157a8430204e1 # v4.4.0
            - uses: docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4.4.1

            - uses: docker/login-action@8aa5831fb8d48d8f76594c7ddde61ae58019f550 # v4.8.0
              with:
                  registry: ghcr.io
                  username: ${{ github.actor }}
                  password: ${{ secrets.GITHUB_TOKEN }}

            - name: Build and push
              uses: docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7.4.0
              env:
                  # Only for a checkout with extension packages from private repositories.
                  COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}
              with:
                  context: .
                  file: docker/self-hosted/Dockerfile
                  platforms: linux/amd64,linux/arm64
                  push: true
                  secret-envs: composer_auth=COMPOSER_AUTH
                  build-args: SERVEROS_VERSION=${{ inputs.version }}
                  tags: |
                      ghcr.io/serveroshq/panel:${{ inputs.version }}
                      ${{ !inputs.prerelease && 'ghcr.io/serveroshq/panel:latest' || '' }}
                  labels: |
                      org.opencontainers.image.title=ServerOS panel (self-hosted)
                      org.opencontainers.image.version=${{ inputs.version }}
                      org.opencontainers.image.revision=${{ github.sha }}
                  cache-from: type=gha
                  cache-to: type=gha,mode=max
```

Once an image is published, the install script's one-liner is `curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | sudo bash -s -- --domain=... --email=...`, and the note at the top of this page can go.

Every install is the self-hosted edition (`SERVEROS_EDITION=self-hosted`) unless it says otherwise, and the image sets it too. That's what turns off what only makes sense on serveros.com (`App\Support\Edition`): the trial and the welcome flow's plan step, billing, and certificates for any subdomain (only the install's own names and its workspaces get one). serveros.com sets `SERVEROS_EDITION=cloud`. What each workspace may use is `App\Support\Entitlements` (`SelfHostedEntitlements`), with the limits in `config/serveros.php` (`self_hosted`): machines and AI tool calls are unlimited, and the rest can be changed with the `SERVEROS_SELF_HOSTED_*` settings.
