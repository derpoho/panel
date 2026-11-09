<script setup lang="ts">
import type { EChartsType } from 'echarts/core';
import type { echarts as LineCharts } from '@/components/machines/lineCharts';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { bridgeGaps } from '@/components/machines/chartGaps';

type Series = {
    label: string;
    color: string;
    values: (number | null)[];
    /** Each point's highest reading, drawn as a faint band above the line. */
    peaks?: (number | null)[];
};

const props = withDefaults(
    defineProps<{
        title: string;
        timestamps: number[];
        series: Series[];
        format: (value: number) => string;
        minMax?: number;
        max?: number;
    }>(),
    { minMax: 1, max: undefined },
);

const plot = ref<HTMLDivElement | null>(null);
let chart: EChartsType | null = null;
/** ECharts, loaded once the chart mounts (lineCharts.ts). */
let echarts: typeof LineCharts | null = null;
let unmounted = false;
let resizeObserver: ResizeObserver | null = null;

const top = computed(() => {
    const highest = Math.max(
        0,
        ...props.series.flatMap((item) =>
            [...item.values, ...(item.peaks ?? [])].filter(
                (value): value is number => value !== null,
            ),
        ),
    );
    const padded = Math.max(highest * 1.15, props.minMax);
    return props.max === undefined ? padded : Math.min(padded, props.max);
});

const enoughData = computed(
    () =>
        props.series.some(
            (item) => item.values.filter((value) => value !== null).length >= 2,
        ) && props.timestamps.length >= 2,
);

/** Over a day and a half, the axis shows dates rather than times. */
const multiDay = computed(
    () => (props.timestamps.at(-1) ?? 0) - (props.timestamps[0] ?? 0) > 129600,
);

