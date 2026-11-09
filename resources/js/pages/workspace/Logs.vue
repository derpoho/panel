<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, Check, ChevronDown, Search } from '@lucide/vue';
import {
    SolarScrollText as ScrollText,
    SolarTextSearch as TextSearch,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import LogHistogram from '@/components/workspace/LogHistogram.vue';
import type { Histogram } from '@/components/workspace/LogHistogram.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Workspace } from '@/types';

type Level = 'error' | 'warn' | 'info' | 'debug';

type LogLine = {
    id: number;
    machineId: number;
    service: string;
    serviceKey: string;
    /** Unix milliseconds. */
    at: number;
    level: Level;
    stream: 'stdout' | 'stderr' | 'journal';
    message: string;
};

type Filters = {
    machine: number | null;
    service: string | null;
    level: 'error' | 'warn' | null;
    q: string | null;
    range: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    machines: { id: number; name: string }[];
    services: { machineId: number; name: string; label: string }[];
    filters: Filters;
    lines: LogLine[];
    hasOlder: boolean;
    retentionDays: number;
    /** Whether this person may delete lines (manage machines). */
    canDelete: boolean;
    shipping: boolean;
    histogram: Histogram;
    /** Today's lines against the plan's daily allowance (UTC). */
    quota: {
        limit: number;
        kept: number;
        dropped: number;
        fullAt: string | null;
        resetsAt: string;
    };
    /** Where lines are kept: ours, or the workspace's own database. */
    logStore: {
        external: boolean;
        status: 'ok' | 'unreachable';
        error: string | null;
    };
}>();

/** Lines kept on the page while following; older ones are a click away. */
const KEEP = 2000;
const FOLLOW_EVERY = 2000;
/** The chart counts by the minute, so it needn't refresh as often. */
const CHART_EVERY = 30_000;

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const search = ref(props.filters.q ?? '');

const levels = [
    { value: null, label: trans('All levels'), title: undefined },
    {
        value: 'warn',
        label: trans('Warnings'),
        title: trans('Warnings and errors'),
    },
    { value: 'error', label: trans('Errors'), title: undefined },
] as const;

const ranges = [
    { value: '15m', label: trans('Last 15 minutes') },
    { value: '1h', label: trans('Last hour') },
    { value: '6h', label: trans('Last 6 hours') },
    { value: '24h', label: trans('Last 24 hours') },
    { value: '7d', label: trans('Last 7 days') },
] as const;

const rangeLabel = computed(
    () =>
        ranges.find((r) => r.value === props.filters.range)?.label ??
        trans(
            props.retentionDays === 1 ? 'All :count day' : 'All :count days',
            { count: String(props.retentionDays) },
        ),
);

const machineName = computed(
    () =>
        props.machines.find((m) => m.id === props.filters.machine)?.name ??
        trans('All'),
);

/** Services for the picker: one machine's, or each name once. */
const serviceOptions = computed(() => {
    const seen = new Set<string>();

    return props.services.filter((service) => {
        if (
            props.filters.machine !== null &&
            service.machineId !== props.filters.machine
        )
            return false;
        if (seen.has(service.name)) return false;
        seen.add(service.name);

        return true;
    });
});

const labels = computed(() => {
    const map = new Map<string, string>();
    for (const service of props.services) {
        map.set(`${service.machineId}|${service.name}`, service.label);
    }

    return map;
});

function serviceLabel(line: { machineId: number; service: string }): string {
    return (
        labels.value.get(`${line.machineId}|${line.service}`) ?? line.service
    );
}

const serviceName = computed(
    () =>
        serviceOptions.value.find((s) => s.name === props.filters.service)
            ?.label ??
        props.filters.service ??
        trans('All'),
);

const machineNames = computed(
    () => new Map(props.machines.map((m) => [m.id, m.name])),
);

const filtered = computed(
    () =>
        props.filters.machine !== null ||
        props.filters.service !== null ||
        props.filters.level !== null ||
        props.filters.q !== null ||
        props.filters.range !== null,
);

function apply(changes: Partial<Filters>): void {
    const next = { ...props.filters, ...changes };
    if (changes.machine !== undefined && changes.service === undefined) {
        next.service = null;
    }

    const query: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(next)) {
        if (value !== null && value !== '') query[key] = value;
    }

    context.value = null;
    router.get('/logs', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['lines', 'hasOlder', 'filters', 'histogram'],
    });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(
        () => apply({ q: value.trim() === '' ? null : value.trim() }),
        300,
    );
});

