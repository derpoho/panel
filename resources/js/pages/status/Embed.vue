<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { maintenanceWhen, timeAgo } from '@/components/status/status';
import type {
    ComponentStatus,
    StatusComponent,
    StatusReport,
} from '@/components/status/status';

/**
 * A status page as a widget for someone else's site, in an iframe: a card
 * with each service, or a single line. Links open the full page in a new
 * tab. Tells the parent page its height, so the embed snippet's script can
 * size the iframe to fit. Refreshes itself every minute.
 */
const props = defineProps<{
    status: StatusReport;
    view: 'card' | 'bar';
    theme: 'light' | 'dark' | 'auto';
    pageUrl: string;
    poweredByUrl: string;
}>();

const label: Record<ComponentStatus, string> = {
    operational: trans('Operational'),
    degraded: trans('Degraded'),
    down: trans('Down'),
    unknown: trans('Not reporting'),
};

const rank: Record<ComponentStatus, number> = {
    operational: 0,
    unknown: 1,
    degraded: 2,
    down: 3,
};

function worst(components: StatusComponent[]): ComponentStatus {
    return components.reduce<ComponentStatus>(
        (acc, c) => (rank[c.status] > rank[acc] ? c.status : acc),
        'operational',
    );
}

/** At most this many rows; past it, each section becomes one row. */
const MAX_ROWS = 8;

type Row = {
    key: string;
    label: string;
    status: ComponentStatus;
    components: StatusComponent[];
};

const rows = computed<Row[]>(() => {
    const all = props.status.sections.flatMap((s) => s.components);
    if (all.length <= MAX_ROWS)
        return all.map((c) => ({
            key: c.key,
            label: c.label,
            status: c.status,
            components: [c],
        }));

    return props.status.sections.map((s, i) => ({
        key: s.name ?? `section-${i}`,
        label: s.name ?? trans('Other'),
        status: worst(s.components),
        components: s.components,
    }));
});

/* The last 30 days, as on the status page: each day the worst of a row's services. */
const DAYS = 30;
const offset = computed(() => Math.max(0, props.status.days.length - DAYS));

function bars(row: Row): (number | null)[] | null {
    if (!row.components.some((c) => c.uptime)) return null;

    return props.status.days.slice(offset.value).map((_, i) => {
        const values = row.components
            .map((c) => c.uptime?.days[offset.value + i] ?? null)
            .filter((v): v is number => v !== null);

        return values.length ? Math.min(...values) : null;
    });
}

function uptime(values: (number | null)[] | null): string {
    const known = (values ?? []).filter((v): v is number => v !== null);
    if (!known.length) return '';
    const value = known.reduce((sum, v) => sum + v, 0) / known.length;

    return `${value >= 99.995 ? '100' : value.toFixed(2).replace(/\.?0+$/, '')}%`;
}

function tone(value: number | null): string {
    if (value === null) return 'is-empty';
    if (value >= 99.9) return 'is-up';
    if (value >= 95) return 'is-degraded';

    return 'is-down';
}

const shown = computed(() => rows.value.slice(0, MAX_ROWS));
const more = computed(() => rows.value.length - shown.value.length);

const now = ref(Date.now());
const updated = computed(() => {
    void now.value;

    return timeAgo(props.status.checkedAt);
});

const root = ref<HTMLElement | null>(null);
let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;
let observer: ResizeObserver | undefined;

function postHeight(): void {
    if (!root.value || window.parent === window) return;
    window.parent.postMessage(
        {
            type: 'serveros:status-height',
            height: Math.ceil(root.value.getBoundingClientRect().height),
        },
        '*',
    );
}

onMounted(() => {
    // The widget picks its own theme, whatever the panel's appearance.
    document.documentElement.classList.remove('dark');
    tick = setInterval(() => (now.value = Date.now()), 15000);
    reload = setInterval(() => router.reload({ only: ['status'] }), 60000);
    observer = new ResizeObserver(postHeight);
    if (root.value) observer.observe(root.value);
    postHeight();
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
    observer?.disconnect();
});
</script>

