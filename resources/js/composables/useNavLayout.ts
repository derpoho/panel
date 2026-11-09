import { router, usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { toast } from '@/lib/toast';
import { update } from '@/routes/nav-layout';

/** The dashboard's sections as tabs along the top, or down a sidebar. */
export type NavLayout = 'top' | 'sidebar';

/** Shown straight away while the choice saves, then the account's. */
const pending = ref<NavLayout | null>(null);

/**
 * How the dashboard lays out its navigation, saved on the account
 * (Settings › Appearance) so it's the same on every workspace and device.
 */
export function useNavLayout(): {
    layout: ComputedRef<NavLayout>;
    setLayout: (value: NavLayout) => void;
} {
    const page = usePage();

    const layout = computed<NavLayout>(
        () =>
            pending.value ??
            (page.props.auth?.user?.nav_layout === 'sidebar'
                ? 'sidebar'
                : 'top'),
    );

    function setLayout(value: NavLayout): void {
        if (value === layout.value) {
            return;
        }

        const previous = layout.value;

        pending.value = value;
        router.put(
            update.url(),
            { layout: value },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    toast.success(
                        value === 'sidebar'
                            ? trans('Navigation moved to the sidebar')
                            : trans('Navigation moved to the top bar'),
                        {
                            action: {
                                label: trans('Undo'),
                                onClick: () => setLayout(previous),
                            },
                        },
                    ),
                onError: () =>
                    toast.error(trans('Couldn’t change the navigation')),
                onFinish: () => (pending.value = null),
            },
        );
    }

    return { layout, setLayout };
}
