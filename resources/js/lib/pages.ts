import type { DefineComponent } from 'vue';

type PageModule = { default: DefineComponent };
type PageLoader = () => Promise<PageModule>;

/** The panel's own pages. */
const own = import.meta.glob<PageModule>('../pages/**/*.vue');

/**
 * Pages from the private packages (panel-ee, serveros.com), which Composer
 * installs under vendor/serveroshq. They're named like the panel's
 * own (workspace/settings/Sso is resources/js/pages/workspace/settings/Sso.vue
 * in either), so moving a page into a package doesn't change the controller
 * that renders it or the layout it gets. A build without them finds none.
 */
const fromPackages = import.meta.glob<PageModule>(
    '../../../vendor/serveroshq/*/resources/js/pages/**/*.vue',
);

const PACKAGE_PAGE = /\/resources\/js\/pages\/(.+)\.vue$/;

/** Every page by name; the panel's own win if a package repeats one. */
const pages: Record<string, PageLoader> = {};

for (const [path, load] of Object.entries(fromPackages)) {
    const name = PACKAGE_PAGE.exec(path)?.[1];

    if (name) {
        pages[name] = load;
    }
}

for (const [path, load] of Object.entries(own)) {
    pages[path.slice('../pages/'.length, -'.vue'.length)] = load;
}

/** Inertia's page lookup for the panel (app.ts). */
export async function resolvePanelPage(name: string): Promise<DefineComponent> {
    const load = pages[name];

    if (!load) {
        throw new Error(`Page not found: ${name}`);
    }

    return (await load()).default;
}