<template>
    <Head :title="status.title" />

    <div
        ref="root"
        class="embed font-ui antialiased"
        :class="`theme-${theme}`"
        :data-view="view"
    >
        <!-- One line, for a footer or a sidebar -->
        <a
            v-if="view === 'bar'"
            :href="pageUrl"
            target="_blank"
            rel="noopener"
            class="bar"
            :aria-label="
                $t(':title: :label. Open the status page', {
                    title: String(status.title),
                    label: String(status.overall.label),
                })
            "
        >
            <span class="dot" :class="`is-${status.overall.status}`" />
            <span class="bar-label">{{ status.overall.label }}</span>
            <span class="bar-title">{{ status.title }}</span>
            <ArrowUpRight class="arrow" :stroke-width="2" aria-hidden="true" />
        </a>

        <section
            v-else
            class="card"
            :class="`is-${status.overall.status}`"
            :aria-label="status.title"
        >
            <header class="head">
                <span class="dot" :class="`is-${status.overall.status}`" />
                <h1 class="title">{{ status.overall.label }}</h1>
                <span class="head-name">{{ status.title }}</span>
            </header>

            <p v-if="status.notice" class="notice">
                {{ status.notice.text }}
            </p>
            <p v-else-if="status.upcoming?.length" class="notice">
                {{
                    $t('Maintenance planned: :when.', {
                        when: maintenanceWhen(
                            status.upcoming[0].startsAt,
                            status.upcoming[0].endsAt,
                        ),
                    })
                }}
            </p>

            <ul v-if="shown.length" class="rows">
                <li v-for="row in shown" :key="row.key" class="row">
                    <div class="row-line">
                        <span class="dot" :class="`is-${row.status}`" />
                        <span class="row-label">{{ row.label }}</span>
                        <span class="row-word" :class="`is-${row.status}`">{{
                            label[row.status]
                        }}</span>
                        <span class="row-uptime">{{ uptime(bars(row)) }}</span>
                    </div>
                    <div v-if="bars(row)" class="days" aria-hidden="true">
                        <span
                            v-for="(value, i) in bars(row)"
                            :key="i"
                            :class="tone(value)"
                        />
                    </div>
                </li>
                <li v-if="more > 0" class="row more">
                    {{
                        $t('And :more more on the status page', {
                            more: String(more ?? ''),
                        })
                    }}
                </li>
            </ul>
            <p v-else class="empty">{{ $t('Nothing is listed yet.') }}</p>

            <footer class="foot">
                <span>{{ $t('Updated :updated', { updated }) }}</span>
                <span class="foot-links">
                    <a :href="pageUrl" target="_blank" rel="noopener">{{
                        $t('Status page')
                    }}</a>
                    <a :href="poweredByUrl" target="_blank" rel="noopener">{{
                        $t('ServerOS')
                    }}</a>
                </span>
            </footer>
        </section>
    </div>
</template>

<style scoped>
/* The status page's palette (status/Show.vue). Fixed rather than the
   panel's tokens: this sits on other people's sites. */
.embed {
    --bg: #ffffff;
    --fg: #18181b;
    --strong: #27272a;
    --muted: #71717a;
    --edge: #e4e4e7;
    --line: #ececee;
    --hover: #fafafa;
    --up: #1fa382;
    --up-head: #e8f6f1;
    --degraded: #f59e0b;
    --degraded-head: #fef3e2;
    --down: #e5484d;
    --down-head: #fdecec;
    --unknown: #a1a1aa;
    --unknown-head: #f4f4f5;
    --empty: #e4e4e7;
    color: var(--fg);
    font-size: 14px;
    line-height: 20px;
}
.embed.theme-dark {
    --bg: #161618;
    --fg: #f7f7f8;
    --strong: #e4e4e7;
    --muted: #787882;
    --edge: #39393f;
    --line: #29292f;
    --hover: #1b1b1e;
    --up-head: #0e493a;
    --degraded-head: #4a3207;
    --down-head: #4c1d1f;
    --unknown: #6b6b75;
    --unknown-head: #26262b;
    --empty: #29292f;
}
@media (prefers-color-scheme: dark) {
    .embed.theme-auto {
        --bg: #161618;
        --fg: #f7f7f8;
        --strong: #e4e4e7;
        --muted: #787882;
        --edge: #39393f;
        --line: #29292f;
        --hover: #1b1b1e;
        --up-head: #0e493a;
        --degraded-head: #4a3207;
        --down-head: #4c1d1f;
        --unknown: #6b6b75;
        --unknown-head: #26262b;
        --empty: #29292f;
    }
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    flex-shrink: 0;
}
.dot.is-operational {
    background: var(--up);
}
.dot.is-degraded {
    background: var(--degraded);
}
.dot.is-down {
    background: var(--down);
}
.dot.is-unknown {
    background: var(--unknown);
}

