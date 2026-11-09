<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { SolarTriangleAlert as TriangleAlert } from '@/lib/solarIcons';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';

/**
 * A new server's setup, in the row where it appears: Connecting →
 * Discovering → Securing → Done. The real stage comes from the panel; the
 * badge steps through one stage at a time and holds each for a moment, so
 * a fast setup still reads as progress. When it's done it says how the
 * scan went, then hands back to the environment badge.
 */
type Stage = 'connecting' | 'discovering' | 'securing';
type Shown = Stage | 'done';

const props = defineProps<{
    stage: Stage | null;
    findings?: number | null;
}>();

const emit = defineEmits<{ finished: [] }>();

const order: Shown[] = ['connecting', 'discovering', 'securing', 'done'];
const HOLD_MS = 2600;
const DONE_MS = 4200;

/** Where the setup really is; once it's no longer reported, it's done. */
const target = computed<Shown>(() => props.stage ?? 'done');
const shown = ref<Shown>(props.stage ?? 'connecting');
let timer: ReturnType<typeof setTimeout> | undefined;

function step(): void {
    clearTimeout(timer);
    const at = order.indexOf(shown.value);
    const goal = order.indexOf(target.value);

    if (at < goal) {
        timer = setTimeout(() => {
            shown.value = order[at + 1];
            void nextTick(measure);
            step();
        }, HOLD_MS);
    } else if (shown.value === 'done') {
        timer = setTimeout(() => emit('finished'), DONE_MS);
    }
}

watch(target, step);

const labels: Record<Shown, string> = {
    connecting: trans('Connecting'),
    discovering: trans('Discovering'),
    securing: trans('Securing'),
    done: trans('Done'),
};

const issues = computed(() =>
    shown.value === 'done' ? (props.findings ?? 0) : 0,
);

const label = computed(() =>
    issues.value
        ? trans(':issues to look at', { issues: String(issues.value) })
        : labels[shown.value],
);

const tone = computed(() => {
    if (shown.value === 'done') {
        return issues.value ? tones.amber : tones.green;
    }

    return shown.value === 'securing' ? tones.amber : tones.blue;
});

/** The hero badge colours: light by default, dark under `dark:`. */
const tones = {
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
};

/* The pill eases between its labels' widths. */
const content = useTemplateRef<HTMLElement>('content');
const width = ref<number | null>(null);

function measure(): void {
    if (content.value) {
        width.value = content.value.offsetWidth;
    }
}

onMounted(() => {
    measure();
    step();
});

onBeforeUnmount(() => clearTimeout(timer));

const shadow =
    'shadow-[0_1px_2px_rgb(0_0_0/0.05)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.08),inset_0_-1px_0_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]';
</script>

<template>
    <span
        class="relative inline-flex overflow-hidden rounded-[7px] border bg-origin-border transition-[width,background-color,border-color] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
        :class="[tone.badge, shadow, { 'installing-badge': shown !== 'done' }]"
        :style="width === null ? undefined : { width: `${width + 2}px` }"
        role="status"
        :aria-label="$t('Setup: :label', { label: String(label) })"
    >
        <span
            ref="content"
            class="flex shrink-0 items-center gap-1.5 py-0.75 pr-2.25 pl-2 whitespace-nowrap"
        >
            <span class="relative size-2.5 shrink-0" aria-hidden="true">
                <span
                    v-if="shown !== 'done'"
                    class="absolute inset-0 animate-spin rounded-full border-[1.5px] motion-reduce:animate-none"
                    :class="tone.spinner"
                />
                <TriangleAlert
                    v-else-if="issues"
                    class="mi-pop absolute -inset-px size-3"
                    :class="tone.text"
                    :stroke-width="2.5"
                />
                <Check
                    v-else
                    class="mi-pop absolute -inset-px size-3"
                    :class="tone.text"
                    :stroke-width="3"
                />
            </span>
            <Transition name="ds-step" mode="out-in">
                <span
                    :key="label"
                    class="block text-xs/4 font-medium"
                    :class="tone.text"
                >
                    {{ label }}
                </span>
            </Transition>
        </span>
    </span>
</template>
