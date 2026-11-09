<script setup lang="ts">
import { computed, useId } from 'vue';
import { bridgeGaps } from '@/components/machines/chartGaps';

/**
 * A small line of recent readings for a stat tile, in the current text
 * colour. It scales to its own readings so quiet changes still show; a
 * long stretch with no readings (the machine wasn't reporting) is dashed.
 */
const props = withDefaults(
    defineProps<{
        values: (number | null)[];
        /** The scale reaches at least this, so idle noise stays small. */
        floor?: number;
        /** The scale never goes past this. */
        max?: number;
    }>(),
    { floor: 0, max: undefined },
);

const points = computed(() => bridgeGaps(props.values));

const gradient = `spark-${useId()}`;

const top = computed(() => {
    const highest = Math.max(
        0,
        ...points.value.filter((value): value is number => value !== null),
    );
    const top = Math.max(highest * 1.15, props.floor, 1e-9);
    return props.max === undefined ? top : Math.min(top, props.max);
});

/** Runs of consecutive readings, as points in a 100 × 32 box. */
const runs = computed(() => {
    const step = points.value.length > 1 ? 100 / (points.value.length - 1) : 0;
    const result: [number, number][][] = [];
    let run: [number, number][] = [];
    points.value.forEach((value, index) => {
        if (value === null) {
            if (run.length) result.push(run);
            run = [];
            return;
        }
        run.push([
            index * step,
            31 - (Math.min(value, top.value) / top.value) * 29,
        ]);
    });
    if (run.length) result.push(run);
    return result;
});

const line = computed(() =>
    runs.value
        .map((run) =>
            run.length === 1
                ? `M${run[0][0]},${run[0][1]}h0.01`
                : 'M' + run.map(([x, y]) => `${x},${y}`).join('L'),
        )
        .join(''),
);

const area = computed(() =>
    runs.value
        .filter((run) => run.length > 1)
        .map(
            (run) =>
                `M${run[0][0]},32L` +
                run.map(([x, y]) => `${x},${y}`).join('L') +
                `L${run.at(-1)![0]},32Z`,
        )
        .join(''),
);

/** Faint dashes across stretches with no readings, end to start. */
const missing = computed(() =>
    runs.value
        .slice(1)
        .map((run, index) => {
            const [x1, y1] = runs.value[index].at(-1)!;
            const [x2, y2] = run[0];
            return `M${x1},${y1}L${x2},${y2}`;
        })
        .join(''),
);

const enoughData = computed(() => runs.value.some((run) => run.length > 1));
</script>

<template>
    <div class="relative size-full min-h-8">
        <svg
            v-if="enoughData"
            class="absolute inset-0 size-full overflow-visible"
            viewBox="0 0 100 32"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <defs>
                <linearGradient :id="gradient" x1="0" y1="0" x2="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="currentColor"
                        stop-opacity="0.28"
                    />
                    <stop
                        offset="1"
                        stop-color="currentColor"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>
            <path :d="area" :fill="`url(#${gradient})`" />
            <path
                :d="missing"
                fill="none"
                stroke="currentColor"
                stroke-opacity="0.35"
                stroke-width="1"
                stroke-dasharray="2 3"
                vector-effect="non-scaling-stroke"
            />
            <path
                :d="line"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                stroke-linejoin="round"
                stroke-linecap="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <p
            v-else
            class="absolute inset-0 flex items-center justify-end text-[11px] text-(--ds-text-faint)"
        >
            {{ ' ' + $t('Collecting data…') + ' ' }}
        </p>
    </div>
</template>
