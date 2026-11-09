import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ChangelogCard } from '@/lib/changelog';

/**
 * "What's new" in the panel: the latest changelog entries (shared by
 * HandleInertiaRequests) and how many this person hasn't seen. Opening it
 * marks them all seen.
 */
export type WhatsNewEntry = ChangelogCard & { new: boolean };

const open = ref(false);
/** Which entries were new when it opened, so they stay marked while it's open. */
const fresh = ref<Set<number>>(new Set());

export function useWhatsNew() {
    const page = usePage<{
        changelog?: { unread: number; latest: WhatsNewEntry[] } | null;
    }>();

    const unread = computed(() => page.props.changelog?.unread ?? 0);
    const latest = computed(() => page.props.changelog?.latest ?? []);

    function show(): void {
        fresh.value = new Set(
            latest.value.filter((entry) => entry.new).map((entry) => entry.id),
        );
        open.value = true;
        if (unread.value > 0) {
            router.post(
                '/changelog/seen',
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['changelog'],
                },
            );
        }
    }

    return { open, fresh, unread, latest, show };
}
