<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    localTime,
    maintenanceWhen,
    startsIn,
    timeAgo,
} from '@/components/status/status';
import type {
    ComponentStatus,
    StatusComponent,
    StatusReport,
} from '@/components/status/status';

/**
 * A machine's (or a workspace's) public or members-only status page, in
 * the visitor's own light or dark theme: a banner with the overall state,
 * then each service with its uptime and a bar per day for the last 30
 * days. Named groups fold into one row until opened. Past incidents sit
 * behind "View history". Refreshes itself every 30 seconds.
 */
const props = defineProps<{
    status: StatusReport;
    brand: { name: string; logoUrl: string | null };
    /** The browser tab's title, as written, when it isn't the name. */
    pageTitle: string | null;
    private: boolean;
    jsonUrl: string;
    /** Email updates and the Atom feed; null on members-only pages. */
    subscribeUrl: string | null;
    feedUrl: string | null;
    /** The site, through the workspace owner's partner link if they have one. */
    poweredByUrl: string;
    /** Link preview tags, rendered by the server; unused here. */
    meta: Record<string, string> | null;
}>();

/** The last 30 days of the 90 the report carries, as the bars show. */
const DAYS = 30;

const word: Record<ComponentStatus, string> = {
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

function worst(statuses: ComponentStatus[]): ComponentStatus {
    return statuses.reduce<ComponentStatus>(
        (acc, s) => (rank[s] > rank[acc] ? s : acc),
        'operational',
    );
}

const dayOffset = computed(() => Math.max(0, props.status.days.length - DAYS));
const days = computed(() => props.status.days.slice(dayOffset.value));

/** The bars for a set of components: each day, the worst of them. */
function bars(components: StatusComponent[]): (number | null)[] {
    return days.value.map((_, i) => {
        const values = components
            .map((c) => c.uptime?.days[dayOffset.value + i] ?? null)
            .filter((v): v is number => v !== null);

        return values.length ? Math.min(...values) : null;
    });
}

function uptime(values: (number | null)[]): number | null {
    const known = values.filter((v): v is number => v !== null);

    return known.length
        ? known.reduce((sum, v) => sum + v, 0) / known.length
        : null;
}

function percent(value: number | null): string {
    if (value === null) return trans('No data yet');

    const shown =
        value >= 99.995 ? '100' : value.toFixed(3).replace(/\.?0+$/, '');

    return trans(':value% uptime', { value: shown });
}

function tone(value: number | null): string {
    if (value === null) return 'is-empty';
    if (value >= 99.9) return 'is-up';
    if (value >= 95) return 'is-degraded';

    return 'is-down';
}

function dayLabel(index: number, value: number | null): string {
    const date = new Date(`${days.value[index]}T00:00:00`).toLocaleDateString(
        undefined,
        { month: 'short', day: 'numeric' },
    );

    return value === null
        ? trans(':date: no data', { date })
        : `${date}: ${value}%`;
}

const range = computed(() => {
    const format = (day: string | undefined) =>
        day
            ? new Date(`${day}T00:00:00`).toLocaleDateString(undefined, {
                  month: 'short',
                  year: 'numeric',
              })
            : '';
    const from = format(days.value[0]);
    const to = format(days.value[days.value.length - 1]);

    return from === to ? from : `${from} – ${to}`;
});

/* Rows: a named group is one row that opens to its services; services
   with no group are rows of their own. */
type Row =
    | { kind: 'service'; key: string; component: StatusComponent }
    | {
          kind: 'group';
          key: string;
          name: string;
          components: StatusComponent[];
      };

const rows = computed<Row[]>(() =>
    props.status.sections.flatMap((section, index): Row[] =>
        section.name
            ? [
                  {
                      kind: 'group',
                      key: `group-${index}`,
                      name: section.name,
                      components: section.components,
                  },
              ]
            : section.components.map((component) => ({
                  kind: 'service',
                  key: component.key,
                  component,
              })),
    ),
);

const opened = ref<Record<string, boolean>>({});
function toggle(key: string): void {
    opened.value = { ...opened.value, [key]: !opened.value[key] };
}

const overall = computed(() => props.status.overall.status);
const affected = computed(() =>
    props.status.sections
        .flatMap((s) => s.components)
        .filter((c) => c.status === 'down' || c.status === 'degraded')
        .map((c) => c.label),
);
const bannerText = computed(() => {
    const notice = props.status.notice;
    // Maintenance under way: when it ends, in the visitor's own time.
    if (notice?.kind === 'maintenance' && notice.until)
        return (
            (notice.reason
                ? `${notice.reason.replace(/\.$/, '')}. `
                : `${trans('Planned maintenance.')} `) +
            trans('Expected to finish by :time.', {
                time: localTime(notice.until),
            })
        );
    if (notice) return notice.text;
    // An open incident someone wrote says it best.
    const open = props.status.notices?.find((n) => !n.resolvedAt);
    if (open) return open.title;
    if (overall.value === 'operational')
        return trans('We’re not aware of any issues affecting our systems.');
    if (affected.value.length)
        return trans('Having trouble: :names.', {
            names: affected.value.join(', '),
        });

    return trans('Some services aren’t reporting right now.');
});

const subscribing = ref(false);
const email = ref('');
const subscribeState = ref<'idle' | 'sending' | 'sent' | 'failed'>('idle');
const subscribeMessage = ref('');

async function subscribe(): Promise<void> {
    if (!props.subscribeUrl || subscribeState.value === 'sending') return;
    subscribeState.value = 'sending';
    try {
        const response = await fetch(props.subscribeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({ email: email.value }),
        });
        const json = await response.json().catch(() => ({}));
        subscribeState.value = response.ok ? 'sent' : 'failed';
        subscribeMessage.value = response.ok
            ? trans('Check your inbox for a link to confirm.')
            : (json.errors?.email?.[0] ??
              json.message ??
              trans('That didn’t work. Try again.'));
    } catch {
        subscribeState.value = 'failed';
        subscribeMessage.value = trans('That didn’t work. Try again.');
    }
}