a {
    color: inherit;
    text-decoration: none;
}
a:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
    border-radius: 4px;
}

.bar {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 40px;
    padding: 0 12px;
    background: var(--bg);
    border: 1px solid var(--edge);
    /* Matches the radius the snippet gives the iframe, which clips it. */
    border-radius: 8px;
    min-width: 0;
}
.bar:hover {
    background: var(--hover);
}
.bar-label {
    font-weight: 500;
    white-space: nowrap;
}
.bar-title {
    color: var(--muted);
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.arrow {
    width: 14px;
    height: 14px;
    margin-left: auto;
    color: var(--muted);
    flex-shrink: 0;
}

/* The card: edged in the overall status's colour, like the banner. */
.card {
    background: var(--bg);
    border: 1px solid var(--edge);
    border-radius: 10px;
    overflow: hidden;
}
.card.is-operational {
    border-color: var(--up);
}
.card.is-degraded {
    border-color: var(--degraded);
}
.card.is-down {
    border-color: var(--down);
}
.head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
}
.card.is-operational .head {
    background: var(--up-head);
}
.card.is-degraded .head {
    background: var(--degraded-head);
}
.card.is-down .head {
    background: var(--down-head);
}
.card.is-unknown .head {
    background: var(--unknown-head);
}
.title {
    font-size: 15px;
    font-weight: 500;
    letter-spacing: -0.01em;
    white-space: nowrap;
}
.head-name {
    margin-left: auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--muted);
    font-size: 13px;
}
.notice {
    padding: 10px 16px;
    border-top: 1px solid var(--line);
    font-size: 13px;
    white-space: pre-line;
}
.rows {
    border-top: 1px solid var(--line);
}
.row {
    padding: 10px 16px;
    border-bottom: 0.5px solid var(--line);
}
.row-line {
    display: flex;
    align-items: center;
    gap: 8px;
}
.row-label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 500;
    color: var(--strong);
}
.row-word {
    flex-shrink: 0;
    font-size: 13px;
}
.row-word.is-operational {
    color: var(--up);
}
.row-word.is-degraded {
    color: var(--degraded);
}
.row-word.is-down {
    color: var(--down);
}
.row-word.is-unknown {
    color: var(--muted);
}
.row-uptime {
    margin-left: auto;
    flex-shrink: 0;
    color: var(--muted);
    font-size: 13px;
}
.days {
    display: grid;
    grid-template-columns: repeat(30, minmax(0, 1fr));
    gap: 2px;
    height: 10px;
    margin-top: 6px;
}
.days > span {
    border-radius: 1px;
}
.days > .is-up {
    background: var(--up);
}
.days > .is-degraded {
    background: var(--degraded);
}
.days > .is-down {
    background: var(--down);
}
.days > .is-empty {
    background: var(--empty);
}
.more {
    color: var(--muted);
    font-size: 13px;
}
.empty {
    border-top: 1px solid var(--line);
    padding: 16px;
    color: var(--muted);
    font-size: 13px;
}
.foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 16px;
    font-size: 12px;
    line-height: 16px;
    color: var(--muted);
}
.foot-links {
    display: flex;
    gap: 12px;
}
.foot-links a:hover {
    color: var(--fg);
}
</style>
