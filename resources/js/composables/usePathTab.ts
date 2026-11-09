import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A page's open tab, kept as the last part of its address (/machines/5/usage)
 * so a refresh, a shared link or the back button lands on the same one. The
 * page's route takes the tab as an optional last segment. The default tab
 * leaves it off when it is also the first, so plain links stay plain.
 */
export function usePathTab<T extends string>(
    base: string,
    values: readonly T[],
    fallback: T = values[0],
): Ref<T> {
    const current = (): string => window.location.pathname.replace(/\/+$/, '');

    const fromPath = (): T | null => {
        const path = current();

        if (!path.startsWith(`${base}/`)) return null;

        const segment = decodeURIComponent(path.slice(base.length + 1));

        return (values as readonly string[]).includes(segment)
            ? (segment as T)
            : null;
    };

    const onPage = (): boolean => current() === base || fromPath() !== null;

    const tab = ref(fromPath() ?? fallback) as Ref<T>;

    const write = (value: T): void => {
        if (!onPage()) return;

        const plain = value === fallback && fallback === values[0];
        const path = plain ? base : `${base}/${value}`;

        if (window.location.pathname === path) return;

        history.replaceState(
            history.state,
            '',
            path + window.location.search + window.location.hash,
        );
    };

    const sync = (): void => {
        if (onPage()) tab.value = fromPath() ?? fallback;
    };

    watch(tab, write, { immediate: true });

    // Inertia visits on the same page (filters, reloads) can go to the bare
    // address, which drops the tab.
    let stop: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('popstate', sync);
        stop = router.on('finish', () => write(tab.value));
    });

    onBeforeUnmount(() => {
        window.removeEventListener('popstate', sync);
        stop?.();
    });

    return tab;
}
