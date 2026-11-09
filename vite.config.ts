import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import type { FontDefinition } from 'laravel-vite-plugin/fonts';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import {
    copyFileSync,
    existsSync,
    lstatSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    realpathSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { defineConfig, lazyPlugins, loadEnv } from 'vite-plus';
import type { Plugin } from 'vite-plus';

/**
 * A web font from resources/fonts/web: the Latin subsets Bunny Fonts serves,
 * WOFF2 only, kept in the repository (all SIL Open Font License). The
 * fonts plugin wrote every Bunny face twice, WOFF2 and WOFF, as separate
 * rules with the same descriptors, so browsers downloaded the WOFF2
 * preloads and then drew with the WOFF. Only the weights in `preload` are
 * preloaded; each layout's `@fonts` picks the families its pages use.
 */
function webFont(
    family: string,
    weights: number[],
    preload: number[] = [],
): FontDefinition {
    const alias = family.toLowerCase().replaceAll(' ', '-');

    return local(family, {
        alias,
        variants: weights.map((weight) => ({
            src: `resources/fonts/web/${alias}-${weight}.woff2`,
            weight,
        })),
        preload:
            preload.length > 0 ? preload.map((weight) => ({ weight })) : false,
        display: 'swap',
        optimizedFallbacks: false,
    });
}

/**
 * `import { siGithub } from 'simple-icons'` reads one 5 MB module, and a
 * module can't be split between chunks, so every page that drew a single
 * brand mark downloaded every mark the whole app uses (175 KB). This turns
 * each such import into a tiny module holding just that icon, so an icon
 * travels only with the code that draws it. Builds only: the dev server
 * reads the package as before.
 */
function simpleIconsPerIcon(): Plugin {
    const prefix = 'virtual:simple-icon/';
    let icons: Record<string, Record<string, unknown>> | null = null;

    return {
        name: 'serveros:simple-icons-per-icon',
        apply: 'build',
        enforce: 'pre',
        transform(code, id) {
            if (
                id.includes('/node_modules/') ||
                !/\.(?:ts|tsx|vue)$/.test(id) ||
                !code.includes('simple-icons')
            ) {
                return null;
            }

            const out = code.replace(
                /import\s*\{([^}]*)\}\s*from\s*['"]simple-icons['"];?/g,
                (statement, names: string) => {
                    const kept: string[] = [];
                    const lines: string[] = [];
                    for (const name of names.split(',').map((n) => n.trim())) {
                        const [imported, local = imported] =
                            name.split(/\s+as\s+/);
                        if (/^si[A-Z0-9]/.test(imported)) {
                            lines.push(
                                `import ${local} from '${prefix}${imported}';`,
                            );
                        } else if (name) {
                            kept.push(name);
                        }
                    }
                    if (kept.length > 0) {
                        lines.unshift(
                            `import { ${kept.join(', ')} } from 'simple-icons';`,
                        );
                    }
                    // Same line count, so line numbers in errors still match.
                    const breaks = statement.split('\n').length - 1;

                    return lines.join(' ') + '\n'.repeat(breaks);
                },
            );

            return out === code ? null : { code: out, map: null };
        },
        resolveId(id) {
            return id.startsWith(prefix) ? `\0${id}` : null;
        },
        async load(id) {
            if (!id.startsWith(`\0${prefix}`)) return null;
            icons ??= (await import('simple-icons')) as Record<
                string,
                Record<string, unknown>
            >;
            const name = id.slice(prefix.length + 1);
            const icon = icons[name];
            if (!icon) this.error(`simple-icons has no ${name}`);
            // Only what the sites read: not its whole SVG again, its source
            // or its brand guidelines, which doubled every icon's size.
            const { title, slug, hex, path } = icon;

            return `export default ${JSON.stringify({ title, slug, hex, path })};`;
        },
    };
}

/**
 * The same for the Solar icons, the marketing site's (lib/solarIcons.tsx)
 * and the panel's (lib/solarIcons.ts): each a generated module of 120
 * icons, every one of which landed on every page. Each named import
 * becomes a module holding that icon alone, made with the file's own
 * `icon()`. Builds only.
 */
function solarIconsPerIcon(options: {
    /** The generated module, from the project root. */
    source: string;
    /**
     * How the app imports it: an alias path (@/lib/solarIcons), or null
     * for relative imports only (a package's own module, which its files
     * import as ../lib/solarIcons).
     */
    specifier: string | null;
    /** The files whose imports are split. */
    importers: RegExp;
    prefix: string;
}): Plugin {
    const { source, specifier, importers, prefix } = options;
    const statements = /import\s*\{([^}]*)\}\s*from\s*(['"])([^'"]+)\2;?/g;
    let file = '';
    let bodies: Map<string, string> | null = null;
    /** Whether an import of `spec` from `importer` is the module. */
    const isSource = (spec: string, importer: string): boolean =>
        specifier !== null
            ? spec === specifier
            : spec.startsWith('.') &&
              resolve(dirname(importer), spec) === file.replace(/\.tsx?$/, '');

    return {
        name: `serveros:solar-icons-per-icon:${source}`,
        apply: 'build',
        enforce: 'pre',
        configResolved(config) {
            file = `${config.root}/${source}`;
        },
        transform(code, id) {
            if (
                id.includes('/node_modules/') ||
                !importers.test(id) ||
                !code.includes('solarIcons')
            ) {
                return null;
            }
            const importer = id.split('?')[0];

            const out = code.replace(
                statements,
                (statement, names: string, _quote: string, spec: string) => {
                    if (!isSource(spec, importer)) return statement;
                    const kept: string[] = [];
                    const lines: string[] = [];
                    for (const name of names.split(',').map((n) => n.trim())) {
                        const [imported, local = imported] =
                            name.split(/\s+as\s+/);
                        if (/^Solar[A-Z0-9]/.test(imported)) {
                            lines.push(
                                `import ${local} from '${prefix}${imported}';`,
                            );
                        } else if (name) {
                            kept.push(name);
                        }
                    }
                    if (kept.length > 0) {
                        lines.unshift(
                            `import { ${kept.join(', ')} } from '${spec}';`,
                        );
                    }
                    const breaks = statement.split('\n').length - 1;

                    return lines.join(' ') + '\n'.repeat(breaks);
                },
            );

            return out === code ? null : { code: out, map: null };
        },
        resolveId(id) {
            return id.startsWith(prefix) ? `\0${id}` : null;
        },
        async load(id) {
            if (!id.startsWith(`\0${prefix}`)) return null;
            if (!bodies) {
                const { readFile } = await import('node:fs/promises');
                const text = await readFile(file, 'utf8');
                bodies = new Map(
                    [
                        ...text.matchAll(
                            /^export const (Solar\w+) = \/\* @__PURE__ \*\/ icon\(\s*'\1',\s*('(?:[^'\\]|\\.)*')/gm,
                        ),
                    ].map((match) => [match[1], match[2]]),
                );
            }
            const name = id.slice(prefix.length + 1);
            const body = bodies.get(name);
            if (!body) this.error(`${source} has no ${name}`);

            return `import { icon } from ${JSON.stringify(file)};\nexport default /* @__PURE__ */ icon('${name}', ${body});`;
        },
    };
}

/**
 * The panel's translations (lib/i18n.ts) download only the page's language,
 * with a top-level await. Every module that translates imports trans()
 * from 'laravel-vue-i18n', so each panel import of the package is pointed
 * at lib/i18n.ts, which re-exports it once the lines are in: those modules
 * then wait for them, as the marketing site's do for its t.ts. Dev server
 * and builds alike; lib/i18n.ts itself gets the package.
 */
function panelI18n(): Plugin {
    let file = '';

    return {
        name: 'serveros:panel-i18n',
        enforce: 'pre',
        configResolved(config) {
            file = `${config.root}/resources/js/lib/i18n.ts`;
        },
        resolveId(source, importer) {
            if (source !== 'laravel-vue-i18n' || !importer) return null;
            const from = importer.split('?')[0];

            return from === file ||
                from.includes('/node_modules/') ||
                !from.includes('/resources/js/') ||
                from.includes('/resources/js/marketing/')
                ? null
                : file;
        },
    };
}

/**
 * Build settings a private package gives when this build has none of its
 * own: each vendor/serveroshq/<package>/build.env.json is {"NAME": "value"},
 * used only for names neither the environment nor .env sets. serveros.com's
 * gives its Sentry project (SENTRY_ORG and so on) and its PostHog proxy
 * (VITE_POSTHOG_HOST).
 */
const ownEnv = loadEnv('production', process.cwd(), '');
for (const name of existsSync('vendor/serveroshq')
    ? readdirSync('vendor/serveroshq')
    : []) {
    const file = `vendor/serveroshq/${name}/build.env.json`;
    if (!existsSync(file)) {
        continue;
    }
    const defaults = JSON.parse(readFileSync(file, 'utf8')) as Record<
        string,
        string
    >;
    for (const [key, value] of Object.entries(defaults)) {
        if (process.env[key] === undefined && ownEnv[key] === undefined) {
            process.env[key] = value;
        }
    }
}

/**
 * Sentry: with SENTRY_AUTH_TOKEN in .env (prod only) and the project it's
 * for (SENTRY_ORG, SENTRY_PROJECT, and SENTRY_URL for a region other than
 * sentry.io's), a build uploads its source maps so front-end errors show
 * our code, then deletes them so they are never served. The release is the
 * deploy folder's commit (<timestamp>-<sha>), the same one the server
 * reports. No token or project, no maps.
 */
const sentryEnv = loadEnv('production', process.cwd(), 'SENTRY_');
const sentryOrg = process.env.SENTRY_ORG ?? sentryEnv.SENTRY_ORG;
const sentryProject = process.env.SENTRY_PROJECT ?? sentryEnv.SENTRY_PROJECT;
const sentryUrl =
    process.env.SENTRY_URL ?? sentryEnv.SENTRY_URL ?? 'https://sentry.io/';
const sentryToken =
    sentryOrg && sentryProject
        ? (process.env.SENTRY_AUTH_TOKEN ?? sentryEnv.SENTRY_AUTH_TOKEN)
        : undefined;
const sentryRelease =
    process.env.SENTRY_RELEASE ??
    sentryEnv.SENTRY_RELEASE ??
    /^\d+-([0-9a-f]{7,40})$/.exec(basename(process.cwd()))?.[1];

// The dashboard tags its errors with it too, maps or not.
if (sentryRelease) {
    process.env.VITE_SENTRY_RELEASE ??= sentryRelease;
}

/** The private packages Composer installed, none without them. */
const packages = existsSync('vendor/serveroshq')
    ? readdirSync('vendor/serveroshq').map(
          (name) => `vendor/serveroshq/${name}`,
      )
    : [];

/**
 * Where each private package really is, for the ones Composer linked from a
 * folder next to this one (path repositories, in development). Builds that
 * installed them normally, or not at all, find none.
 */
const linkedPackages = packages
    .filter((path) => lstatSync(path).isSymbolicLink())
    .map((path) => realpathSync(path));

/**
 * A package's React site (serveros.com's website, in its
 * resources/js/marketing): its browser entry, its server renderer, and
 * the Solar icons module its pages split. A build without it has neither
 * entry, and no SSR bundle.
 */
const site = packages.find((path) =>
    existsSync(`${path}/resources/js/marketing/app.tsx`),
);
const siteSsr =
    site && existsSync(`${site}/resources/js/marketing/ssr.tsx`)
        ? `${site}/resources/js/marketing/ssr.tsx`
        : undefined;

/**
 * `@package-css 'file.css';` in app.css: that file from the package that
 * has it in its resources/css, imported right there among the panel's
 * styles, or nothing when no package does. A rule of its own rather than
 * an @import, so that tools reading app.css without this plugin (the
 * formatter's class sorting) don't stop at a file that isn't there.
 */
function packageStyles(): Plugin {
    return {
        name: 'serveros:package-css',
        enforce: 'pre',
        transform(code, id) {
            if (!id.split('?')[0].endsWith('/resources/css/app.css')) {
                return null;
            }
            const out = code.replace(
                /@package-css\s+(['"])([\w.-]+\.css)\1\s*;/g,
                (_rule, _quote: string, file: string) => {
                    const found = packages
                        .map((path) => `${path}/resources/css/${file}`)
                        .find((path) => existsSync(path));

                    return found ? `@import '../../${found}';` : '';
                },
            );

            return out === code ? null : { code: out, map: null };
        },
    };
}

/**
 * The packages' own strings (each package's lang/{code}.json),
 * merged into the panel's lang/{code}.json wherever the front end imports
 * one (lib/i18n.ts, and the site's lib/t.ts and ssr.tsx), so every page
 * gets the same lines per language that __() has, with the package's
 * loadJsonTranslationsFrom. The panel's line wins when both have a key. A
 * build without the packages reads the panel's files as they are.
 */
function packageTranslations(): Plugin {
    let langDir = '';

    return {
        name: 'serveros:package-translations',
        enforce: 'pre',
        configResolved(config) {
            langDir = `${config.root}/lang/`;
        },
        load(id) {
            const file = id.split('?')[0];
            const name = file.startsWith(langDir)
                ? file.slice(langDir.length)
                : '';
            if (!/^[a-z]{2,3}\.json$/.test(name)) return null;
            const extra = packages
                .map((path) => `${path}/lang/${name}`)
                .filter((path) => existsSync(path));
            if (extra.length === 0) return null;

            const lines = JSON.parse(readFileSync(file, 'utf8')) as Record<
                string,
                string
            >;
            for (const path of extra) {
                this.addWatchFile(path);
                const own = JSON.parse(readFileSync(path, 'utf8')) as Record<
                    string,
                    string
                >;
                for (const [key, line] of Object.entries(own)) {
                    if (!(key in lines)) lines[key] = line;
                }
            }

            return JSON.stringify(lines);
        },
    };
}

/**
 * A package's public files (its public/ folder: the site's images and
 * videos) are served from the panel's public/ at the same paths, so the
 * build copies them in, and the dev server too. Each folder they land in
 * gets a .gitignore saying so (every file in a folder that's all the
 * package's, or just its files in one the panel has files in too), so
 * they never show in the panel's git status.
 */
function packagePublicFiles(): Plugin {
    const mark = '# From a private package: vite.config.ts copies these in.';
    const ignoreOf = (dir: string) => join(dir, '.gitignore');
    const ours = (dir: string): string | null => {
        const file = ignoreOf(dir);
        if (!existsSync(file)) return null;
        const text = readFileSync(file, 'utf8');

        return text.startsWith(mark) ? text : null;
    };
    const copy = (from: string, to: string): void => {
        const whole = !existsSync(to) || ours(to) === `${mark}\n*\n`;
        mkdirSync(to, { recursive: true });
        const files: string[] = [];
        for (const entry of readdirSync(from, { withFileTypes: true })) {
            const source = join(from, entry.name);
            const target = join(to, entry.name);
            if (entry.isDirectory()) {
                copy(source, target);
            } else if (entry.isFile() && entry.name !== '.gitignore') {
                if (
                    !existsSync(target) ||
                    statSync(target).size !== statSync(source).size ||
                    statSync(target).mtimeMs < statSync(source).mtimeMs
                ) {
                    copyFileSync(source, target);
                }
                files.push(entry.name);
            }
        }
        if (whole) {
            writeFileSync(ignoreOf(to), `${mark}\n*\n`);
        } else if (
            files.length > 0 &&
            (!existsSync(ignoreOf(to)) || ours(to) !== null)
        ) {
            writeFileSync(
                ignoreOf(to),
                [
                    mark,
                    '/.gitignore',
                    ...files.sort().map((f) => `/${f}`),
                    '',
                ].join('\n'),
            );
        }
    };

    return {
        name: 'serveros:package-public-files',
        buildStart() {
            for (const path of packages) {
                if (existsSync(`${path}/public`)) {
                    copy(`${path}/public`, 'public');
                }
            }
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                ...(site ? [`${site}/resources/js/marketing/app.tsx`] : []),
            ],
            // Server rendering is for a package's React site only; the
            // panel stays client-rendered. `vp build --ssr` writes it to
            // bootstrap/ssr/ssr.js for `php artisan inertia:start-ssr`.
            ssr: siteSsr,
            refresh: true,
            fonts: [
                webFont('Instrument Sans', [400, 500, 600]),
                // The marketing headlines (font-hero, light).
                webFont('Inter Tight', [300, 400], [300]),
                webFont('Schibsted Grotesk', [400, 500]),
                webFont('Cabin', [400, 500]),
                // Both sites' UI type (font-ui).
                webFont('Mona Sans', [400, 500, 600], [400, 500]),
                webFont('Geist Mono', [400, 500]),
                webFont('Inter', [400, 500, 600]),
                // The startup page's letter: its heading and the signature.
                webFont('Newsreader', [400, 500]),
                webFont('Caveat', [500]),
            ],
        }),
        packageStyles(),
        packageTranslations(),
        simpleIconsPerIcon(),
        ...(site
            ? [
                  solarIconsPerIcon({
                      source: `${site}/resources/js/marketing/lib/solarIcons.tsx`,
                      specifier: null,
                      importers: /\.tsx?$/,
                      prefix: 'virtual:solar-icon/',
                  }),
              ]
            : []),
        solarIconsPerIcon({
            source: 'resources/js/lib/solarIcons.ts',
            specifier: '@/lib/solarIcons',
            importers: /\.(?:ts|vue)$/,
            prefix: 'virtual:panel-solar-icon/',
        }),
        panelI18n(),
        // Named, or the plugin would pick resources/js/app.ts (the Vue
        // panel) as the SSR entry, in the dev server too. None without a
        // package's site.
        inertia({ ssr: siteSsr ?? false }),
        packagePublicFiles(),
        tailwindcss(),
        // React only for a package's site; the panel stays Vue.
        react({ include: /resources\/js\/marketing\/.*\.tsx$/ }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        {
            // @vitejs/plugin-vue compiles <script lang="ts"> with the dev
            // server's whole `oxc` config, React's `jsx.refresh` included,
            // skipping plugin-react's include filter. Vue modules then call
            // $RefreshSig$, which only exists on the React pages. The oxc
            // transform plugin keeps its own copy of the options, so the
            // marketing .tsx files still get fast refresh.
            name: 'serveros:no-react-refresh-in-vue',
            apply: 'serve',
            configureServer(server) {
                const { oxc } = server.config;

                if (oxc && typeof oxc.jsx === 'object') {
                    (server.config as { oxc: typeof oxc }).oxc = {
                        ...oxc,
                        jsx: { ...oxc.jsx, refresh: false },
                    };
                }
            },
        },
        wayfinder({
            formVariants: true,
        }),
        ...(sentryToken
            ? [
                  sentryVitePlugin({
                      org: sentryOrg,
                      project: sentryProject,
                      url: sentryUrl,
                      authToken: sentryToken,
                      release: sentryRelease
                          ? { name: sentryRelease }
                          : undefined,
                      sourcemaps: {
                          filesToDeleteAfterUpload: ['public/build/**/*.map'],
                      },
                      telemetry: false,
                  }),
              ]
            : []),
        {
            // The built SSR bundle (bootstrap/ssr) carries its dependencies,
            // React and the rest, so production runs it with only Node
            // installed and no node_modules. Build only: the dev server's
            // SSR loads them from node_modules, which needs them external.
            name: 'serveros:self-contained-ssr-bundle',
            apply: 'build',
            config: (_config, env) =>
                env.isSsrBuild ? { ssr: { noExternal: true } } : undefined,
        },
    ]),
    // Pre-bundled up front, so the dev server never meets them mid-session
    // (a new dependency there answers 504 until it restarts).
    // One React for the whole site: laravel-react-i18n asks for React 18,
    // package.json overrides it, and this keeps Vite from bundling two.
    resolve: {
        dedupe: ['react', 'react-dom'],
        // A linked package's pages import vue, @inertiajs/vue3 and @/...
        // from the panel: keep their vendor/serveroshq path, so imports
        // resolve from here rather than from the folder the link points to.
        ...(linkedPackages.length > 0 ? { preserveSymlinks: true } : {}),
    },
    optimizeDeps: {
        include: ['laravel-react-i18n', 'laravel-vue-i18n'],
    },
    build: {
        // Maps only when they go to Sentry, and never linked from the files.
        sourcemap: sentryToken ? 'hidden' : false,
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        {
                            // The panel's icons, one module each (Solar, see
                            // above, and Lucide's own files): grouped by the
                            // pages that draw them, with the smallest groups
                            // folded into their nearest neighbour, rather
                            // than a request for every few icons.
                            name: 'icons',
                            test: /virtual:panel-solar-icon|@lucide[\\/]vue[\\/]dist[\\/]esm[\\/]icons/,
                            entriesAware: true,
                            entriesAwareMergeThreshold: 8000,
                        },
                    ],
                },
            },
        },
    },
    server: {
        // Composer links the private packages (vendor/serveroshq) from
        // folders next to this one in development; the dev server may serve
        // their pages from there.
        ...(linkedPackages.length > 0
            ? { fs: { allow: [process.cwd(), ...linkedPackages] } }
            : {}),
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            // Built files, and the files packages copy in.
            'public/**',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