/* Incidents the workspace wrote */
const noticeStates: Record<string, string> = {
    investigating: trans('Investigating'),
    identified: trans('Identified'),
    monitoring: trans('Monitoring'),
    resolved: trans('Resolved'),
};
const noticeImpacts: Record<string, string> = {
    degraded: trans('Degraded performance'),
    partial: trans('Partial outage'),
    major: trans('Major outage'),
};
function noticeDot(notice: {
    impact: string;
    resolvedAt: string | null;
}): string {
    if (notice.resolvedAt) return 'is-operational';
    return notice.impact === 'degraded' ? 'is-degraded' : 'is-down';
}

/* Past incidents, behind "View history" */
const history = ref(false);

function duration(seconds: number): string {
    if (seconds < 60)
        return trans(':count seconds', { count: String(seconds) });
    if (seconds < 3600) {
        const m = Math.round(seconds / 60);

        return `${m} ${m === 1 ? trans('minute') : trans('minutes')}`;
    }
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);

    return `${h} h${m ? ` ${m} min` : ''}`;
}

function when(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/* Keep "Updated …" honest between reloads, and reload every 30 s. */
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    tick = setInterval(() => (now.value = Date.now()), 15000);
    reload = setInterval(() => router.reload({ only: ['status'] }), 30000);
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
});

const updated = computed(() => {
    void now.value;

    return timeAgo(props.status.checkedAt);
});
</script>

