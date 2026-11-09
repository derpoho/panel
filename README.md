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
  <a href="CODE_OF_CONDUCT.md"><img alt="Contributor Covenant v2.1 adopted" src="https://img.shields.io/badge/Contributor%20Covenant-v2.1%20adopted-ff69b4?style=flat-square"></a>
  <a href="https://discord.gg/serveros"><img alt="Discord" src="https://img.shields.io/badge/Discord-Join%20us-5865f2?style=flat-square&logo=discord&logoColor=white"></a>
  <a href="https://www.linkedin.com/company/serveros"><img alt="Follow ServerOS on LinkedIn" src="https://img.shields.io/badge/Follow-ServerOS-0a66c2?style=flat-square&logo=linkedin&logoColor=white"></a>
</p>

<p align="center">
  <a href="https://serveros.com"><img alt="The ServerOS dashboard: machines, average CPU, last backup and each machine's services" src=".github/assets/dashboard.png" width="900"></a>
</p>

ServerOS is one place to run the Linux servers you already own. Connect a VPS from any provider with one command, then deploy apps, sites and databases, watch them, back them up and fix them, without living in a terminal. Your servers stay with your provider, on your account.

This repo is the panel: the web app you and your team work in. It talks to [serverosd](https://github.com/serveroshq/daemon), the small daemon that runs on each of your machines. It's the same panel [serveros.com](https://serveros.com) runs.

## Editions

The core is open source under the AGPL and free to self-host. serveros.com runs it as a hosted service. A paid license adds enterprise features (single sign-on, SCIM, custom roles and more) through a separate private package. [LICENSING.md](LICENSING.md) explains what that means for you.

|                                 | Where                                                     | License                                                                               |
| ------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| The panel                       | This repo                                                 | [AGPL-3.0](LICENSE), free to self-host                                                |
| The daemon                      | [serveroshq/daemon](https://github.com/serveroshq/daemon) | [AGPL-3.0](https://github.com/serveroshq/daemon/blob/main/LICENSE), free to self-host |
| Paid features                   | A separate private package                                | Commercial, unlocked with a license key                                               |
| serveros.com and ServerOS Cloud | [serveros.com](https://serveros.com)                      | A hosted service, not part of this repo                                               |

## Self-hosting

You need a Linux server with Docker, a domain like `panel.example.com` with an A record for it and one for `*.panel.example.com`, and ports 80, 443 and 8443 open.

There's no published image yet, so build one from a checkout on the server, then install with it:

```sh
git clone https://github.com/serveroshq/panel.git && cd panel
docker build -f docker/self-hosted/Dockerfile -t serveros/panel:dev .
sudo bash docker/self-hosted/install.sh --domain=panel.example.com --email=you@example.com --image=serveros/panel --version=dev
```

The script writes its settings to `/opt/serveros/.env`, starts the panel and prints a setup code. Open `https://panel.example.com`, enter the code, and the web installer walks you through email and your first account. Lost the code? `serveros-panel setup-code` shows it again.

[docs/self-hosting.md](docs/self-hosting.md) has the rest: installing on a plain server without Docker, upgrades, backups, connecting machines and what your install sends (and how to turn it off).

## Development

You need PHP 8.4, Composer, Node 22 and npm. SQLite works out of the box.

```sh
composer setup   # composer install, .env, key, migrate, npm install, build
composer dev     # the panel, queue worker, logs and Vite together
```

[CONTRIBUTING.md](CONTRIBUTING.md) has the full setup (with the three `.env` lines that make workspace subdomains work on `serveros.localhost`) and how to run the tests and checks.

## Getting help

- Docs: [docs.serveros.com](https://docs.serveros.com)
- Questions and ideas: [Discussions](https://github.com/serveroshq/panel/discussions)
- Chat: [Discord](https://discord.gg/serveros)
- Bugs: [Issues](https://github.com/serveroshq/panel/issues/new/choose)

Found a security problem? Please don't open an issue. Follow [SECURITY.md](SECURITY.md) instead.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) first. Everyone who takes part agrees to the [Code of Conduct](CODE_OF_CONDUCT.md), and first-time contributors sign our [CLA](CLA.md) in a comment on their pull request.

## License

The panel is licensed under the [GNU Affero General Public License v3.0](LICENSE). Copyright © 2026 ServerOS Cloud Ltd.

The ServerOS name and logo are trademarks of ServerOS Cloud Ltd and aren't covered by the AGPL. See [TRADEMARKS.md](TRADEMARKS.md).
