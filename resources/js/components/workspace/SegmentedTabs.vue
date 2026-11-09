<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';

export type SegmentedTab = {
    label: string;
    href: string;
    current?: boolean;
    /** Leaves the workspace, e.g. account security: shows an arrow. */
    external?: boolean;
};

type Props = {
    tabs: SegmentedTab[];
    label: string;
};

defineProps<Props>();
</script>

<template>
    <nav class="max-w-full overflow-x-auto" :aria-label="label">
        <div class="ds-segments">
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="tab.href"
                :aria-current="tab.current ? 'page' : undefined"
                class="shrink-0 gap-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
            >
                {{ tab.label }}
                <ArrowUpRight
                    v-if="tab.external"
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
            </Link>
        </div>
    </nav>
</template>