<template>
    <Head :title="pageTitle || status.title" />

    <div class="status-page min-h-screen font-ui antialiased">
        <main
            class="mx-auto flex max-w-[702px] flex-col px-4 pt-10 pb-16 sm:pt-12"
        >
            <header class="flex items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <img
                        v-if="brand.logoUrl"
                        :src="brand.logoUrl"
                        alt=""
                        class="size-8 shrink-0 rounded-md object-cover"
                    />
                    <h1
                        class="truncate text-[22px]/7 font-semibold tracking-[-0.02em]"
                    >
                        {{ status.title }}
                    </h1>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <p
                        class="text-[13px]/5 text-(--sp-muted)"
                        :class="{ 'hidden sm:block': subscribeUrl }"
                    >
                        {{ $t('Updated :updated', { updated }) }}
                    </p>
                    <button
                        v-if="subscribeUrl"
                        type="button"
                        class="ghost-button cursor-pointer rounded-md px-2.5 py-1 text-[13px]/5 font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--sp-focus)"
                        :aria-expanded="subscribing"
                        aria-controls="get-updates"
                        @click="subscribing = !subscribing"
                    >
                        {{ $t('Get updates') }}
                    </button>
                </div>
            </header>

            <section
                v-if="subscribeUrl && subscribing"
                id="get-updates"
                class="card mt-4 rounded-lg p-px"
            >
                <div class="rounded-[7px] bg-(--sp-card) px-4 py-3.5">
                    <p
                        v-if="subscribeState === 'sent'"
                        class="text-[14px]/5"
                        role="status"
                    >
                        {{ subscribeMessage }}
                    </p>
                    <form
                        v-else
                        class="flex flex-wrap items-center gap-2"
                        @submit.prevent="subscribe"
                    >
                        <label for="subscribe-email" class="sr-only">{{
                            $t('Email address')
                        }}</label>
                        <input
                            id="subscribe-email"
                            v-model="email"
                            type="email"
                            required
                            autocomplete="email"
                            :placeholder="$t('you@example.com')"
                            class="h-8 min-w-0 grow basis-52 rounded-md border-[0.5px] border-(--sp-edge) bg-transparent px-2.5 text-[14px] text-(--sp-text) outline-none placeholder:text-(--sp-muted) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--sp-focus)"
                        />
                        <button
                            type="submit"
                            class="ghost-button h-8 cursor-pointer rounded-md px-3 text-[13px]/5 font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--sp-focus) disabled:opacity-60"
                            :disabled="subscribeState === 'sending'"
                        >
                            {{ $t('Email me updates') }}
                        </button>
                        <a
                            v-if="feedUrl"
                            :href="feedUrl"
                            class="ml-auto text-[13px]/5 text-(--sp-muted) underline-offset-2 hover:underline"
                            >{{ $t('Feed') }}</a
                        >
                        <p
                            v-if="subscribeState === 'failed'"
                            class="w-full text-[13px]/5 text-(--sp-down)"
                            role="alert"
                        >
                            {{ subscribeMessage }}
                        </p>
                    </form>
                    <p
                        v-if="subscribeState !== 'sent'"
                        class="mt-2 text-[12px]/4 text-(--sp-muted)"
                    >
                        {{
                            $t(
                                'We’ll email you when something goes wrong and when it’s fixed. Every email has a link to stop them.',
                            )
                        }}
                    </p>
                </div>
            </section>
            <p
                v-if="status.description"
                class="mt-2 text-[14px]/5 text-pretty text-(--sp-muted)"
            >
                {{ status.description }}
            </p>
            <p v-if="private" class="mt-2 text-[13px]/5 text-(--sp-muted)">
                {{
                    $t('Only members of :name can see this page.', {
                        name: brand.name,
                    })
                }}
            </p>

            <section
                class="banner mt-6 rounded-lg p-px"
                :class="`is-${overall}`"
                aria-live="polite"
            >
                <div class="overflow-hidden rounded-[7px] bg-(--sp-card)">
                    <div
                        class="banner-head flex items-center gap-2 px-4 py-3.5"
                    >
                        <svg
                            viewBox="2 2 16 16"
                            class="banner-icon size-4 shrink-0"
                            aria-hidden="true"
                        >
                            <path
                                v-if="overall === 'operational'"
                                d="M10 2C5.589 2 2 5.589 2 10s3.589 8 8 8 8-3.589 8-8-3.589-8-8-8Zm3.843 5.708-4.25 5.5a.75.75 0 0 1-1.151.043l-2.25-2.5a.75.75 0 1 1 1.116-1.004l1.648 1.832 3.701-4.789a.75.75 0 1 1 1.187.918Z"
                            />
                            <path
                                v-else
                                d="M10 2C5.589 2 2 5.589 2 10s3.589 8 8 8 8-3.589 8-8-3.589-8-8-8Zm-.75 4.5a.75.75 0 0 1 1.5 0v4a.75.75 0 0 1-1.5 0v-4ZM10 14.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"
                            />
                        </svg>
                        <h2
                            class="text-[16px]/6 font-medium tracking-[-0.0125em]"
                        >
                            {{
                                overall === 'operational'
                                    ? $t('We’re fully operational')
                                    : status.overall.label
                            }}
                        </h2>
                    </div>
                    <p class="px-4 py-4 text-[14px]/5 whitespace-pre-line">
                        {{ bannerText }}
                    </p>
                </div>
            </section>

            <!-- Incidents written for the page, open ones first -->
            <section
                v-if="status.notices?.length"
                class="card mt-6 rounded-lg p-px"
                aria-labelledby="recent-incidents"
            >
                <div class="rounded-[7px] bg-(--sp-card)">
                    <h2
                        id="recent-incidents"
                        class="border-b border-(--sp-line) px-4 py-3.5 text-[16px]/6 font-medium tracking-[-0.0125em]"
                    >
                        {{ $t('Incidents') }}
                    </h2>
                    <article
                        v-for="(notice, index) in status.notices"
                        :key="notice.id"
                        class="px-4 py-4"
                        :class="{
                            'border-t-[0.5px] border-(--sp-line)': index > 0,
                        }"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-0.5"
                        >
                            <span
                                class="dot"
                                :class="noticeDot(notice)"
                                aria-hidden="true"
                            />
                            <h3
                                class="text-[14px]/5 font-medium text-(--sp-strong)"
                            >
                                {{ notice.title }}
                            </h3>
                            <span class="text-[13px]/5 text-(--sp-muted)">
                                {{
                                    notice.resolvedAt
                                        ? noticeStates.resolved
                                        : noticeImpacts[notice.impact]
                                }}<template v-if="notice.machine">
                                    · {{ notice.machine }}</template
                                >
                            </span>
                        </div>
                        <ol class="mt-3 grid gap-3">
                            <li
                                v-for="(entry, i) in notice.updates"
                                :key="i"
                                class="grid gap-0.5 text-[13px]/5 sm:grid-cols-[7.5rem_1fr] sm:gap-4"
                            >
                                <span class="font-medium text-(--sp-strong)">{{
                                    noticeStates[entry.state] ?? entry.state
                                }}</span>
                                <div>
                                    <p
                                        class="whitespace-pre-line text-(--sp-text)"
                                    >
                                        {{ entry.message }}
                                    </p>
                                    <time
                                        class="text-[12px]/4 text-(--sp-muted)"
                                        :datetime="entry.at"
                                        >{{ when(entry.at) }}</time
                                    >
                                </div>
                            </li>
                        </ol>
                    </article>
                </div>
            </section>

            <!-- Planned maintenance, soonest first -->
            <section
                v-if="status.upcoming?.length"
                class="card mt-6 rounded-lg p-px"
                aria-labelledby="upcoming-maintenance"
            >
                <div class="rounded-[7px] bg-(--sp-card)">
                    <div
                        class="flex items-center gap-2 border-b border-(--sp-line) px-4 py-3.5"
                    >
                        <span class="dot is-degraded" aria-hidden="true" />
                        <h2
                            id="upcoming-maintenance"
                            class="text-[16px]/6 font-medium tracking-[-0.0125em]"
                        >
                            {{ $t('Upcoming maintenance') }}
                        </h2>
                    </div>
                    <ul>
                        <li
                            v-for="(window, index) in status.upcoming"
                            :key="window.startsAt + index"
                            class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-3"
                            :class="{
                                'border-t-[0.5px] border-(--sp-line)':
                                    index > 0,
                            }"
                        >
                            <span
                                class="text-[14px]/5 font-medium text-(--sp-strong)"
                            >
                                {{ window.reason || $t('Planned maintenance') }}
                                <span
                                    v-if="window.machine"
                                    class="font-normal text-(--sp-muted)"
                                    >· {{ window.machine }}</span
                                >
                            </span>
                            <span
                                class="ml-auto text-[13px]/5 text-(--sp-muted)"
                            >
                                {{ startsIn(window.startsAt, now) }}
                            </span>
                            <span
                                class="basis-full text-[13px]/5 text-(--sp-muted)"
                            >
                                {{
                                    maintenanceWhen(
                                        window.startsAt,
                                        window.endsAt,
                                    )
                                }}
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <section
                class="card mt-6 rounded-lg p-px"
                aria-labelledby="system-status"
            >
                <div class="rounded-[7px] bg-(--sp-card)">
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-(--sp-line) px-4 py-3.5"
                    >
                        <h2
                            id="system-status"
                            class="text-[16px]/6 font-medium tracking-[-0.0125em]"
                        >
                            {{ $t('System status') }}
                        </h2>
                        <span
                            class="text-[14px]/5 font-medium text-(--sp-muted)"
                        >
                            {{ range }}
                        </span>
                        <span
                            v-if="status.uptimePercent !== null"
                            class="ml-auto text-[14px]/5 text-(--sp-muted)"
                        >
                            {{ percent(status.uptimePercent) }},
                            {{ $t('90 days') }}
                        </span>
                    </div>

                    <ul v-if="rows.length">
                        <li
                            v-for="(row, index) in rows"
                            :key="row.key"
                            :class="
                                index > 0 &&
                                'border-t-[0.5px] border-(--sp-line)'
                            "
                        >
                            <div
                                v-if="row.kind === 'service'"
                                class="px-4 py-3"
                            >
                                <div class="flex h-7 items-center gap-2">
                                    <span
                                        class="dot"
                                        :class="`is-${row.component.status}`"
                                        :title="word[row.component.status]"
                                    />
                                    <span
                                        class="min-w-0 truncate text-[14px]/5 font-medium text-(--sp-strong)"
                                    >
                                        {{ row.component.label }}
                                    </span>
                                    <span
                                        class="shrink-0 text-[13px]/5"
                                        :class="`word is-${row.component.status}`"
                                        >{{ word[row.component.status] }}</span
                                    >
                                    <span
                                        v-if="row.component.responseMs"
                                        class="text-[13px]/5 text-(--sp-muted) tabular-nums"
                                        :title="$t('Last response time')"
                                        >{{ row.component.responseMs }} ms</span
                                    >
                                    <span
                                        class="ml-auto shrink-0 text-[14px]/5 text-(--sp-muted)"
                                    >
                                        {{
                                            row.component.uptime
                                                ? percent(
                                                      uptime(
                                                          bars([row.component]),
                                                      ),
                                                  )
                                                : word[row.component.status]
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="row.component.uptime && days.length"
                                    class="bars mt-1"
                                    role="img"
                                    :aria-label="
                                        percent(uptime(bars([row.component])))
                                    "
                                >
                                    <span
                                        v-for="(value, i) in bars([
                                            row.component,
                                        ])"
                                        :key="days[i]"
                                        :class="tone(value)"
                                        :title="dayLabel(i, value)"
                                    />
                                </div>
                            </div>

                            <!-- A group: one row until it's opened -->
                            <template v-else>
                                <div class="px-4 py-3">
                                    <div class="flex h-7 items-center gap-2">
                                        <span
                                            class="dot"
                                            :class="`is-${worst(row.components.map((c) => c.status))}`"
                                            :title="
                                                word[
                                                    worst(
                                                        row.components.map(
                                                            (c) => c.status,
                                                        ),
                                                    )
                                                ]
                                            "
                                        />
                                        <span
                                            class="min-w-0 truncate text-[14px]/5 font-medium text-(--sp-strong)"
                                        >
                                            {{ row.name }}
                                        </span>
                                        <button
                                            type="button"
                                            class="flex cursor-pointer items-center gap-0.5 rounded text-[14px]/5 text-(--sp-muted) hover:text-(--sp-strong) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--sp-focus)"
                                            :aria-expanded="!!opened[row.key]"
                                            :aria-controls="`${row.key}-list`"
                                            @click="toggle(row.key)"
                                        >
                                            {{
                                                row.components.length === 1
                                                    ? $t('1 component')
                                                    : $t(':count components', {
                                                          count: String(
                                                              row.components
                                                                  .length,
                                                          ),
                                                      })
                                            }}
                                            <ChevronRight
                                                class="size-3.5 transition-transform"
                                                :class="
                                                    opened[row.key] &&
                                                    'rotate-90'
                                                "
                                                aria-hidden="true"
                                            />
                                        </button>
                                        <span
                                            class="ml-auto shrink-0 text-[14px]/5 text-(--sp-muted)"
                                        >
                                            {{
                                                percent(
                                                    uptime(
                                                        bars(row.components),
                                                    ),
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="days.length"
                                        class="bars mt-1"
                                        role="img"
                                        :aria-label="
                                            percent(
                                                uptime(bars(row.components)),
                                            )
                                        "
                                    >
                                        <span
                                            v-for="(value, i) in bars(
                                                row.components,
                                            )"
                                            :key="days[i]"
                                            :class="tone(value)"
                                            :title="dayLabel(i, value)"
                                        />
                                    </div>
                                </div>
                                <ul
                                    v-show="opened[row.key]"
                                    :id="`${row.key}-list`"
                                    class="border-t-[0.5px] border-(--sp-line) bg-(--sp-sunken)"
                                >
                                    <li
                                        v-for="(
                                            component, ci
                                        ) in row.components"
                                        :key="component.key"
                                        class="py-3 pr-4 pl-8"
                                        :class="
                                            ci > 0 &&
                                            'border-t-[0.5px] border-(--sp-line)'
                                        "
                                    >
                                        <div
                                            class="flex h-7 items-center gap-2"
                                        >
                                            <span
                                                class="dot"
                                                :class="`is-${component.status}`"
                                                :title="word[component.status]"
                                            />
                                            <span
                                                class="min-w-0 truncate text-[14px]/5 font-medium text-(--sp-strong)"
                                                >{{ component.label }}</span
                                            >
                                            <span
                                                class="shrink-0 text-[13px]/5"
                                                :class="`word is-${component.status}`"
                                                >{{
                                                    word[component.status]
                                                }}</span
                                            >
                                            <span
                                                v-if="component.responseMs"
                                                class="text-[13px]/5 text-(--sp-muted) tabular-nums"
                                                :title="
                                                    $t('Last response time')
                                                "
                                                >{{
                                                    component.responseMs
                                                }}
                                                ms</span
                                            >
                                            <span
                                                class="ml-auto shrink-0 text-[14px]/5 text-(--sp-muted)"
                                            >
                                                {{
                                                    component.uptime
                                                        ? percent(
                                                              uptime(
                                                                  bars([
                                                                      component,
                                                                  ]),
                                                              ),
                                                          )
                                                        : ''
                                                }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="
                                                component.uptime && days.length
                                            "
                                            class="bars mt-1"
                                            role="img"
                                            :aria-label="
                                                percent(
                                                    uptime(bars([component])),
                                                )
                                            "
                                        >
                                            <span
                                                v-for="(value, i) in bars([
                                                    component,
                                                ])"
                                                :key="days[i]"
                                                :class="tone(value)"
                                                :title="dayLabel(i, value)"
                                            />
                                        </div>
                                    </li>
                                </ul>
                            </template>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="px-4 py-10 text-center text-[14px]/5 text-(--sp-muted)"
                    >
                        {{ $t('Nothing is listed on this page yet.') }}
                    </p>
                </div>
            </section>

            <div
                v-if="status.incidents"
                class="mt-6 flex flex-col items-center gap-4"
            >
                <button
                    type="button"
                    class="ghost-button flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[14px]/5 font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--sp-focus)"
                    :aria-expanded="history"
                    aria-controls="incident-history"
                    @click="history = !history"
                >
                    <svg
                        viewBox="0 0 20 20"
                        class="size-4"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <rect x="3" y="4.5" width="14" height="12" rx="2" />
                        <path
                            d="M3 8.5h14M7 3v3M13 3v3"
                            stroke-linecap="round"
                        />
                    </svg>
                    {{ history ? $t('Hide history') : $t('View history') }}
                </button>
                <section
                    v-show="history"
                    id="incident-history"
                    class="card w-full rounded-lg p-px"
                    :aria-label="$t('Past 14 days')"
                >
                    <div class="rounded-[7px] bg-(--sp-card)">
                        <h2
                            class="border-b border-(--sp-line) px-4 py-3.5 text-[16px]/6 font-medium tracking-[-0.0125em]"
                        >
                            {{ $t('Past 14 days') }}
                        </h2>
                        <ul v-if="status.incidents.length">
                            <li
                                v-for="(incident, i) in status.incidents"
                                :key="i"
                                class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-4 py-3 text-[14px]/5"
                                :class="
                                    i > 0 &&
                                    'border-t-[0.5px] border-(--sp-line)'
                                "
                            >
                                <span>
                                    <span
                                        class="font-medium text-(--sp-strong)"
                                        >{{ incident.label }}</span
                                    >
                                    <span class="text-(--sp-muted)">{{
                                        incident.resolvedAt
                                            ? ' ' +
                                              $t('was down for :duration', {
                                                  duration: duration(
                                                      incident.seconds,
                                                  ),
                                              })
                                            : ' ' + $t('is down')
                                    }}</span>
                                </span>
                                <span
                                    class="text-[13px]/5 tabular-nums"
                                    :class="
                                        incident.resolvedAt
                                            ? 'text-(--sp-muted)'
                                            : 'incident-open font-medium'
                                    "
                                >
                                    {{
                                        incident.resolvedAt
                                            ? when(incident.startedAt)
                                            : $t('Since :when', {
                                                  when: when(
                                                      incident.startedAt,
                                                  ),
                                              })
                                    }}
                                </span>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="px-4 py-4 text-[14px]/5 text-(--sp-muted)"
                        >
                            {{ $t('No downtime recorded.') }}
                        </p>
                    </div>
                </section>
            </div>

            <footer
                class="mt-10 flex flex-col items-center gap-2 text-[14px]/5 text-(--sp-muted)"
            >
                <a :href="poweredByUrl" class="hover:text-(--sp-strong)">{{
                    $t('Powered by ServerOS')
                }}</a>
                <a
                    :href="jsonUrl"
                    class="text-[12px]/4 hover:text-(--sp-strong)"
                    >JSON</a
                >
            </footer>
        </main>
    </div>
