<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
} from 'vue';

type Stage = 'installing' | 'setting-up' | 'done';

type Props = {
    /** Loop Installing → Setting up → Done, for showing it off. */
    cycle?: boolean;
    /**
     * In the dashboard: follow the light / dark theme. Off on the landing
     * page, which is always dark.
     */
    themed?: boolean;
};

const { cycle = false, themed = false } = defineProps<Props>();

const stages: Record<
    Stage,
    {
        label: string;
        hold: number;
        badge: string;
        text: string;
        spinner: string;
    }
> = {
    installing: {
        label: trans('Installing'),
        hold: 2600,
        badge: 'border-[#6B5220] bg-[#3A2C12]',
        text: 'text-[#FCD34D]',
        spinner: 'border-[#FBBF24]/25 border-t-[#FBBF24]',
    },
    'setting-up': {
        label: trans('Setting up'),
        hold: 2600,
        badge: 'border-[#2F4C80] bg-[#1B2A45]',
        text: 'text-[#93BBFD]',
        spinner: 'border-[#60A5FA]/25 border-t-[#60A5FA]',
    },
    done: {
        label: trans('Done'),
        hold: 2200,
        badge: 'border-[#1F5136] bg-[#12291C]',
        text: 'text-[#6EE7B7]',
        spinner: '',
    },
};

/**
 * Dashboard colours: light by default, the landing colours under `dark:`.
 * Spelled out in full so Tailwind can see every class.
 */
const themedStages: Record<
    Stage,
    { badge: string; text: string; spinner: string }
> = {
    installing: {
        badge: 'border-[#F3D58F] bg-[#FEF6E0] dark:border-[#6B5220] dark:bg-[#3A2C12]',
        text: 'text-[#92400E] dark:text-[#FCD34D]',
        spinner:
            'border-[#D97706]/25 border-t-[#D97706] dark:border-[#FBBF24]/25 dark:border-t-[#FBBF24]',
    },
    'setting-up': {
        badge: 'border-[#C3D5FB] bg-[#EEF4FF] dark:border-[#2F4C80] dark:bg-[#1B2A45]',
        text: 'text-[#1A4FBF] dark:text-[#93BBFD]',
        spinner:
            'border-[#1F5FE0]/25 border-t-[#1F5FE0] dark:border-[#60A5FA]/25 dark:border-t-[#60A5FA]',
    },
    done: {
        badge: 'border-[#A9DDBB] bg-[#E9F8EE] dark:border-[#1F5136] dark:bg-[#12291C]',
        text: 'text-[#166534] dark:text-[#6EE7B7]',
        spinner: '',
    },
};

function colours(key: Stage): { badge: string; text: string; spinner: string } {
    return themed ? themedStages[key] : stages[key];
}

const landingShadow =
    'shadow-[inset_0_1px_0_rgb(255_255_255/0.08),inset_0_-1px_0_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]';
const themedShadow =
    'shadow-[0_1px_2px_rgb(0_0_0/0.05)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.08),inset_0_-1px_0_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]';

const order: Stage[] = ['installing', 'setting-up', 'done'];

const stage = ref<Stage>('installing');
const current = computed(() => stages[stage.value]);

/**
 * The pill eases between the widths of its labels. It renders at its natural
 * width on the server; once mounted, the width is measured and pinned so
 * changes can transition.
 */
const content = useTemplateRef<HTMLElement>('content');
const width = ref<number | null>(null);

function measure(): void {
    if (content.value) {
        width.value = content.value.offsetWidth;
    }
}

let timer: ReturnType<typeof setTimeout> | undefined;

function advance(): void {
    stage.value = order[
        (order.indexOf(stage.value) + 1) % order.length
    ] as Stage;
    void nextTick(measure);
    timer = setTimeout(advance, current.value.hold);
}

onMounted(() => {
    measure();

    if (
        cycle &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        timer = setTimeout(advance, current.value.hold);
    }
});

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span
        class="relative inline-flex overflow-hidden rounded-[7px] border bg-origin-border transition-[width,background-color,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
        :class="[
            colours(stage).badge,
            themed ? themedShadow : landingShadow,
            { 'installing-badge': stage !== 'done' },
        ]"
        :style="width === null ? undefined : { width: `${width + 2}px` }"
        role="status"
    >
        <span
            ref="content"
            class="flex shrink-0 items-center gap-1.5 py-0.75 pr-2.25 pl-2 whitespace-nowrap"
        >
            <!-- Spinner and check share one slot and blur-fade between each other. -->
            <span class="relative size-2.5 shrink-0" aria-hidden="true">
                <span
                    class="absolute inset-0 animate-spin rounded-full border-[1.5px] transition-[opacity,filter,scale,border-color] duration-500 ease-out motion-reduce:animate-none motion-reduce:transition-none"
                    :class="[
                        stage === 'done'
                            ? 'scale-50 opacity-0 blur-[2px]'
                            : 'scale-100 opacity-100 blur-none',
                        stage === 'done'
                            ? colours('setting-up').spinner
                            : colours(stage).spinner,
                    ]"
                />
                <Check
                    class="absolute -inset-px size-3 transition-[opacity,filter,scale] duration-500 ease-out motion-reduce:transition-none"
                    :class="[
                        colours('done').text,
                        stage === 'done'
                            ? 'scale-100 opacity-100 blur-none'
                            : 'scale-50 opacity-0 blur-[2px]',
                    ]"
                    :stroke-width="3"
                />
            </span>

            <!--
                Every label is always rendered in the same spot. The active one
                sits in the flow (so the pill takes its width); the others are
                laid over it, so the old and new words blur-fade through each
                other while the pill eases to the new width.
            -->
            <span class="relative">
                <span
                    v-for="key in order"
                    :key="key"
                    class="block text-xs/4 font-medium transition-[opacity,filter,translate] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                    :class="[
                        colours(key).text,
                        key === stage
                            ? 'relative translate-y-0 opacity-100 blur-none'
                            : 'absolute top-0 left-0 translate-y-0.5 opacity-0 blur-[3px]',
                    ]"
                    :aria-hidden="key !== stage"
                >
                    {{ stages[key].label }}
                </span>
            </span>
        </span>
    </span>
</template>
