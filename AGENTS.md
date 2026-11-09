# AGENTS.md

Read [CONTRIBUTING.md](CONTRIBUTING.md) and [AI_POLICY.md](AI_POLICY.md)
first. A person opens and owns every pull request; keep changes small and
say in the description if AI wrote a large part of it.

## The stack

- Laravel 13 on PHP 8.4, Inertia 3. The pages are Vue 3
  (`resources/js/pages`, `resources/js/components`).
- Vite+ (`vp`) builds, lints and formats the frontend. Tailwind 4.
- Pest for tests, PHPStan (Larastan, level 7), Pint.
- Machines run the ServerOS daemon ([serveroshq/daemon](https://github.com/serveroshq/daemon));
  the panel talks to it through the gateway (`app/Support/DaemonGatewayClient.php`).

## Commands

```sh
composer setup             # first time: install, .env, key, migrate, npm install, build
composer dev               # the panel, queue worker, logs and Vite together
php artisan test --compact # Pest
composer lint              # Pint (composer lint:check to only check)
composer types:check       # PHPStan
npm run check:fix          # vp check --fix: frontend format and lint (not prettier)
npm run types:check        # vue-tsc and tsc
```

CI runs all of them; run the ones your change touches before you push.

## Conventions

- **Copy is plain and short.** Apps, sites, databases; no hype. No em dashes
  in anything a user reads.
- **Collections are tables**, with a toolbar and filter pills (see
  `resources/js/pages/workspace/Machines.vue`), not card grids. Detail pages
  follow the machine page.
- **Ask the workspace, not the plan**: `$workspace->hasFeature(...)`,
  `$workspace->limitOf(...)`. Paid features live outside this repo and plug
  in through `App\Support\Extensions` (see `docs/extensions.md`); don't add
  code that names a package or a plan.
- **Vue `<script setup>`**: declare a `const` before any `watch` or
  `computed` that reads it. A block that reads a later `const` crashes at
  runtime with a TDZ error that no check catches.
- **Import every component a template uses.** A missing import passes
  vue-tsc and lint and renders nothing; check the browser console for
  "Failed to resolve component".
- **Tests**: a feature test for every behaviour you change, in
  `tests/Feature`. Tests use SQLite in memory (phpunit.xml); migrations must
  also run on PostgreSQL and MySQL.
- **Destructive artisan commands** (`migrate:fresh`, `db:wipe`) only against
  a throwaway database.
- Don't commit generated files: `resources/js/actions`, `resources/js/routes`
  and `resources/js/wayfinder` are written by Wayfinder at build time.
