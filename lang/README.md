# Translations

ServerOS speaks English, Spanish, German and Hindi (`config/app.php` → `locales`).
English is the source: every other language lives in `lang/{code}.json`,
keyed by the English text. A string with no translation shows in English.

## Writing a translatable string

- **Vue (the panel):** `{{ $t('Machines') }}` in templates, `trans('Machines')`
  (from `laravel-vue-i18n`) in scripts.
- **React (the marketing site):** `const { t } = useLaravelReactI18n();` then
  `t('Pricing')`.
- **PHP (toasts, validation, emails):** `__('Machines')`.

Placeholders use Laravel's `:name` form: `$t('Ask :name about ServerOS', { name })`.
Keep keys as whole sentences, so translators see the full thought.

## Adding the translations

Add the English key with its translation to `lang/es.json`, `lang/de.json` and
`lang/hi.json` (alphabetical). Spanish uses "tú" and German "du", like the
site's casual English. Hindi uses "आप" and writes everyday tech words the way
Indian developers say them (सर्वर, ऐप, डिप्लॉय), keeping product names, code
and `:placeholders` in English. Machine drafts are fine to start; have a native
speaker check the pages people see most.

Laravel's own messages (validation, passwords, sign-in) come from
`laravel-lang`: `lang/{code}/*.php` and its keys in the JSON files. Update
them with `php artisan lang:update`.

## How the language is chosen

`SetLocale`: the signed-in person's choice (`users.locale`), then the
`locale` cookie, then the browser's languages, then English. People switch in
the account menu (panel) or the footer (site), which posts to `/locale`.

Legal pages, the docs and the MCP tool descriptions stay in English.
