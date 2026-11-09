# Contributing to the ServerOS panel

Thanks for wanting to help. This page explains how changes get into the panel, so your time isn't wasted.

## Before you write code

- **Small fixes** (typos, docs, an obvious bug with a clear fix): just open a pull request.
- **Anything bigger** (a new feature, a change in behaviour, a refactor, a new dependency): open an [issue](https://github.com/serveroshq/panel/issues/new/choose) or a [discussion](https://github.com/serveroshq/panel/discussions) first and wait for a maintainer to agree on the approach. Pull requests for big changes nobody agreed to are likely to be closed.
- **Security problems**: never in public. Follow [SECURITY.md](SECURITY.md).

Some features belong in our paid editions, which aren't in this repo (see [LICENSING.md](LICENSING.md)). If we think that's where an idea fits, we'll say so in the issue.

## The CLA

The first time you open a pull request, a bot asks you to sign our [Contributor License Agreement](CLA.md) by posting a comment. You only do this once. You keep the copyright in your work; the CLA lets us ship it in the open source panel and in our paid editions. We can't merge a pull request until everyone who made commits in it has signed.

## Pull requests

- One change per pull request. Keep it focused and small, ideally under 500 changed lines.
- No more than 3 open pull requests at a time, please.
- Fill in the template: what changed, why, and how you tested it.
- Add or update tests for what you change.
- Don't mix in unrelated formatting or renames.
- Keep CI green. If you're new here, a maintainer has to start CI on your pull request, so it can take a little while to run.
- Write plain, short user-facing copy. No em dashes in the UI.

## How review works

- A maintainer has to approve every pull request before it's merged, and new pushes reset that approval.
- Conversations have to be resolved before merge.
- We squash-merge, so your pull request becomes one commit on `main`. The pull request title becomes the commit title, so make it say what the change does.
- We may ask for changes, push small fixes to your branch, or close a pull request that doesn't fit. We'll always say why.

## Using AI tools

You can, but you're responsible for every line you submit. Read [AI_POLICY.md](AI_POLICY.md) before you do.

## Setting up the panel locally

You need PHP 8.4 with the extensions Laravel needs plus bcmath, intl, zip and pdo_sqlite (pdo_pgsql for PostgreSQL), Composer 2, Node 22 and npm.

```sh
git clone https://github.com/serveroshq/panel.git && cd panel
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Each workspace lives on its own subdomain, so point the panel at `serveros.localhost` (browsers send every `*.localhost` name to your computer) and share the login across subdomains. In `.env`:

```dotenv
APP_URL=http://serveros.localhost:8000
APP_DOMAIN=serveros.localhost
SESSION_DOMAIN=.serveros.localhost
```

The database is SQLite by default (`database/database.sqlite`). For PostgreSQL, set `DB_CONNECTION=pgsql` and `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. Then:

```sh
php artisan migrate
php artisan storage:link
composer dev
```

`composer dev` runs the panel on port 8000, a queue worker, the logs and Vite together. Open http://serveros.localhost:8000/register, make an account and you land in your workspace. Mail goes to `storage/logs/laravel.log`, so look there for invitation and verification links.

`composer setup` does the install, `.env`, key, migrations and a build in one go, if you'd rather. A fresh checkout is the self-hosted edition (`SERVEROS_EDITION=self-hosted`): every workspace is active, with no plan or trial.

On Linux, `docker/dev/README.md` runs all of this in Docker instead.

## Tests and checks

CI runs all of these on every pull request. Run the ones your change touches before you push.

```sh
php artisan test --compact     # Pest, on SQLite in memory
php artisan test --parallel    # the same, faster
vendor/bin/pint                # PHP style (vendor/bin/pint --test only checks)
composer types:check           # PHPStan
npm run check                  # frontend format and lint (vp check; npm run check:fix fixes it)
npm run types:check            # vue-tsc and tsc
npm run build                  # the frontend build
```

Migrations have to run on SQLite, PostgreSQL and MySQL. Tests never touch your own database: phpunit.xml points them at SQLite in memory.

## Code of Conduct

Everyone taking part agrees to our [Code of Conduct](CODE_OF_CONDUCT.md).
