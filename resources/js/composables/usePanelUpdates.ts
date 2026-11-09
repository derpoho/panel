import { trans } from 'laravel-vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { toast } from '@/lib/toast';

/** How often an open, visible tab asks which build is live. */
const EVERY = 60_000;

/**
 * Offers a reload once a new release of the panel is live. Inertia already
 * reloads on the next click; this is for a tab left open, so it doesn't sit
 * on an old build. Asks only while the tab is visible, and offers once.
 *
 * A page's background refresh (a service page polling, say) finds out
 * first: the panel answers it 409 with the new version, and Inertia
 * leaves async requests be rather than reloading under someone. That's
 * the moment to offer, instead of polling into 409s until the next check.
 */
export function usePanelUpdates(): void {
    const page = usePage();
    let timer: ReturnType<typeof setInterval> | undefined;
    let offered = false;

    async function check(): Promise<void> {
        if (offered || document.hidden || !page.version) return;
        try {
            const response = await fetch('/panel-version', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const { version } = (await response.json()) as {
                version?: string | null;
            };
            if (!version || version === page.version) return;
        } catch {
            return;
        }

        offer();
    }

    function offer(): void {
        if (offered) return;
        offered = true;
        clearInterval(timer);
        toast.info(trans('ServerOS has been updated'), {
            description: trans('Reload to get the newest version.'),
            duration: Infinity,
            action: {
                label: trans('Reload'),
                onClick: () => window.location.reload(),
            },
        });
    }

    function onVisible(): void {
        if (!document.hidden) void check();
    }

    let stopListening: (() => void) | undefined;

    onMounted(() => {
        timer = setInterval(() => void check(), EVERY);
        document.addEventListener('visibilitychange', onVisible);
        stopListening = router.on('location', (event) => {
            if (event.detail.versionChange) offer();
        });
    });

    onBeforeUnmount(() => {
        stopListening?.();
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });
}
