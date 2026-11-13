# Self-hosting ServerOS

You can run the ServerOS panel on your own server, with Docker. It's the same panel as serveros.com, without the billing: every workspace on your install is active, with no plan, trial or card. You pay for the server; ServerOS costs nothing.

## Editions

|                                                 | What's in it                                                                                  | License                                                                                                                  |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| The official image (`ghcr.io/serveroshq/panel`) | The open source panel, plus the paid features in the commercial `serveroshq/panel-ee` package | The panel is AGPL-3.0; panel-ee is under its own commercial license and runs as the free edition until you add a license |
| Built from source (`serveroshq/panel`)          | The community edition: the open source panel only, with no paid code                          | AGPL-3.0                                                                                                                 |

The install script uses the official image. Without a license key it works exactly like the community edition: the same features and limits. The paid features (single sign-on, SCIM, custom roles, your own domains, your own log storage and one status page for all your machines) turn on when you paste a key in Settings › License. There's nothing to reinstall or download.

Built from source, Settings › License says it's the community edition and how to switch: set `SERVEROS_IMAGE=ghcr.io/serveroshq/panel:latest` in `/opt/serveros/.env` (or run the install script again with `--image=ghcr.io/serveroshq/panel`), then `serveros-panel upgrade` (it backs up first; `docker compose pull && docker compose up -d` in `/opt/serveros` does the same without the backup). Your data stays. Then paste the key in the same place.

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

On the server:

```sh
curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | sudo bash -s -- --domain=panel.example.com --email=you@example.com
```

That runs the official image. Leave out `--domain` and `--email` and it asks for them. To read the script first, it's [docker/self-hosted/install.sh](../docker/self-hosted/install.sh); it does the same from a checkout (`sudo bash docker/self-hosted/install.sh --domain=… --email=…`).

