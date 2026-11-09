import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { WorkspaceAccess, WorkspaceSummary } from '@/types/workspace';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /** The root domain, where the landing page lives. */
            appUrl: string;
            statusUrl: string | null;
            /**
             * Who runs this install, for the footers: its company, where its
             * terms and privacy policy are (a path or an address), and where
             * the panel's code is. All null on an install that sets none.
             */
            brand: {
                company: string | null;
                terms: string | null;
                privacy: string | null;
                source: string | null;
            };
            /** The docs' front page: this install's docs site, or serveros.com's. */
            docsUrl: string;
            /** The feedback board: this install's, or serveros.com's. */
            feedbackUrl: string;
            /** The promo code in the site and trial banners, while one is running. */
            promo: { code: string; offer: string } | null;
            /**
             * A paying workspace's credit off its first Cloud server (£3), for
             * people who can order one. Shared by the serveros.com package, so
             * absent without it.
             */
            cloudCredit?: string | null;
            /**
             * Where the dashboard's upgrade and "choose a plan" prompts go,
             * on workspace pages: a package's (serveros.com: Billing), or
             * null for no link (App\Support\Extensions\UpgradeLink).
             */
            upgrade?: { href: string; label: string } | null;
            auth: Auth;
            /** Workspaces the signed-in user belongs to; empty for guests. */
            workspaces: WorkspaceSummary[];
            /** Role and permissions in the current workspace, on workspace pages. */
            workspaceAccess: WorkspaceAccess | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