</template>

<style scoped>
/* Colours from the design, as tokens that follow the visitor's theme. */
.status-page {
    --sp-bg: #ffffff;
    --sp-card: #ffffff;
    --sp-sunken: #fafafa;
    --sp-edge: #e4e4e7;
    --sp-line: #ececee;
    --sp-text: #18181b;
    --sp-strong: #27272a;
    --sp-muted: #71717a;
    --sp-up: #1fa382;
    --sp-up-head: #e8f6f1;
    --sp-degraded: #f59e0b;
    --sp-degraded-head: #fef3e2;
    --sp-down: #e5484d;
    --sp-down-head: #fdecec;
    --sp-unknown: #a1a1aa;
    --sp-unknown-head: #f4f4f5;
    --sp-empty: #e4e4e7;
    --sp-focus: #2563eb;
    background: var(--sp-bg);
    color: var(--sp-text);
    color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    .status-page {
        --sp-bg: #161618;
        --sp-card: #161618;
        --sp-sunken: #1b1b1e;
        --sp-edge: #39393f;
        --sp-line: #29292f;
        --sp-text: #f7f7f8;
        --sp-strong: #e4e4e7;
        --sp-muted: #787882;
        --sp-up-head: #0e493a;
        --sp-degraded-head: #4a3207;
        --sp-down-head: #4c1d1f;
        --sp-unknown: #6b6b75;
        --sp-unknown-head: #26262b;
        --sp-empty: #29292f;
        --sp-focus: #60a5fa;
        color-scheme: dark;
    }
}

