<script setup lang="ts">
import { computed } from 'vue';
import { planBadge, planBadgeTones } from '@/lib/workspacePlan';
import type { WorkspaceBilling } from '@/types';

type Props = {
    billing: WorkspaceBilling;
    /** Compact for breadcrumbs and cards, or slightly larger for tiles. */
    shape?: 'compact' | 'large';
};

const { billing, shape = 'compact' } = defineProps<Props>();

const badge = computed(() => planBadge(billing));
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded-[4px] border font-body font-medium tracking-[0.08em] whitespace-nowrap uppercase"
        :class="[
            planBadgeTones[badge.tone],
            shape === 'compact'
                ? 'px-1.5 py-0.5 text-[10px]/3'
                : 'px-2 py-0.75 text-[11px]/3.5',
        ]"
    >
        {{ badge.label }}
    </span>
</template>
