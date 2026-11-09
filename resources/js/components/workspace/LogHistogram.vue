<script setup lang="ts">
import { computed, ref } from 'vue';

export type Histogram = {
    bucketMinutes: number;
    /** `at` is the bucket's start, Unix milliseconds. */
    buckets: { at: number; lines: number; warn: number; error: number }[];
};

/**
 * How many lines the filtered services wrote over time: one bar per
 * bucket, warnings and errors stacked on top, so a spike stands out
 * before you read a line of it.
 */
const props = defineProps<{ histogram: Histogram; searching: boolean }>();

const buckets = computed(() => props.histogram.buckets);

const totals = computed(() =>
    buckets.value.reduce(
        (sum, b) => ({
            lines: sum.lines + b.lines,
            warn: sum.warn + b.warn,
            error: sum.error + b.error,
        }),
        { lines: 0, warn: 0, error: 0 },
    ),
);

/** The top of the scale: the busiest bar, rounded up to 1, 2 or 5 × 10ⁿ. */
const scale = computed(() => {
    const max = Math.max(0, ...buckets.value.map((b) => b.lines));
    if (max <= 4) return 4;
    const power = 10 ** Math.floor(Math.log10(max));
    const step = [1, 2, 5, 10].find((s) => s * power >= max) ?? 10;

    return step * power;
});

const compact = new Intl.NumberFormat(undefined, { notation: 'compact' });
const whole = new Intl.NumberFormat();

const daily = computed(() => props.histogram.bucketMinutes >= 120);
const clockFormat = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});
const dayFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

function label(at: number): string {
    return (daily.value ? dayFormat : clockFormat).format(at);
}

/** Five or so time labels, on round times. */
const ticks = computed(() => {
    const list = buckets.value;
    if (list.length === 0) return [];
    const span = (list.length * props.histogram.bucketMinutes) / 5;
    const step =
        [1, 2, 5, 10, 15, 30, 60, 120, 180, 360, 720, 1440].find(
            (m) => m >= span,
        ) ?? 1440;
    const offset = new Date().getTimezoneOffset();

    return list.flatMap((bucket, index) => {
        const local = bucket.at / 60_000 - offset;

        const left = (index / list.length) * 100;

        // Not so near an edge that the label hangs off it.
        return local % step === 0 && left > 4 && left < 96
            ? [{ at: bucket.at, left }]
            : [];
    });
});

const hovered = ref<number | null>(null);
const hoveredBucket = computed(() =>
    hovered.value === null ? null : buckets.value[hovered.value],
);

function height(count: number): string {
    return `${(count / scale.value) * 100}%`;
}
</script>

<template>
    <div class="border-b border-(--ds-divider) px-4 pt-3 pb-2 font-ui">
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[13px]/5"
        >
            <!-- What the hovered bar holds, or the whole range -->
            <p class="text-(--ds-text-secondary) tabular-nums">
                <template v-if="hoveredBucket">
                    <span class="font-medium text-(--ds-text)">{{
                        label(hoveredBucket.at)
                    }}</span>
                    {{
                        ' · ' +
                        $t(
                            hoveredBucket.lines === 1
                                ? ':count line'
                                : ':count lines',
                            { count: whole.format(hoveredBucket.lines) },
                        )
                    }}
                </template>
                <template v-else>{{
                    $t(totals.lines === 1 ? ':count line' : ':count lines', {
                        count: whole.format(totals.lines),
                    })
                }}</template>
            </p>
            <div
                class="flex items-center gap-3 text-xs text-(--ds-text-muted) tabular-nums"
            >
                <span v-if="searching" class="text-(--ds-text-faint)">{{
                    $t('Search narrows the lines below, not the chart')
                }}</span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2 rounded-[2px] bg-(--ds-amber)" />
                    {{
                        $t('Warnings') +
                        ' ' +
                        whole.format(hoveredBucket?.warn ?? totals.warn)
                    }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2 rounded-[2px] bg-(--ds-red)" />
                    {{
                        $t('Errors') +
                        ' ' +
                        whole.format(hoveredBucket?.error ?? totals.error)
                    }}
                </span>
            </div>
        </div>

        <div class="mt-2 flex gap-2">
            <div
                class="flex h-20 w-8 shrink-0 flex-col justify-between text-right font-ui-mono text-[10px]/3 text-(--ds-text-faint) tabular-nums"
                aria-hidden="true"
            >
                <span>{{ compact.format(scale) }}</span>
                <span>{{ compact.format(scale / 2) }}</span>
                <span>0</span>
            </div>

            <div class="min-w-0 flex-1">
                <div
                    class="relative h-20"
                    role="img"
                    :aria-label="
                        $t(':count lines, :warn warnings, :error errors', {
                            count: whole.format(totals.lines),
                            warn: whole.format(totals.warn),
                            error: whole.format(totals.error),
                        })
                    "
                    @mouseleave="hovered = null"
                >
                    <div
                        class="pointer-events-none absolute inset-x-0 top-1.5 border-t border-dashed border-(--ds-border)"
                    />
                    <div
                        class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-(--ds-border)"
                    />
                    <div
                        class="pointer-events-none absolute inset-x-0 bottom-0 border-t border-(--ds-border-strong)"
                    />

                    <div class="absolute inset-0 flex items-end gap-px">
                        <div
                            v-for="(bucket, index) in buckets"
                            :key="bucket.at"
                            class="flex h-full min-w-0 flex-1 flex-col-reverse"
                            @mouseenter="hovered = index"
                        >
                            <div
                                class="flex flex-col-reverse overflow-hidden rounded-t-[2px] transition-opacity"
                                :class="
                                    hovered !== null && hovered !== index
                                        ? 'opacity-55'
                                        : ''
                                "
                                :style="{ height: height(bucket.lines) }"
                            >
                                <div class="grow bg-(--ds-blue)" />
                                <div
                                    v-if="bucket.warn"
                                    class="shrink-0 bg-(--ds-amber)"
                                    :style="{
                                        height: `${(bucket.warn / Math.max(bucket.lines, 1)) * 100}%`,
                                    }"
                                />
                                <div
                                    v-if="bucket.error"
                                    class="shrink-0 bg-(--ds-red)"
                                    :style="{
                                        height: `${(bucket.error / Math.max(bucket.lines, 1)) * 100}%`,
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="relative mt-1 h-4 font-ui-mono text-[10px]/4 text-(--ds-text-faint) tabular-nums"
                    aria-hidden="true"
                >
                    <span
                        v-for="tick in ticks"
                        :key="tick.at"
                        class="absolute -translate-x-1/2 whitespace-nowrap"
                        :style="{ left: `${tick.left}%` }"
                        >{{ label(tick.at) }}</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