/* Cards: a 1px edge drawn by the padding around the inner panel. */
.card {
    background: var(--sp-edge);
}
.banner.is-operational {
    background: var(--sp-up);
}
.banner.is-degraded {
    background: var(--sp-degraded);
}
.banner.is-down {
    background: var(--sp-down);
}
.banner.is-unknown {
    background: var(--sp-edge);
}
.banner.is-operational .banner-head {
    background: var(--sp-up-head);
}
.banner.is-degraded .banner-head {
    background: var(--sp-degraded-head);
}
.banner.is-down .banner-head {
    background: var(--sp-down-head);
}
.banner.is-unknown .banner-head {
    background: var(--sp-unknown-head);
}
.banner.is-operational .banner-icon {
    fill: var(--sp-up);
}
.banner.is-degraded .banner-icon {
    fill: var(--sp-degraded);
}
.banner.is-down .banner-icon {
    fill: var(--sp-down);
}
.banner.is-unknown .banner-icon {
    fill: var(--sp-unknown);
}

.dot {
    width: 8px;
    height: 8px;
    margin-inline: 4px;
    border-radius: 9999px;
    flex-shrink: 0;
}
.dot.is-operational {
    background: var(--sp-up);
}
.dot.is-degraded {
    background: var(--sp-degraded);
}
.dot.is-down {
    background: var(--sp-down);
}
.dot.is-unknown {
    background: var(--sp-unknown);
}

/* A bar per day, chunky like the design's squares. */
.bars {
    display: grid;
    grid-template-columns: repeat(30, minmax(0, 1fr));
    gap: 2.4px;
    height: 16px;
}
.bars > span {
    border-radius: 1px;
}
.bars > .is-up {
    background: var(--sp-up);
}
.bars > .is-degraded {
    background: var(--sp-degraded);
}
.bars > .is-down {
    background: var(--sp-down);
}
.bars > .is-empty {
    background: var(--sp-empty);
}

.ghost-button {
    border: 0.5px solid var(--sp-edge);
    color: var(--sp-text);
}
.ghost-button:hover {
    background: var(--sp-sunken);
}
.incident-open {
    color: var(--sp-down);
}

/* The state in words beside a service's name. */
.word.is-operational {
    color: var(--sp-up);
}
.word.is-degraded {
    color: var(--sp-degraded);
}
.word.is-down {
    color: var(--sp-down);
}
.word.is-unknown {
    color: var(--sp-muted);
}
</style>
