import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

export type UpgradeLink = { href: string; label: string };

/**
 * Where the dashboard's upgrade, "choose a plan" and plan limit prompts
 * go: the upgrade prop a package supplies (serveros.com: Billing, at
 * /billing). Null when none does, and then those prompts show no link.
 */
export function useUpgradeLink(): ComputedRef<UpgradeLink | null> {
    const page = usePage();

    return computed(() => page.props.upgrade ?? null);
}