const lines = ref<LogLine[]>([...props.lines]);
const older = ref(props.hasOlder);
const live = ref(true);
const unseen = ref(0);
const skipped = ref(false);

const scroller = useTemplateRef<HTMLElement>('scroller');
const atBottom = ref(true);

watch(
    () => props.lines,
    async (fresh) => {
        lines.value = [...fresh];
        older.value = props.hasOlder;
        unseen.value = 0;
        skipped.value = false;
        await nextTick();
        toBottom();
    },
);

function onScroll(): void {
    const el = scroller.value;
    if (!el) return;
    atBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 40;
    if (atBottom.value) unseen.value = 0;
}

function toBottom(): void {
    const el = scroller.value;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
    atBottom.value = true;
    unseen.value = 0;
}

function feedQuery(extra: Record<string, number>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries({
        ...props.filters,
        ...extra,
    })) {
        if (value !== null && value !== '') params.set(key, String(value));
    }

    return `/logs/feed?${params}`;
}

async function getJson<T>(url: string): Promise<T | null> {
    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.ok ? ((await response.json()) as T) : null;
    } catch {
        return null;
    }
}

let following = false;

async function follow(): Promise<void> {
    if (following || !live.value || context.value !== null) return;
    if (document.visibilityState !== 'visible') return;
    following = true;

    const after = lines.value.at(-1)?.id ?? 0;
    const result = await getJson<{ lines: LogLine[]; skipped: boolean }>(
        feedQuery({ after }),
    );
    following = false;

    if (!result || result.lines.length === 0 || context.value !== null) return;

    const stick = atBottom.value;
    if (result.skipped) {
        // More arrived than one poll returns: jump to the newest.
        lines.value = result.lines;
        older.value = true;
        skipped.value = true;
    } else {
        lines.value = [...lines.value, ...result.lines];
        if (lines.value.length > KEEP) {
            lines.value = lines.value.slice(-KEEP);
            older.value = true;
        }
    }

    await nextTick();
    if (stick) toBottom();
    else unseen.value += result.lines.length;
}

const loadingOlder = ref(false);

async function loadOlder(): Promise<void> {
    const first = lines.value[0]?.id;
    if (!first || loadingOlder.value) return;
    loadingOlder.value = true;

    const result = await getJson<{ lines: LogLine[]; hasOlder: boolean }>(
        feedQuery({ before: first }),
    );
    loadingOlder.value = false;
    if (!result) return;

    const el = scroller.value;
    const from = el ? el.scrollHeight - el.scrollTop : 0;
    lines.value = [...result.lines, ...lines.value];
    older.value = result.hasOlder;
    await nextTick();
    if (el) el.scrollTop = el.scrollHeight - from;
}

const context = ref<{ anchor: LogLine; lines: LogLine[] } | null>(null);

async function showContext(line: LogLine): Promise<void> {
    const result = await getJson<{ lines: LogLine[] }>(
        `/logs/feed?around=${line.id}`,
    );
    if (!result) return;

    context.value = { anchor: line, lines: result.lines };
    await nextTick();
    scroller.value
        ?.querySelector('[data-anchor]')
        ?.scrollIntoView({ block: 'center' });
}

async function leaveContext(): Promise<void> {
    context.value = null;
    await nextTick();
    toBottom();
}

const shown = computed(() => context.value?.lines ?? lines.value);

const levelTag: Record<Level, { text: string; class: string }> = {
    error: { text: trans('ERR'), class: 'bg-(--ds-red)/14 text-(--ds-red)' },
    warn: { text: trans('WRN'), class: 'bg-(--ds-amber)/14 text-(--ds-amber)' },
    info: { text: trans('INF'), class: 'text-(--ds-text-faint)' },
    debug: { text: trans('DBG'), class: 'text-(--ds-text-faint)' },
};

const rowTone: Record<Level, string> = {
    error: 'bg-(--ds-red)/6 shadow-[inset_2px_0_0_var(--ds-red)]',
    warn: 'bg-(--ds-amber)/6 shadow-[inset_2px_0_0_var(--ds-amber)]',
    info: '',
    debug: 'text-(--ds-text-muted)',
};

