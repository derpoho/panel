<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    cpu: number;
    /**
     * In the dashboard: follow the light / dark theme. Off on the landing
     * page, which is always dark.
     */
    themed?: boolean;
};

const props = defineProps<Props>();

const BAR_COUNT = 20;
const BRIGHT_TAIL = 3;

const isHot = computed(() => props.cpu >= 80);

const barColor = computed(() => {
    if (props.themed) {
        if (props.cpu >= 80) {
            return 'bg-[#EF4444] dark:bg-[#F87171]';
        }

        return props.cpu >= 60
            ? 'bg-[#F59E0B] dark:bg-[#FBBF24]'
            : 'bg-[#3B7BFF] dark:bg-[#60A5FA]';
    }

    if (props.cpu >= 80) {
        return 'bg-[#F87171]';
    }

    return props.cpu >= 60 ? 'bg-[#FBBF24]' : 'bg-[#60A5FA]';
});

const emptyBar = computed(() =>
    props.themed ? 'bg-[#E7E7E4] dark:bg-[#222222]' : 'bg-[#2A2A28]',
);

const valueClass = computed(() => {
    if (props.themed) {
        return isHot.value
            ? 'font-medium text-(--ds-text)'
            : 'text-(--ds-text-secondary)';
    }

    return isHot.value ? 'font-medium text-[#F2F2F0]' : 'text-[#B5B3AD]';
});

const bars = computed(() => {
    const filled = Math.round((props.cpu / 100) * BAR_COUNT);

    return Array.from({ length: BAR_COUNT }, (_, index) => {
        if (index >= filled) {
            return emptyBar.value;
        }

        return index >= filled - BRIGHT_TAIL
            ? barColor.value
            : `${barColor.value} opacity-55`;
    });
});
</script>

<template>
    <!-- In a narrow column the bars clip from the right, so the figure
         never spills into the next column. -->
    <div class="flex min-w-0 items-center gap-2.5">
        <div
            class="flex min-w-0 items-center gap-[1.5px] overflow-hidden"
            aria-hidden="true"
        >
            <span
                v-for="(bar, index) in bars"
                :key="index"
                class="h-3 w-1 shrink-0"
                :class="bar"
            />
        </div>
        <span
            class="w-8.5 shrink-0 text-right font-ui-mono text-xs/4"
            :class="valueClass"
        >
            {{ cpu }}%
        </span>
    </div>
</template>
