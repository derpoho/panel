<p align="center">
  <a href="https://serveros.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset=".github/assets/logo-dark-mode.svg">
      <img alt="ServerOS" src=".github/assets/logo-light-mode.svg" width="72">
    </picture>
  </a>
</p>

<h1 align="center">ServerOS panel</h1>

<p align="center">One place to run the Linux servers you already own.</p>

<p align="center">
  <a href="LICENSE"><img alt="License: AGPL-3.0" src="https://img.shields.io/github/license/serveroshq/panel?style=flat-square&color=2563eb"></a>
  <a href="https://github.com/serveroshq/panel/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/serveroshq/panel/ci.yml?branch=main&style=flat-square&label=CI"></a>
  <a href="https://github.com/serveroshq/panel/pkgs/container/panel"><img alt="Downloads" src="https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fserveroshq%2Fpanel%2Frefs%2Fheads%2Fbadges%2Fdownloads.json&style=flat-square"></a>
  <a href=".github/CODE_OF_CONDUCT.md"><img alt="Contributor Covenant v2.1 adopted" src="https://img.shields.io/badge/Contributor%20Covenant-v2.1%20adopted-ff69b4?style=flat-square"></a>
  <a href="https://discord.gg/serveros"><img alt="Discord" src="https://img.shields.io/badge/Discord-Join%20us-5865f2?style=flat-square&logo=discord&logoColor=white"></a>
  <a href="https://www.linkedin.com/company/serveros"><img alt="Follow ServerOS on LinkedIn" src="https://img.shields.io/badge/Follow-ServerOS-0a66c2?style=flat-square&logo=linkedin&logoColor=white"></a>
</p>

<p align="center">
  <a href="https://serveros.com"><img alt="The ServerOS dashboard: machines, services, average CPU and backups, a CPU chart for the last 24 hours, and the machines list" src=".github/assets/dashboard.png" width="900"></a>
</p>

ServerOS runs the Linux servers you already own from one place. Connect a VPS from any provider with one command, then deploy apps, sites and databases, watch them, back them up and fix them, without living in a terminal. Your servers stay with your provider, on your account.

This repo is the panel, the web app you and your team work in. Each server runs [serverosd](https://github.com/serveroshq/daemon), a small daemon the panel talks to. [serveros.com](https://serveros.com) runs this same panel as a hosted service.

## What's in it

- **Machines**: connect a server with one command, see CPU, memory and disk, and install updates.
- **Services and projects**: deploy from Git or a template, with domains and HTTPS, and copy a project to a test environment before it goes live.
- **Backups, monitors and logs**: scheduled backups, uptime checks with alerts, status pages, and every service's logs in one place.
- **Workflows**: run steps on a schedule, on a push or by hand, like deploy, check and promote.
- **Teams**: members, roles, two-factor sign-in and an activity log.
- **AI and the API**: an MCP server and an API, so your own tools and assistants can work with your servers.

## Self-hosting

You need a Linux server with Docker, ports 80, 443 and 8443 open, and a domain like `panel.example.com` with two A records pointing at the server: one for `panel.example.com` and one for `*.panel.example.com`.

Then run this on the server:

```sh
curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | sudo bash -s -- --domain=panel.example.com --email=you@example.com
```

It installs Docker if it's missing, runs the official image (`ghcr.io/serveroshq/panel`) and prints a setup code. Open `https://panel.example.com`, enter it, and the installer walks you through email and your first account. `serveros-panel setup-code` shows the code again if you lose it.

Then connect your servers from Machines › Connect machine. Each one runs [serverosd](https://github.com/serveroshq/daemon), the open source daemon, which connects out to your panel on port 8443.

[docs/self-hosting.md](docs/self-hosting.md) covers the rest: building the community edition yourself, installing without Docker, upgrades, backups, connecting machines, and the anonymous daily ping (and how to turn it off).

## Editions

| Edition                                     | What's in it                                                          | License                                    |
| ------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------ |
| Community, built from this repo             | The panel                                                             | [AGPL-3.0](LICENSE), free                  |
| Official image (`ghcr.io/serveroshq/panel`) | The panel plus `serveroshq/panel-ee`, off until you add a license key | AGPL-3.0, and panel-ee under its own terms |
| [serveros.com](https://serveros.com)        | The panel, hosted for you                                             | A paid service                             |

Without a key, the official image works exactly like the community edition. A license turns on single sign-on, SCIM, custom roles, your own domains and the other paid features, with nothing to reinstall. [LICENSING.md](.github/LICENSING.md) explains what that means for you. The daemon is [AGPL-3.0](https://github.com/serveroshq/daemon/blob/main/LICENSE) too.

## Development

You need PHP 8.4, Composer, Node 22 and npm. SQLite works out of the box.

```sh
composer setup   # install, .env, key, migrate, build
composer dev     # the panel, queue worker, logs and Vite together
```

Workspaces live on subdomains, so set these in `.env` and open `http://serveros.localhost:8000`:

```dotenv
APP_URL=http://serveros.localhost:8000
APP_DOMAIN=serveros.localhost
SESSION_DOMAIN=.serveros.localhost
```

[CONTRIBUTING.md](.github/CONTRIBUTING.md) has the full setup, the tests and checks CI runs, and how pull requests are reviewed. On Linux, [docker/dev](docker/dev/README.md) runs it all in Docker.

## Help and contributing

- Docs: [docs.serveros.com](https://docs.serveros.com)
- Questions and ideas: [Discussions](https://github.com/serveroshq/panel/discussions)
- Chat: [Discord](https://discord.gg/serveros)
- Bugs: [Issues](https://github.com/serveroshq/panel/issues/new/choose)

Found a security problem? Please don't open an issue; follow [SECURITY.md](.github/SECURITY.md).

Before you open a pull request, read [CONTRIBUTING.md](.github/CONTRIBUTING.md). Everyone who takes part follows the [Code of Conduct](.github/CODE_OF_CONDUCT.md), and first-time contributors sign the [CLA](.github/CLA.md) with a comment on their pull request.

## Star history

<a href="https://www.star-history.com/#serveroshq/panel&type=date&legend=top-left">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=serveroshq/panel&type=date&legend=top-left&theme=dark">
    <img alt="Stars on serveroshq/panel over time" src="https://api.star-history.com/chart?repos=serveroshq/panel&type=date&legend=top-left" width="100%">
  </picture>
</a>

## License

The panel is licensed under the [GNU Affero General Public License v3.0](LICENSE). Copyright © 2026 ServerOS Cloud Ltd.

The ServerOS name and logo are trademarks of ServerOS Cloud Ltd and aren't covered by the AGPL. See [TRADEMARKS.md](.github/TRADEMARKS.md).
