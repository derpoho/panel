<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
import { jobStatus } from '@/lib/jobStatus';
import type { Tone as JobTone } from '@/lib/jobStatus';

/**
 * A daemon job's status, in the hero preview's badge style: blue while
 * queued, amber with the current step while it runs, green when done, red
 * when it fails. The label blur-swaps and the pill eases to the new width.
 */
const props = defineProps<{
    state: string;
    phase?: string | null;
    progress?: number | null;
}>();

type Tone = JobTone;

const status = computed(() =>
    jobStatus(props.state, props.phase, props.progress),
);

/** Light by default, the hero's dark colours under `dark:`. */
const tones: Record<Tone, { badge: string; text: string; spinner: string }> = {
    blue: {
        badge: 'border-[#C3D5FB] bg-[#EEF4FF] dark:border-[#2F4C80] dark:bg-[#1B2A45]',
        text: 'text-[#1A4FBF] dark:text-[#93BBFD]',
        spinner:
            'border-[#1F5FE0]/25 border-t-[#1F5FE0] dark:border-[#60A5FA]/25 dark:border-t-[#60A5FA]',
    },
    amber: {
        badge: 'border-[#F3D58F] bg-[#FEF6E0] dark:border-[#6B5220] dark:bg-[#3A2C12]',
        text: 'text-[#92400E] dark:text-[#FCD34D]',
        spinner:
            'border-[#D97706]/25 border-t-[#D97706] dark:border-[#FBBF24]/25 dark:border-t-[#FBBF24]',
    },
    green: {
        badge: 'border-[#A9DDBB] bg-[#E9F8EE] dark:border-[#1F5136] dark:bg-[#12291C]',
        text: 'text-[#166534] dark:text-[#6EE7B7]',
        spinner: '',
    },
    red: {
        badge: 'border-[#F5C2C2] bg-[#FEF0F0] dark:border-[#7A2E2E] dark:bg-[#3A1717]',
        text: 'text-[#B42318] dark:text-[#FCA5A5]',
        spinner: '',
    },
    neutral: {
        badge: 'border-(--ds-border-strong) bg-(--ds-field)',
        text: 'text-(--ds-text-secondary)',
        spinner: '',
    },
};

const colours = computed(() => tones[status.value.tone]);

/*
 * The outgoing label stays laid over the new one while both blur-fade, and
 * the pill eases between their widths.
 */
const shown = ref(status.value.label);
const leaving = ref<string | null>(null);
const content = useTemplateRef<HTMLElement>('content');
const width = ref<number | null>(null);

function measure(): void {
    if (content.value) {
        width.value = content.value.offsetWidth;
    }
}

onMounted(measure);

watch(
    () => status.value.label,
    (next, previous) => {
        leaving.value = previous;
        shown.value = next;
        void nextTick(measure);
        setTimeout(() => {
            if (leaving.value === previous) {
                leaving.value = null;
            }
        }, 500);
    },
);

const shadow =
    'shadow-[0_1px_2px_rgb(0_0_0/0.05)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.08),inset_0_-1px_0_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]';
</script>

<template>
    <span
        class="relative inline-flex overflow-hidden rounded-[7px] border bg-origin-border transition-[width,background-color,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
        :class="[colours.badge, shadow, { 'installing-badge': status.busy }]"
        :style="width === null ? undefined : { width: `${width + 2}px` }"
        role="status"
    >
        <span
            ref="content"
            class="flex shrink-0 items-center gap-1.5 py-0.75 pr-2.25 pl-2 whitespace-nowrap"
        >
            <span class="relative size-2.5 shrink-0" aria-hidden="true">
                <span
                    class="absolute inset-0 animate-spin rounded-full border-[1.5px] transition-[opacity,filter,scale] duration-500 ease-out motion-reduce:animate-none motion-reduce:transition-none"
                    :class="[
                        status.busy
                            ? 'scale-100 opacity-100 blur-none'
                            : 'scale-50 opacity-0 blur-[2px]',
                        status.busy ? colours.spinner : tones.amber.spinner,
                    ]"
                />
                <Check
                    v-if="status.tone === 'green'"
                    class="mi-pop absolute -inset-px size-3"
                    :class="colours.text"
                    :stroke-width="3"
                />
                <X
                    v-else-if="
                        status.tone === 'red' || status.tone === 'neutral'
                    "
                    class="mi-pop absolute -inset-px size-3"
                    :class="colours.text"
                    :stroke-width="3"
                />
            </span>

            <span class="relative">
                <span
                    :key="shown"
                    class="block text-xs/4 font-medium tabular-nums transition-[opacity,filter,translate] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                    :class="colours.text"
                >
                    {{ shown }}
                </span>
                <span
                    v-if="leaving"
                    :key="`leaving-${leaving}`"
                    class="badge-label-leave absolute top-0 left-0 block text-xs/4 font-medium whitespace-nowrap"
                    :class="colours.text"
                    aria-hidden="true"
                >
                    {{ leaving }}
                </span>
            </span>
        </span>
    </span>
</template>