function clock(ts: number): string {
    const date = new Date(ts * 1000);
    return multiDay.value
        ? date.toLocaleDateString([], { day: 'numeric', month: 'short' })
        : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function stamp(ts: number): string {
    const date = new Date(ts * 1000);
    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    return multiDay.value
        ? `${date.toLocaleDateString([], { day: 'numeric', month: 'short' })}, ${time}`
        : time;
}

const axis = computed(() => {
    const times = props.timestamps;
    return times.length < 2
        ? []
        : [times[0], times[Math.floor(times.length / 2)], times.at(-1)!].map(
              clock,
          );
});

const latest = computed(() =>
    props.series.map((item) => {
        const peaks = (item.peaks ?? []).filter(
            (value): value is number => value !== null,
        );
        return {
            label: item.label,
            color: item.color,
            value: [...item.values].reverse().find((value) => value !== null),
            peak: peaks.length ? Math.max(...peaks) : undefined,
        };
    }),
);

const gridlines = computed(() =>
    [1, 2 / 3, 1 / 3].map((fraction) => ({
        top: String((1 - fraction) * 100) + '%',
        label: props.format(top.value * fraction),
    })),
);

function escapeHtml(value: string): string {
    const entities: Record<string, string> = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    };
    return value.replace(/[&<>"']/g, (character) => entities[character]);
}

function tooltipRow(label: string, color: string, value: string): string {
    return (
        '<div style="display:flex;align-items:center;gap:8px;margin-top:5px">' +
        '<span style="width:7px;height:7px;border-radius:50%;background:' +
        color +
        '"></span>' +
        '<span style="color:#a1a1aa">' +
        escapeHtml(label) +
        '</span>' +
        '<strong style="margin-left:auto;font-weight:500">' +
        escapeHtml(value) +
        '</strong></div>'
    );
}

function tooltip(raw: unknown): string {
    const items = Array.isArray(raw) ? raw : [raw];
    const index = (items[0] as { dataIndex?: number } | undefined)?.dataIndex;
    if (index === undefined) return '';
    const ts = props.timestamps[index];
    const show = (value: number | null | undefined) =>
        typeof value === 'number' ? props.format(value) : '—';
    const rows = props.series
        .map(
            (item) =>
                tooltipRow(item.label, item.color, show(item.values[index])) +
                (item.peaks
                    ? tooltipRow(
                          `${item.label} peak`,
                          item.color + '66',
                          show(item.peaks[index]),
                      )
                    : ''),
        )
        .join('');
    return (
        '<div style="min-width:128px;font:12px/1.4 Inter,sans-serif;color:#f4f4f5">' +
        '<div style="color:#a1a1aa">' +
        escapeHtml(ts === undefined ? '' : stamp(ts)) +
        '</div>' +
        rows +
        '</div>'
    );
}

/**
 * The peak band: an invisible series at the average with the gap up to the
 * peak stacked on it, so only the space between the two is filled.
 */
function band(item: Series): object[] {
    if (!item.peaks) return [];
    const values = bridgeGaps(item.values);
    const peaks = bridgeGaps(item.peaks);
    const stack = `${item.label}-peak`;
    const point = (index: number, value: number | null) => [
        props.timestamps[index] * 1000,
        value,
    ];
    const quiet = {
        type: 'line',
        stack,
        silent: true,
        showSymbol: false,
        connectNulls: false,
        emphasis: { disabled: true },
    };

    return [
        {
            ...quiet,
            name: `${item.label} base`,
            data: values.map((value, index) => point(index, value)),
            lineStyle: { opacity: 0 },
        },
        {
            ...quiet,
            name: `${item.label} peak`,
            data: values.map((value, index) => {
                const peak = peaks[index] ?? null;
                return point(
                    index,
                    value === null || peak === null
                        ? null
                        : Math.max(0, peak - value),
                );
            }),
            lineStyle: { color: item.color, opacity: 0.35, width: 1 },
            areaStyle: { color: item.color, opacity: 0.12 },
        },
    ];
}

function draw(): void {
    const lib = echarts;
    if (!chart || !lib) return;
    const first = props.timestamps[0] ?? 0;
    const last = props.timestamps.at(-1) ?? first + 1;
    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    chart.setOption(
        {
            animation: !reduceMotion,
            animationDuration: 350,
            animationDurationUpdate: 300,
            grid: { top: 0, right: 0, bottom: 0, left: 0 },
            xAxis: {
                type: 'time',
                min: first * 1000,
                max: Math.max(first + 1, last) * 1000,
                show: false,
            },
            yAxis: {
                type: 'value',
                min: 0,
                max: top.value,
                interval: top.value / 3,
                axisLabel: { show: false },
                axisLine: { show: false },
                axisTick: { show: false },
                splitLine: {
                    show: true,
                    lineStyle: { color: '#2b2b30', type: 'dashed', width: 1 },
                },
            },
            tooltip: {
                trigger: 'axis',
                confine: true,
                backgroundColor: '#1b1b1e',
                borderColor: '#3a3a40',
                borderWidth: 1,
                padding: [8, 10],
                extraCssText:
                    'border-radius:8px;box-shadow:0 12px 24px rgba(0,0,0,.35)',
                axisPointer: {
                    type: 'line',
                    lineStyle: { color: '#71717a', width: 1 },
                },
                formatter: tooltip,
            },
            series: props.series.flatMap((item) => {
                const values = bridgeGaps(item.values);
                return [
                    ...band(item),
                    {
                        name: item.label,
                        type: 'line',
                        data: props.timestamps.map((ts, index) => [
                            ts * 1000,
                            values[index] ?? null,
                        ]),
                        connectNulls: false,
                        showSymbol: false,
                        smooth: false,
                        lineStyle: {
                            color: item.color,
                            width: 2,
                            shadowBlur: 7,
                            shadowColor: item.color + '55',
                            shadowOffsetY: 2,
                        },
                        itemStyle: {
                            decal: {
                                symbol: 'rect',
                                symbolSize: 0.7,
                                color: item.color + '24',
                                dashArrayX: [1, 4],
                                dashArrayY: [1, 4],
                                rotation: Math.PI / 4,
                            },
                        },
                        areaStyle: {
                            color: new lib.graphic.LinearGradient(0, 0, 0, 1, [
                                { offset: 0, color: item.color + '66' },
                                { offset: 0.06, color: item.color + '40' },
                                { offset: 0.55, color: item.color + '18' },
                                { offset: 1, color: item.color + '02' },
                            ]),
                        },
                        emphasis: { focus: 'series' },
                    },
                ];
            }),
        },
        // Drawn straight away: a lazy update leaves the old series on screen
        // for a frame, and hovering them then crashes inside echarts.
        { notMerge: true },
    );
}

onMounted(async () => {
    if (!plot.value) return;
    echarts = (await import('@/components/machines/lineCharts')).echarts;
    if (unmounted || !plot.value) return;
    chart = echarts.init(plot.value, undefined, { renderer: 'svg' });
    resizeObserver = new ResizeObserver(() => chart?.resize());
    resizeObserver.observe(plot.value);
    draw();
});

watch(() => [props.timestamps, props.series, props.minMax, props.max], draw, {
    deep: true,
});

onBeforeUnmount(() => {
    unmounted = true;
    resizeObserver?.disconnect();
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <section class="ds-tile flex min-w-0 flex-col gap-3 p-4">
        <div class="flex items-start justify-between gap-3">
            <h3 class="text-[13px]/5 font-medium text-(--ds-text-muted)">
                {{ title }}
            </h3>
            <div class="flex flex-wrap justify-end gap-x-3 gap-y-1">
                <span
                    v-for="item in latest"
                    :key="item.label"
                    class="flex items-center gap-1.5 text-[13px]/5 tabular-nums"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: item.color }"
                    />
                    <span
                        v-if="series.length > 1"
                        class="text-(--ds-text-faint)"
                        >{{ item.label }}</span
                    >
                    <span class="font-medium">{{
                        item.value === undefined ? '—' : format(item.value)
                    }}</span>
                    <span
                        v-if="item.peak !== undefined"
                        class="text-(--ds-text-faint)"
                        >{{
                            $t('peak :value', { value: format(item.peak) })
                        }}</span
                    >
                </span>
            </div>
        </div>
        <div class="relative h-40">
            <div ref="plot" class="absolute inset-0" aria-hidden="true" />
            <span
                v-for="line in gridlines"
                :key="line.top"
                class="pointer-events-none absolute right-0 -translate-y-full pb-0.5 text-[10px]/3 text-(--ds-text-faint) tabular-nums"
                :style="{ top: line.top }"
                >{{ line.label }}</span
            >
            <p
                v-if="!enoughData"
                class="pointer-events-none absolute inset-0 flex items-center justify-center text-[13px] text-(--ds-text-faint)"
            >
                {{ ' ' + $t('Collecting data…') + ' ' }}
            </p>
        </div>
        <div
            v-if="axis.length"
            class="flex justify-between text-[10px]/3 text-(--ds-text-faint) tabular-nums"
        >
            <span v-for="(label, index) in axis" :key="index">{{ label }}</span>
        </div>
    </section>
</template>
