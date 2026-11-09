import { I18n, i18nVue } from 'laravel-vue-i18n';
import type { App } from 'vue';

/**
 * The panel's translations: Laravel's lang/{code}.json, keyed by the
 * English text, so `$t('Machines')` in a template (or `trans()` in a
 * script) is English until a language has its own string. The marketing
 * site reads the same files.
 *
 * Only the page's own language is downloaded, as its own chunk, and
 * English (the keys themselves) needs none: every language bundled
 * together comes to some 370 KB of JavaScript on every panel page. The
 * top-level await below holds back every module that translates until the
 * lines are in, because the build points each panel import of
 * 'laravel-vue-i18n' at this file (vite.config.ts), which re-exports the
 * package once its language is loaded. So pages still render in the right
 * language straight away, with no flash of English, and lists of labels
 * built when their file loads (tabs, filters) get them too.
 */
const files = import.meta.glob<{ default: Record<string, string> }>(
    '../../../lang/*.json',
);

/** The page's language, as the server set it on <html lang>. */
function pageLocale(): string {
    return typeof document === 'undefined'
        ? 'en'
        : document.documentElement.lang.split('-')[0] || 'en';
}

const locale = pageLocale();

// English if the file can't be fetched, rather than no page at all.
const lines: Record<string, string> =
    locale === 'en'
        ? {}
        : ((
              await files[`../../../lang/${locale}.json`]?.().catch(
                  () => undefined,
              )
          )?.default ?? {});

/**
 * The page's language from memory; any other one (the library never asks
 * for one: changing language reloads the page) is fetched.
 */
const resolve = (lang: string) =>
    lang === locale ? lines : (files[`../../../lang/${lang}.json`]?.() ?? {});

/*
 * Set up the shared translator as soon as this module loads, before any
 * page or layout does, with the lines already in hand: in the browser the
 * library would otherwise load them asynchronously.
 */
I18n.getSharedInstance({
    lang: locale,
    fallbackLang: 'en',
    resolve,
}).loadLanguage(locale);

// The rest of the package, for everything the build points here.
export * from 'laravel-vue-i18n';

export function installI18n(app: App, locale: string): void {
    app.use(i18nVue, { lang: locale, fallbackLang: 'en', resolve });
}
