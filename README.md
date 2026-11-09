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

The open source panel behind [ServerOS](https://serveros.com): one place to run the Linux servers you already own. Connect a VPS with one command, then deploy apps, sites and databases, keep an eye on them, back them up and fix them, without living in a terminal.

The panel talks to [serverosd](https://github.com/serveroshq/daemon), the small daemon that runs on each of your machines.

## Status

We're moving the panel's code into this repo now. Until it lands, this repo holds the license, the contributing rules and the security policy. Watch the repo (Watch → Custom → Releases) to hear when the first release is out.

## What's here and what isn't

| | Where | License |
| --- | --- | --- |
| The panel | This repo | [AGPL-3.0](LICENSE), free to self-host |
| The daemon | [serveroshq/daemon](https://github.com/serveroshq/daemon) | [AGPL-3.0](https://github.com/serveroshq/daemon/blob/main/LICENSE), free to self-host |
| Paid features | A separate private repo | Commercial, unlocked with a license key |
| ServerOS Cloud | [serveros.com](https://serveros.com) | A hosted service, not part of this repo |

[LICENSING.md](LICENSING.md) explains what that means for you.

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