function clock(at: number): string {
    const d = new Date(at);
    const pad = (n: number, w = 2) => String(n).padStart(w, '0');

    return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}.${pad(d.getMilliseconds(), 3)}`;
}

/*
 * One formatter each, made once: toLocaleString() with options builds a
 * new one per call, and every line asks for both, again each time a few
 * new lines arrive. Same output.
 */
const dayFormat = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const fullFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
});

function day(at: number): string {
    return dayFormat.format(at);
}

function fullTime(at: number): string {
    return fullFormat.format(at);
}

/** Rows with a date line wherever the day changes. */
const rows = computed(() => {
    const out: (
        | { type: 'day'; key: string; label: string }
        | { type: 'line'; key: number; line: LogLine }
    )[] = [];
    let previous = '';

    for (const line of shown.value) {
        const label = day(line.at);
        if (label !== previous) {
            out.push({ type: 'day', key: `day-${line.id}`, label });
            previous = label;
        }
        out.push({ type: 'line', key: line.id, line });
    }

    return out;
});

/** The message split around the search text, for highlighting. */
function parts(message: string): { text: string; match: boolean }[] {
    const q = context.value ? null : props.filters.q;
    if (!q) return [{ text: message, match: false }];

    const out: { text: string; match: boolean }[] = [];
    const lower = message.toLowerCase();
    const needle = q.toLowerCase();
    let from = 0;
    let at = lower.indexOf(needle);

    while (at !== -1) {
        if (at > from)
            out.push({ text: message.slice(from, at), match: false });
        out.push({ text: message.slice(at, at + q.length), match: true });
        from = at + q.length;
        at = lower.indexOf(needle, from);
    }
    if (from < message.length)
        out.push({ text: message.slice(from), match: false });

    return out;
}

/*
 * Structured lines. Many services (Caddy, most Node and Go apps) write each
 * line as JSON. Show its message first, the logger as a faint prefix and the
 * rest as key=value; a click opens the whole thing, formatted.
 */
type Structured = {
    message: string;
    logger: string | null;
    fields: string;
    pretty: string;
};

const QUIET_KEYS = new Set([
    'level',
    'lvl',
    'severity',
    'ts',
    'time',
    'timestamp',
    '@timestamp',
    'msg',
    'message',
    'logger',
    'name',
]);

const structuredCache = new Map<number, Structured | null>();

function structured(line: LogLine): Structured | null {
    if (structuredCache.has(line.id)) return structuredCache.get(line.id)!;

    let result: Structured | null = null;
    const text = line.message.trim();
    if (text.startsWith('{') && text.endsWith('}')) {
        try {
            const data = JSON.parse(text) as Record<string, unknown>;
            const message = data.msg ?? data.message;
            if (typeof message === 'string') {
                const logger = data.logger ?? data.name;
                result = {
                    message,
                    logger: typeof logger === 'string' ? logger : null,
                    fields: Object.entries(data)
                        .filter(([key]) => !QUIET_KEYS.has(key))
                        .map(
                            ([key, value]) =>
                                `${key}=${typeof value === 'string' ? value : JSON.stringify(value)}`,
                        )
                        .join('  '),
                    pretty: JSON.stringify(data, null, 2),
                };
            }
        } catch {
            // Not JSON after all; show it as written.
        }
    }

    structuredCache.set(line.id, result);
    return result;
}

/** Lines opened to show everything they carry. */
const opened = ref(new Set<number>());

function toggleOpen(id: number): void {
    const next = new Set(opened.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    opened.value = next;
}

/** Picking lines to delete: clicking a line ticks it. */
const picking = ref(false);
const picked = ref(new Set<number>());

/** A delete can't be every line: it needs a time range or a service. */
const canDeleteMatching = computed(
    () => props.filters.range !== null || props.filters.service !== null,
);

function startPicking(): void {
    context.value = null;
    live.value = false;
    picked.value = new Set();
    picking.value = true;
}

function stopPicking(): void {
    picking.value = false;
    picked.value = new Set();
}

function togglePicked(id: number): void {
    const next = new Set(picked.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    picked.value = next;
}

/** The delete being confirmed, counted and pinned to when it was counted. */
const deleting = ref<{
    mode: 'matching' | 'picked';
    count: number;
    upTo: number;
    since: number | null;
    ids: number[];
} | null>(null);
const confirmOpen = ref(false);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);
const deleteNote = ref<string | null>(null);

function deleteParams(ids: number[]): URLSearchParams {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(props.filters)) {
        if (value !== null && value !== '') params.set(key, String(value));
    }
    for (const id of ids) params.append('ids[]', String(id));

    return params;
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function askToDelete(mode: 'matching' | 'picked'): Promise<void> {
    const ids = mode === 'picked' ? [...picked.value] : [];
    deleteError.value = null;
    deleteNote.value = null;
    const result = await getJson<{
        count: number;
        upTo: number;
        since: number | null;
    }>(`/logs/deletion?${deleteParams(ids)}`);
    if (!result) {
        deleteNote.value = trans('Couldn’t count the lines. Try again.');
        return;
    }

    deleting.value = { mode, ids, ...result };
    confirmOpen.value = true;
}

async function confirmDelete(): Promise<void> {
    const pending = deleting.value;
    if (!pending || deleteBusy.value) return;
    deleteBusy.value = true;
    deleteError.value = null;

    const body: Record<string, unknown> = {
        up_to: pending.upTo,
        since: pending.since,
        ids: pending.ids,
    };
    for (const [key, value] of Object.entries(props.filters)) {
        if (value !== null && value !== '') body[key] = value;
    }

    try {
        const response = await fetch('/logs', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        });
        const data = (await response.json().catch(() => null)) as {
            deleted?: number;
            queued?: boolean;
            message?: string;
        } | null;
        if (!response.ok) {
            deleteError.value =
                data?.message ?? trans('Couldn’t delete them. Try again.');
            return;
        }

        confirmOpen.value = false;
        const gone = new Set(pending.ids);
        if (pending.mode === 'picked') {
            lines.value = lines.value.filter((line) => !gone.has(line.id));
            stopPicking();
        }
        if (data?.queued) {
            deleteNote.value = trans(
                'Deleting :count lines in the background. They’ll be gone in a few minutes.',
                { count: (data.deleted ?? 0).toLocaleString() },
            );
        }
        router.reload({ only: ['lines', 'hasOlder', 'histogram'] });
    } catch {
        deleteError.value = trans('Couldn’t delete them. Try again.');
    } finally {
        deleteBusy.value = false;
    }
}

const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
let timer: ReturnType<typeof setInterval> | undefined;
let chartTimer: ReturnType<typeof setInterval> | undefined;

function refreshChart(): void {
    if (live.value && !context.value && !document.hidden) {
        router.reload({ only: ['histogram'] });
    }
}

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    if (
        event.key === '/' &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '') &&
        !target?.isContentEditable
    ) {
        event.preventDefault();
        searchInput.value?.focus();
    }
    if (event.key === 'Escape' && context.value) leaveContext();
    else if (event.key === 'Escape' && picking.value && !confirmOpen.value)
        stopPicking();
}

onMounted(() => {
    toBottom();
    timer = setInterval(follow, FOLLOW_EVERY);
    chartTimer = setInterval(refreshChart, CHART_EVERY);
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    clearInterval(chartTimer);
    clearTimeout(searchTimer);
    window.removeEventListener('keydown', onKey);
});

watch(live, (on) => {
    if (on) follow();
});
</script>

<template>
    <Head :title="$t('Logs')" />

    <WorkspacePage :title="$t('Logs')" toolbar>
        <template #description>
            {{
                ' ' +
                $t(
                    'Every line your services write, kept for :retentionDays :value. Search all of them at once, or follow one as it runs.',
                    {
                        retentionDays: String(retentionDays ?? ''),
                        value: retentionDays === 1 ? $t('day') : $t('days'),
                    },
                ) +
                ' '
            }}
        </template>

        <template v-if="machines.length > 0" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div class="ds-segments" role="group" :aria-label="$t('Level')">
                    <button
                        v-for="level in levels"
                        :key="level.label"
                        :title="level.title"
                        type="button"
                        class="cursor-pointer"
                        :class="focusRing"
                        :aria-pressed="filters.level === level.value"
                        @click="apply({ level: level.value })"
                    >
                        {{ level.label }}
                    </button>
                </div>
            </div>
        </template>

        <template v-if="machines.length > 0" #actions>
            <label
                class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-72"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{ $t('Search lines') }}</span>
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    :placeholder="$t('Search lines')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                />
                <kbd
                    class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px font-ui text-[11px]/3.5 text-(--ds-text-muted)"
                    aria-hidden="true"
                >
                    /
                </kbd>
            </label>

            <DropdownMenu v-if="machines.length > 1">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('Machine')
                        }}</span>
                        <span class="max-w-32 truncate font-medium">
                            {{ machineName }}
                        </span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-40 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="apply({ machine: null })">
                        {{ ' ' + $t('All') }}
                        <Check
                            v-if="filters.machine === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="machine in machines"
                        :key="machine.id"
                        @select="apply({ machine: machine.id })"
                    >
                        {{ machine.name }}
                        <Check
                            v-if="filters.machine === machine.id"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('Service')
                        }}</span>
                        <span class="max-w-36 truncate font-medium">
                            {{ serviceName }}
                        </span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-80 min-w-48 overflow-y-auto font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="apply({ service: null })">
                        {{ ' ' + $t('All') }}
                        <Check
                            v-if="filters.service === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator v-if="serviceOptions.length" />
                    <DropdownMenuItem
                        v-for="service in serviceOptions"
                        :key="`${service.machineId}|${service.name}`"
                        :title="service.name"
                        @select="apply({ service: service.name })"
                    >
                        <span class="truncate">{{ service.label }}</span>
                        <span
                            v-if="
                                filters.machine === null && machines.length > 1
                            "
                            class="truncate text-xs text-(--ds-text-muted)"
                        >
                            {{ machineNames.get(service.machineId) }}
                        </span>
                        <Check
                            v-if="filters.service === service.name"
                            class="ml-auto size-3.5 shrink-0"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="font-medium">{{ rangeLabel }}</span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="end"
                >
                    <DropdownMenuItem
                        v-for="range in ranges"
                        :key="range.value"
                        @select="apply({ range: range.value })"
                    >
                        {{ range.label }}
                        <Check
                            v-if="filters.range === range.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem @select="apply({ range: null })">
                        {{ ' ' + $t('Everything kept') }}
                        <Check
                            v-if="filters.range === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu v-if="canDelete && shipping">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                        :title="$t('Delete lines')"
                    >
                        <Trash2 class="size-3.5" :stroke-width="1.75" />
                        <span class="sr-only">{{ $t('Delete lines') }}</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-56 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="startPicking">
                        {{ ' ' + $t('Pick lines to delete') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="!canDeleteMatching"
                        @select="askToDelete('matching')"
                    >
                        <span class="flex flex-col">
                            <span>{{
                                $t('Delete what these filters match')
                            }}</span>
                            <span
                                v-if="!canDeleteMatching"
                                class="text-xs text-(--ds-text-muted)"
                            >
                                {{ $t('Pick a time range or a service first') }}
                            </span>
                        </span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <button
                type="button"
                class="ds-raised-button cursor-pointer text-[13px]!"
                :class="focusRing"
                :aria-pressed="live"
                :title="
                    live
                        ? $t('Pause following new lines')
                        : $t('Follow new lines')
                "
                @click="live = !live"
            >
                <span class="relative flex size-2" aria-hidden="true">
                    <span
                        v-if="live"
                        class="absolute inline-flex size-full animate-ping rounded-full bg-(--ds-green) opacity-60 motion-reduce:hidden"
                    />
                    <span
                        class="relative inline-flex size-2 rounded-full"
                        :class="
                            live ? 'bg-(--ds-green)' : 'bg-(--ds-text-faint)'
                        "
                    />
                </span>
                <span class="font-medium">{{
                    live ? $t('Live') : $t('Paused')
                }}</span>
            </button>
        </template>

        <!-- Their own log database isn't answering -->
        <div
            v-if="logStore.external && logStore.status === 'unreachable'"
            class="mb-4 rounded-xl bg-(--ds-amber)/8 px-4 py-3 text-[13px]/5 shadow-[inset_2px_0_0_var(--ds-amber)]"
            role="status"
        >
            <p class="font-medium">
                {{ $t('Can’t reach your log database.') }}
            </p>
            <p class="mt-0.5 text-pretty text-(--ds-text-muted)">
                <span v-if="logStore.error" class="break-words">{{
                    logStore.error + ' '
                }}</span>
                {{
                    $t(
                        'New lines are held for a while and stored once it answers.',
                    )
                }}
            </p>
        </div>

        <!-- Over today's allowance (not for lines kept in their own database) -->
        <div
            v-if="!logStore.external && quota.dropped > 0"
            class="mb-4 rounded-xl bg-(--ds-amber)/8 px-4 py-3 text-[13px]/5 shadow-[inset_2px_0_0_var(--ds-amber)]"
            role="status"
        >
            <p class="font-medium">
                {{
                    $t(
                        "Today's log limit is reached, so new lines aren't kept.",
                    )
                }}
            </p>
            <p class="mt-0.5 text-pretty text-(--ds-text-muted)">
                {{
                    ' ' +
                    $t(
                        'Your plan keeps :limit lines a day. :dropped more came in since :time and were left out. It starts again at :reset. To make room, stop keeping logs from a chatty service on its page.',
                        {
                            limit: quota.limit.toLocaleString(),
                            dropped: quota.dropped.toLocaleString(),
                            time: quota.fullAt
                                ? new Date(quota.fullAt).toLocaleTimeString(
                                      [],
                                      { hour: '2-digit', minute: '2-digit' },
                                  )
                                : '',
                            reset: new Date(quota.resetsAt).toLocaleTimeString(
                                [],
                                { hour: '2-digit', minute: '2-digit' },
                            ),
                        },
                    ) +
                    ' '
                }}
            </p>
        </div>

        <p
            v-if="deleteNote"
            class="mb-4 rounded-xl bg-(--ds-hover) px-4 py-3 text-[13px]/5 text-(--ds-text-secondary)"
            role="status"
        >
            {{ deleteNote }}
        </p>

        <div
            v-if="machines.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <ScrollText class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ $t('No logs yet') }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Connect a machine and every service on it sends its logs here, with secrets taken out before they leave the machine.',
                    ) +
                    ' '
                }}
            </p>
            <ConnectMachineButton class="mt-6" />
        </div>

        <!-- Nothing shipped yet -->
        <div
            v-else-if="!shipping"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <ScrollText class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ $t('Waiting for lines') }}
            </h2>
            <p
                class="mt-1.5 max-w-md text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Containers and systemd services send what they write as they write it. Lines show up here within a few seconds of the daemon updating; nothing to install or configure.',
                    ) +
                    ' '
                }}
            </p>
        </div>

        <div v-else class="ds-tile relative overflow-hidden">
            <div
                v-if="context"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/5"
            >
                <span class="min-w-0 text-(--ds-text-secondary)">
                    {{ ' ' + $t('Lines around') }}
                    <span class="font-medium text-(--ds-text)">{{
                        clock(context.anchor.at)
                    }}</span>
                    {{ $t('from') }}
                    <span class="font-medium text-(--ds-text)">{{
                        serviceLabel(context.anchor)
                    }}</span>
                    <template v-if="machines.length > 1">
                        {{
                            ' ' +
                            $t('on :value', {
                                value: String(
                                    machineNames.get(
                                        context.anchor.machineId,
                                    ) ?? '',
                                ),
                            })
                        }}
                    </template>
                </span>
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                    :class="focusRing"
                    @click="leaveContext"
                >
                    <ArrowLeft class="size-3.5" />
                    {{ $t('Back to results') + ' ' }}
                </button>
            </div>

            <div
                v-if="picking"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/5"
            >
                <span class="text-(--ds-text-secondary)">
                    {{
                        picked.size === 0
                            ? $t('Click lines to pick them for deleting.')
                            : $t(
                                  picked.size === 1
                                      ? ':count line picked'
                                      : ':count lines picked',
                                  { count: String(picked.size) },
                              )
                    }}
                </span>
                <span class="flex items-center gap-2">
                    <AppButton size="sm" @click="stopPicking">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <AppButton
                        size="sm"
                        :disabled="picked.size === 0"
                        @click="askToDelete('picked')"
                    >
                        {{ ' ' + $t('Delete') + ' ' }}
                    </AppButton>
                </span>
            </div>

            <LogHistogram
                v-if="!context"
                :histogram="histogram"
                :searching="filters.q !== null"
            />

            <div
                ref="scroller"
                class="h-[calc(100dvh-25rem)] min-h-96 overflow-y-auto overscroll-contain font-ui-mono text-[12px]/5"
                role="log"
                :aria-label="$t('Log lines')"
                :aria-live="live && !context ? 'polite' : 'off'"
                @scroll.passive="onScroll"
            >
                <div
                    v-if="!context && older"
                    class="flex justify-center border-b border-(--ds-divider) py-2"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded-md px-2 py-1 font-ui text-xs font-medium text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text) disabled:opacity-60"
                        :class="focusRing"
                        :disabled="loadingOlder"
                        @click="loadOlder"
                    >
                        {{
                            loadingOlder
                                ? $t('Loading…')
                                : $t('Load older lines')
                        }}
                    </button>
                </div>

                <div
                    v-if="!context && skipped"
                    class="border-b border-(--ds-divider) px-4 py-1.5 font-ui text-xs text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'Lines came in faster than the page shows them, so it jumped to the newest. Pause to read, or narrow the filters.',
                        ) +
                        ' '
                    }}
                </div>

                <div
                    v-if="shown.length === 0"
                    class="flex h-full flex-col items-center justify-center px-6 text-center font-ui"
                >
                    <h2 class="text-sm/6 font-semibold">
                        {{
                            filtered ? $t('No lines match') : $t('Quiet so far')
                        }}
                    </h2>
                    <p
                        class="mt-1 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            filtered
                                ? $t('Nothing kept matches these filters.')
                                : $t(
                                      'New lines appear here as your services write them.',
                                  )
                        }}
                        <template v-if="filtered && live">
                            {{
                                ' ' +
                                $t('New ones that do will appear here.') +
                                ' '
                            }}
                        </template>
                    </p>
                    <button
                        v-if="filtered"
                        type="button"
                        class="mt-4 cursor-pointer text-[13px] font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                        :class="focusRing"
                        @click="
                            search = '';
                            apply({
                                machine: null,
                                service: null,
                                level: null,
                                q: null,
                                range: null,
                            });
                        "
                    >
                        {{ ' ' + $t('Clear filters') + ' ' }}
                    </button>
                </div>

                <template v-for="row in rows" :key="row.key">
                    <div
                        v-if="row.type === 'day'"
                        class="sticky top-0 z-10 border-b border-(--ds-divider) bg-(--ds-card) px-4 py-1 font-ui text-[11px]/4 font-medium text-(--ds-text-muted)"
                    >
                        {{ row.label }}
                    </div>
                    <div
                        v-else
                        class="group grid grid-cols-[5.75rem_2.25rem_minmax(0,1fr)] items-start gap-x-3 px-4 py-1 hover:bg-(--ds-row-hover) md:grid-cols-[5.75rem_2.25rem_8.5rem_minmax(0,1fr)_1.25rem]"
                        :class="[
                            rowTone[row.line.level],
                            picking
                                ? 'cursor-pointer *:pointer-events-none'
                                : '',
                            picked.has(row.line.id)
                                ? 'bg-(--ds-red)/10! shadow-[inset_2px_0_0_var(--ds-red)]!'
                                : '',
                            context?.anchor.id === row.line.id
                                ? 'bg-(--ds-blue)/10! shadow-[inset_2px_0_0_var(--ds-blue)]!'
                                : '',
                        ]"
                        :data-anchor="
                            context?.anchor.id === row.line.id ? '' : undefined
                        "
                        :role="picking ? 'checkbox' : undefined"
                        :aria-checked="
                            picking ? picked.has(row.line.id) : undefined
                        "
                        :tabindex="picking ? 0 : undefined"
                        @click="picking && togglePicked(row.line.id)"
                        @keydown.space.prevent="
                            picking && togglePicked(row.line.id)
                        "
                    >
                        <time
                            class="text-(--ds-text-faint) tabular-nums select-none"
                            :datetime="new Date(row.line.at).toISOString()"
                            :title="fullTime(row.line.at)"
                        >
                            {{ clock(row.line.at) }}
                        </time>
                        <span
                            class="w-fit rounded-[4px] px-1 text-[11px]/5 font-medium select-none"
                            :class="levelTag[row.line.level].class"
                            :title="row.line.stream"
                        >
                            {{ levelTag[row.line.level].text }}
                        </span>
                        <button
                            type="button"
                            class="cursor-pointer truncate text-left font-ui text-[12px]/5 font-medium text-(--ds-text-secondary) hover:text-(--ds-text) hover:underline"
                            :title="
                                machines.length > 1
                                    ? $t('Only :service on :machine', {
                                          service: serviceLabel(row.line),
                                          machine: String(
                                              machineNames.get(
                                                  row.line.machineId,
                                              ) ?? '',
                                          ),
                                      })
                                    : $t('Only :service', {
                                          service: serviceLabel(row.line),
                                      })
                            "
                            @click="
                                apply({
                                    machine: row.line.machineId,
                                    service: row.line.service,
                                })
                            "
                        >
                            {{ serviceLabel(row.line) }}
                        </button>

                        <!-- A JSON line: its message, then the rest -->
                        <div
                            v-if="structured(row.line)"
                            class="col-span-3 min-w-0 md:col-span-1"
                        >
                            <button
                                type="button"
                                class="block w-full cursor-pointer text-left"
                                :aria-expanded="opened.has(row.line.id)"
                                @click="toggleOpen(row.line.id)"
                            >
                                <span
                                    v-if="structured(row.line)!.logger"
                                    class="mr-2 text-(--ds-text-faint)"
                                    >{{ structured(row.line)!.logger }}</span
                                ><span class="text-(--ds-text)"
                                    ><template
                                        v-for="(part, i) in parts(
                                            structured(row.line)!.message,
                                        )"
                                        :key="i"
                                        ><mark
                                            v-if="part.match"
                                            class="rounded-[2px] bg-(--ds-amber)/30 text-inherit"
                                            >{{ part.text }}</mark
                                        ><template v-else>{{
                                            part.text
                                        }}</template></template
                                    ></span
                                >
                                <span
                                    v-if="
                                        structured(row.line)!.fields &&
                                        !opened.has(row.line.id)
                                    "
                                    class="block truncate text-[11px]/4.5 text-(--ds-text-faint)"
                                    >{{ structured(row.line)!.fields }}</span
                                >
                            </button>
                            <pre
                                v-if="opened.has(row.line.id)"
                                class="mt-1.5 mb-1 overflow-x-auto rounded-md bg-(--ds-code) px-3 py-2 text-[11px]/4.5 text-(--ds-text-secondary)"
                                >{{ structured(row.line)!.pretty }}</pre>
                        </div>
                        <span
                            v-else
                            class="col-span-3 min-w-0 break-words whitespace-pre-wrap md:col-span-1"
                            ><template
                                v-for="(part, i) in parts(row.line.message)"
                                :key="i"
                                ><mark
                                    v-if="part.match"
                                    class="rounded-[2px] bg-(--ds-amber)/30 text-inherit"
                                    >{{ part.text }}</mark
                                ><template v-else>{{
                                    part.text
                                }}</template></template
                            ></span
                        >
                        <button
                            v-if="!context"
                            type="button"
                            class="hidden size-5 cursor-pointer items-center justify-center rounded text-(--ds-text-muted) opacity-0 group-hover:opacity-100 hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:opacity-100 md:flex"
                            :class="focusRing"
                            :title="$t('Show the lines around this one')"
                            :aria-label="$t('Show the lines around this one')"
                            @click="showContext(row.line)"
                        >
                            <TextSearch class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </div>
                </template>
            </div>

            <button
                v-if="!context && unseen > 0 && !atBottom"
                type="button"
                class="ds-raised-button absolute bottom-4 left-1/2 -translate-x-1/2 cursor-pointer text-[13px]! shadow-md"
                :class="focusRing"
                @click="toBottom"
            >
                <ArrowDown class="size-3.5" />
                {{
                    $t(':unseen new :value', {
                        unseen: String(unseen ?? ''),
                        value: unseen === 1 ? $t('line') : $t('lines'),
                    })
                }}
            </button>
        </div>
        <Dialog v-model:open="confirmOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            deleting?.count
                                ? $t(
                                      deleting.count === 1
                                          ? 'Delete :count line?'
                                          : 'Delete :count lines?',
                                      {
                                          count: deleting.count.toLocaleString(),
                                      },
                                  )
                                : $t('Nothing to delete')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            deleting?.count
                                ? $t(
                                      'They’re gone for good, from search, the live view and the chart. Lines that come in after now are kept.',
                                  )
                                : $t('No lines match these filters any more.')
                        }}
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="deleteError"
                    class="text-[13px]/5 text-(--ds-red)"
                    role="alert"
                >
                    {{ deleteError }}
                </p>
                <form
                    class="flex justify-end gap-2"
                    @submit.prevent="confirmDelete"
                >
                    <AppButton size="sm" @click="confirmOpen = false">
                        {{
                            ' ' +
                            (deleting?.count ? $t('Cancel') : $t('Close')) +
                            ' '
                        }}
                    </AppButton>
                    <DangerButton
                        v-if="deleting?.count"
                        :label="
                            $t(
                                deleting.count === 1
                                    ? 'Delete :count line'
                                    : 'Delete :count lines',
                                { count: deleting.count.toLocaleString() },
                            )
                        "
                        :processing="deleteBusy"
                        :error="deleteError"
                    />
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