To run the community edition instead, build it on the server (see [Building the community edition yourself](#building-the-community-edition-yourself)) and point the script at it with `--image` and `--version` (any name and tag you built it as):

```sh
git clone https://github.com/serveroshq/panel.git && cd panel
docker build -f docker/self-hosted/Dockerfile -t serveros/panel:dev .
sudo bash docker/self-hosted/install.sh --domain=panel.example.com --email=you@example.com --image=serveros/panel --version=dev
```

The script installs Docker if it isn't there, writes the settings to `/opt/serveros/.env` (with new keys and passwords), starts ServerOS and waits for it to answer. It takes a few minutes the first time. Its last lines show the setup code.

Then open `https://panel.example.com`, enter the code, and go through the steps:

1. **Server check.** Docker has everything, so it's all green.
2. **Database.** The install brings its own PostgreSQL, already set up, so this one is done for you.
3. **Address.** Set by the install (`--domain`). The installer checks that it and `*.panel.example.com` point at the server.
4. **Email.** Your SMTP server, with a test email before it's saved. You can skip it and come back to it later in `/opt/serveros/.env` (see [Email](#email)).
5. **Your account and first workspace.** You're the owner of the install. Your account doesn't need its email checked, so you can sign in before email is set up.
6. **Finish.** You land in your workspace, on a short welcome: what you run, what your machines collect, and the command to connect your first one. There's no plan step.

### On a plain server

This runs the code you check out, so it's the community edition: the paid features only come with the official image, which runs with Docker.

You'll need PHP 8.4 (with the bcmath, ctype, curl, dom, fileinfo, intl, mbstring, openssl, pdo, tokenizer, xml and zip extensions, and pdo_pgsql for PostgreSQL), Composer, Node 22, a web server (nginx with PHP-FPM, or Caddy) and a database: PostgreSQL is best, MySQL, MariaDB and SQLite work too.

1. Put the code in place and build it, as root or a deploy user, not the web server's user:

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

    That's all PHP needs to write. Leave the rest of the code owned by root or the deploy user, so the web server can't change it. Run updates as that user too, as in [Upgrading](#upgrading): `bootstrap/cache` belongs to the web server's user now, so Composer skips its scripts and `php artisan package:discover` runs as `www-data`.

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

### Behind a Cloudflare Tunnel

If you'd rather not open ports 80 and 443, the panel can sit behind a [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/). cloudflared runs next to the panel, carries its traffic from Cloudflare, and Cloudflare does HTTPS.

Machines are the exception. They connect to the gateway with their own certificates, which Cloudflare's proxy can't pass on, so the gateway needs a name of its own that points straight at the server, with port 8443 open to your machines.

1. In Cloudflare, open Networking › Tunnels and create a tunnel with Cloudflared. Copy the token from its install command (the long string after `--token`).
2. Add a DNS record for the gateway, DNS only (grey cloud): an A record for `gateway.example.com` with the server's address.
3. Install with the token and the gateway's name:

    ```sh
    sudo bash docker/self-hosted/install.sh --domain=panel.example.com \
        --tunnel-token=eyJhIjoi... --gateway-host=gateway.example.com
    ```

    There's no `--email` to give: Let's Encrypt isn't used.

4. In the tunnel's Routes, add a published application for `panel.example.com` and another for `*.panel.example.com`, each with the service URL `http://web:80`. Cloudflare adds their DNS records. Check the full hostname before you save: a route on the bare domain takes over whatever that domain serves now.

Then open `https://panel.example.com` and set it up as usual. The address step checks the tunnel and the gateway's name.

The script adds this to `/opt/serveros/.env`:

| Setting                                                | What it does                                                                            |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| `SERVEROS_TUNNEL=cloudflare`                           | Plain HTTP serves the panel, and the visitor's address comes from Cloudflare's headers. |
| `CLOUDFLARE_TUNNEL_TOKEN`                              | The tunnel's token, for the `cloudflared` container.                                    |
| `COMPOSE_PROFILES=tunnel`                              | Starts the `cloudflared` container.                                                     |
| `SERVEROS_GATEWAY_HOST`                                | Where machines connect, and the name on the gateway's certificate.                      |
| `HTTP_PORT`, `HTTPS_PORT`                              | `127.0.0.1:18080` and `127.0.0.1:18443`: the panel only listens on the server itself.   |
| `CADDY_GLOBAL_OPTIONS=local_certs`, `GATEWAY_PANEL_CA` | The gateway reaches the panel inside Docker with Caddy's own certificate.               |

Two things to know:

- Cloudflare's free certificate covers a domain and one level under it: `example.com` and `*.example.com`. Workspaces on `acme.panel.example.com` are two levels down, so they need Cloudflare's Advanced Certificate Manager. Using the domain itself for the panel (`example.com`, with workspaces on `acme.example.com`) avoids that.
- To move an existing install behind a tunnel, run the script again with `--tunnel-token` and `--gateway-host`. Machines connected before then use the old gateway name, so connect them again from Machines › Connect machine.

### Your machines' apps behind a tunnel

A machine can serve its apps through a Cloudflare Tunnel too, so it needs no open ports for them. Open the machine's Settings, find Cloudflare Tunnel, and paste a tunnel's token (or its whole install command). ServerOS installs cloudflared on the machine from Cloudflare's package repository and runs it as the `serveros-tunnel` service, with the token kept root-only in `/etc/serveros/tunnel.env`. The panel doesn't keep the token once the machine has it.

The machine's proxy then answers each app's domain over plain HTTP, since Cloudflare does HTTPS. In the tunnel's Routes, add a published application for each app's domain, or one wildcard like `*.example.com`, with the service URL `http://localhost:80`. Deploy apps with their domain as usual. Taking the tunnel off stops cloudflared, and the apps get their own certificates again.

This needs the daemon at v0.1.1-pre.37 or later on the machine, with Caddy as its proxy (the default) or nginx.

### Getting the code again

The code is the same until you finish. To see it again:

```sh
serveros-panel setup-code                   # Docker
sudo -u www-data php artisan install:code   # a plain server, in the panel's folder
```

The web container's log shows it too, each time it starts (`serveros-panel logs web`). `--new` makes a new code, and anyone who had entered the old one has to enter the new one. Five wrong codes from one address and it waits ten minutes before trying again.

The code is kept in `storage/app/private/install`, as a hash to check against and a copy encrypted with `APP_KEY` so the commands above can show it. Whether the install is set up is kept in the database. Finishing deletes the code.

Installs set up before the web installer existed carry on as they are, and an install with accounts already counts as set up.

The panel is invite only once its first account exists: invite your team from Settings › Members, and they sign up with the email the invite went to. To let anyone who can open the panel make an account and their own workspace, as on serveros.com, set `SERVEROS_SIGN_UPS=open` in `.env`.

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

The panel and the daemon go together. Each server you manage runs `serverosd`, the open source ServerOS daemon ([serveroshq/daemon](https://github.com/serveroshq/daemon), AGPL-3.0), and your install runs the gateway those daemons connect to: the `gateway` container on port 8443, built from the same repository.

To connect a server, open Machines › Connect machine and run the command it gives you on that server. The command downloads the daemon from releases.serveros.com, checks it against the release's checksum and ServerOS's signature before installing it, and connects it to your panel at `panel.example.com:8443`. Each machine needs to reach that port, and your panel's domain on 443. Nothing has to be open on the machine itself: the daemon connects out.

The key the signature is checked with is `DAEMON_RELEASE_PUBKEY`, which the install script sets for you. If you build and publish your own daemon releases, set `DAEMON_RELEASES_URL` and `DAEMON_RELEASE_PUBKEY` to your own. Every hour your panel fetches the newest daemon releases (see [What your install sends](#what-your-install-sends)) so it can offer your machines updates. The [daemon's repository](https://github.com/serveroshq/daemon) has its source, how to build it and how it updates itself.

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

## GitHub

Public repositories deploy without any setup. For private repositories, and to deploy when you push, your install needs a GitHub App of its own. The workspace owner sets it up once in **Settings › General › GitHub › Set up GitHub**, and every workspace on the install can use it:

1. Pick a name and whether the app belongs to your account or an organisation (the one that owns your repositories), then click **Create on GitHub**. GitHub opens with the permissions, webhook and return address filled in. Click **Create GitHub App** and you come back to the panel with the app saved.
2. Click **Connect GitHub**, install the app on your account or organisation and choose the repositories ServerOS may read. GitHub then asks you to authorize ServerOS, which confirms you manage that account.

If you'd rather make the app yourself, the same page has a step-by-step guide and a form for its App ID, Client ID, client secret, private key and webhook secret. The panel checks them with GitHub before it saves them, and keeps the secrets encrypted in its database.

The app needs Contents and Pull requests set to Read and write, Metadata read-only, the Push and Pull request events, `https://panel.example.com/github/setup` as its callback URL with **Request user authorization (OAuth) during installation** ticked, and `https://panel.example.com/api/github/webhook` as its webhook URL. GitHub has to reach that webhook URL for deploy on push.

To manage the app in `.env` instead, set `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY` (the .pem file's contents, base64 encoded) and `GITHUB_APP_WEBHOOK_SECRET`. Those win over the one saved in Settings.

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

Three other requests aren't part of the ping, and `SERVEROS_TELEMETRY=false` doesn't stop them, since updates depend on them. None sends anything about your install:

- Every hour the panel asks `https://api.github.com/repos/serveroshq/panel/releases/latest` for the newest ServerOS release, so Settings › General can say when there's one to update to. `SERVEROS_UPDATE_FEED_URL` points it somewhere else; set it empty to stop it.

- Every hour the panel asks `https://serveros.com/api/daemon/releases/latest` for the newest daemon release, so it can offer your machines updates. It's a plain GET with the channel and CPU type in the query. `DAEMON_RELEASE_FEED_URL` points it somewhere else; set it empty to stop it, and your machines won't be offered daemon updates.
- Your machines download the daemon from `https://releases.serveros.com` when they connect and when they update. `DAEMON_RELEASES_URL` changes where from.

## Upgrading

```sh
serveros-panel upgrade
```

That backs up first, pulls the newest image, and restarts on it. The database migrations run as the panel starts. `serveros-panel upgrade 1.2.0` moves to a version of your choosing (it's kept in `.env` as `SERVEROS_IMAGE`, so a later `serveros-panel upgrade` stays on it until you choose another).

You can also update from the panel. When a new release is out, Settings › General and the home page say so, with an Update button. The button shows for the install's first account, in the workspaces it owns; to let someone else update, run `serveros-panel artisan admin:grant sam@example.com`. Once you click it, the server starts the update within a minute. It runs `serveros-panel upgrade` for you, so it backs up first, and the panel is unavailable for a minute or two while it restarts. Once it's back, the workspace's machines are sent the newest daemon, unless their machine policy updates them by itself already.

The button needs the updater, which the install's cron runs every minute (`/etc/cron.d/serveros-panel`, logging to `/var/log/serveros-panel-update.log`). An install from before the updater gets it the night after its next `serveros-panel upgrade`, or right away with:

```sh
serveros-panel upgrade && serveros-panel schedule
```

Running the install command again brings it up to date too, keeping your settings, but without the backup first.

What's new and what's fixed in each version is in the [release notes](https://github.com/serveroshq/panel/releases).

On a plain server, back up first (below), then in the panel's folder, the code as the user that owns it and `php artisan` as the web server's user:

```sh
git pull
composer install --no-dev --optimize-autoloader --no-scripts
sudo -u www-data rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
sudo -u www-data php artisan package:discover
npm ci && npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart
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
SERVEROS_IMAGE=ghcr.io/serveroshq/panel:latest   # or the image you built
CADDY_GLOBAL_OPTIONS=local_certs
GATEWAY_PANEL_CA=/gateway/panel-ca.crt
APP_KEY=base64:...      # head -c 32 /dev/urandom | base64
DB_PASSWORD=...         # anything long
DAEMON_GATEWAY_SECRET=... # anything 16 characters or longer
```

Then `docker compose up -d` and open https://serveros.localhost (your browser will warn about the certificate, since it's Caddy's own). Browsers send every `*.localhost` name to your computer, so workspaces work too.

## Building the community edition yourself

From a checkout of this repository, `docker/self-hosted/Dockerfile` builds the community edition: the open source panel, AGPL-3.0 only, with no paid code.

```sh
docker build -f docker/self-hosted/Dockerfile \
  --build-arg SERVEROS_VERSION=dev \
  -t serveros/panel:dev .
```

Run it with `--image` and `--version` on the install script, or `SERVEROS_IMAGE` in `.env`. The gateway is built from the daemon's repository at the tag in `DAEMON_REF`, and the build checks it's still the commit in `DAEMON_COMMIT`.

The official image is built by ServerOS the same way, from a checkout that also requires the commercial `serveroshq/panel-ee` package, which is why it has the paid features and this one doesn't.

Every install is the self-hosted edition (`SERVEROS_EDITION=self-hosted`) unless it says otherwise, and the image sets it too. That's what turns off what only makes sense on serveros.com (`App\Support\Edition`): the trial and the welcome flow's plan step, billing, and certificates for any subdomain (only the install's own names and its workspaces get one). serveros.com sets `SERVEROS_EDITION=cloud`. What each workspace may use is `App\Support\Entitlements` (`SelfHostedEntitlements`), with the limits in `config/serveros.php` (`self_hosted`): machines and AI tool calls are unlimited, and the rest can be changed with the `SERVEROS_SELF_HOSTED_*` settings.
