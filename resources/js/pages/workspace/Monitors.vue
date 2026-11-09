<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import {
    SolarPulse as Activity,
    SolarBell as Bell,
    SolarMegaphone as Megaphone,
    SolarRadio as Radio,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import AlertsDialog from '@/components/monitors/AlertsDialog.vue';
import MonitorDialog from '@/components/monitors/MonitorDialog.vue';
import {
    ago,
    dayTone,
    every,
    ms,
    percent,
    span,
    statusLook,
} from '@/components/monitors/monitors';
import type {
    MachineOption,
    Monitor,
    MonitorStatus,
} from '@/components/monitors/monitors';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import IncidentDialog from '@/components/status/IncidentDialog.vue';
import type { Incident } from '@/components/status/IncidentDialog.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    monitors: Monitor[];
    machines: MachineOption[];
    limit: number;
    minInterval: number;
    alerts: {
        email: boolean;
        webhook: string | null;
        webhookSet: boolean;
        muted: string[];
    };
    /** Incidents written for the status pages, open ones first. */
    incidents: Incident[];
    /** The workspace's status page for every machine (Business). */
    statusPage: {
        available: boolean;
        set: boolean;
        live: boolean;
        url: string | null;
    };
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

type Filter = 'all' | MonitorStatus;
const filter = ref<Filter>('all');
const search = ref('');

const tabs = computed(() =>
    (
        [
            ['all', trans('All')],
            ['down', trans('Down')],
            ['up', trans('Up')],
            ['paused', trans('Paused')],
        ] as const
    )
        .map(([value, label]) => ({
            value,
            label,
            count:
                value === 'all'
                    ? props.monitors.length
                    : props.monitors.filter((m) => m.status === value).length,
        }))
        .filter((tab) => tab.value === 'all' || tab.count > 0),
);

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.monitors.filter(
        (m) =>
            (filter.value === 'all' || m.status === filter.value) &&
            (!needle || `${m.name} ${m.target}`.toLowerCase().includes(needle)),
    );
});

const down = computed(() => props.monitors.filter((m) => m.status === 'down'));
const full = computed(() => props.monitors.length >= props.limit);

const creating = ref(false);
const alertsOpen = ref(false);

/* Keep "checked … ago" moving, and pick up new results. */
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    if (
        event.key === '/' &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '')
    ) {
        event.preventDefault();
        searchInput.value?.focus();
    }
}

onMounted(() => {
    // ⌘K › New monitor lands here with ?new=1.
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value &&
        props.monitors.length < props.limit
    ) {
        creating.value = true;
    }
    tick = setInterval(() => (now.value = Date.now()), 5000);
    reload = setInterval(() => {
        if (document.visibilityState === 'visible' && !creating.value) {
            router.reload({ only: ['monitors'] });
        }
    }, 15000);
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
    window.removeEventListener('keydown', onKey);
});

/* Incidents written for the status pages */
const incidentOpen = ref(false);
const incidentShown = ref<Incident | null>(null);
function showIncident(incident: Incident | null): void {
    incidentShown.value = incident;
    incidentOpen.value = true;
}
const incidentColumns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 md:grid-cols-[minmax(0,1.5fr)_8rem_10rem_7rem]';
const impactDot: Record<Incident['impact'], string> = {
    degraded: 'bg-(--ds-amber)',
    partial: 'bg-(--ds-amber)',
    major: 'bg-(--ds-red)',
};
const incidentStates: Record<Incident['state'], string> = {
    investigating: trans('Investigating'),
    identified: trans('Identified'),
    monitoring: trans('Monitoring'),
    resolved: trans('Resolved'),
};

const columns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 md:grid-cols-[minmax(0,1.5fr)_minmax(10rem,1fr)_5rem_5rem_7rem]';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Monitors')" />

    <WorkspacePage :title="$t('Monitors')" toolbar>
        <template #description>
            {{
                ' ' +
                $t(
                    'Your sites, APIs and ports, checked from outside your machines. You hear about it when one stops answering, before your users tell you.',
                ) +
                ' '
            }}
        </template>

        <template v-if="monitors.length > 0" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Filter')"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="filter === tab.value"
                        @click="filter = tab.value"
                    >
                        {{ tab.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                filter === tab.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>
            </div>
        </template>

        <template #actions>
            <label
                v-if="monitors.length > 5"
                class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-64"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{ $t('Search monitors') }}</span>
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    :placeholder="$t('Search monitors')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                />
            </label>
            <!-- Where these checks can be shown to customers (Business) -->
            <Link
                v-if="canManage"
                href="/settings/status-page"
                class="ds-raised-button cursor-pointer text-[13px]!"
                :class="focusRing"
                :title="
                    statusPage.live
                        ? $t('Live at :url', { url: String(statusPage.url) })
                        : $t(
                              'One public page for every machine, with these monitors',
                          )
                "
            >
                <Radio class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Status page') }}
                <span
                    class="flex items-center gap-1.5"
                    :class="
                        statusPage.live
                            ? 'text-(--ds-green)'
                            : 'text-(--ds-text-muted)'
                    "
                >
                    {{
                        statusPage.live
                            ? $t('Live')
                            : statusPage.available
                              ? $t('Off')
                              : $t('Business')
                    }}
                </span>
            </Link>
            <button
                v-if="canManage"
                type="button"
                class="ds-raised-button cursor-pointer text-[13px]!"
                :class="focusRing"
                :title="$t('Tell status page visitors what’s wrong')"
                @click="showIncident(null)"
            >
                <Megaphone class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Incident') }}
            </button>
            <button
                v-if="canManage"
                type="button"
                class="ds-raised-button cursor-pointer text-[13px]!"
                :class="focusRing"
                @click="alertsOpen = true"
            >
                <Bell class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Alerts') }}
                <span
                    class="text-(--ds-text-muted)"
                    :title="
                        [
                            alerts.email ? $t('Email') : null,
                            alerts.webhookSet ? $t('Webhook') : null,
                        ]
                            .filter(Boolean)
                            .join(' and ') || 'Off'
                    "
                >
                    {{
                        alerts.email && alerts.webhookSet
                            ? $t('Email, webhook')
                            : alerts.email
                              ? $t('Email')
                              : alerts.webhookSet
                                ? $t('Webhook')
                                : 'Off'
                    }}
                </span>
            </button>
            <button
                v-if="canManage && monitors.length > 0"
                type="button"
                :class="appButtonClasses('primary')"
                :disabled="full"
                :title="
                    full
                        ? $t('Your plan includes :limit monitors', {
                              limit: String(limit),
                          })
                        : undefined
                "
                @click="creating = true"
            >
                <Plus class="size-3.5" /> {{ $t('New monitor') + ' ' }}
            </button>
        </template>

        <!-- Incidents written for the status pages, open ones first -->
        <div
            v-if="incidents.length"
            class="ds-tile mb-5 overflow-x-auto"
            role="table"
            :aria-label="$t('Incidents')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-5 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="incidentColumns"
                role="row"
            >
                <span role="columnheader">{{ $t('Incident') }}</span>
                <span role="columnheader">{{ $t('Status') }}</span>
                <span role="columnheader">{{ $t('Shown on') }}</span>
                <span role="columnheader" class="text-right">{{
                    $t('Started')
                }}</span>
            </div>
            <button
                v-for="incident in incidents"
                :key="incident.id"
                type="button"
                class="ds-tile-row w-full cursor-pointer px-5 py-3 text-left text-[13px]/4 transition-colors hover:bg-(--ds-row-hover)"
                :class="[incidentColumns, focusRing]"
                role="row"
                @click="showIncident(incident)"
            >
                <span class="flex min-w-0 items-center gap-3" role="cell">
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="
                            incident.resolvedAt
                                ? 'bg-(--ds-green)'
                                : impactDot[incident.impact]
                        "
                        aria-hidden="true"
                    />
                    <span class="truncate font-medium">{{
                        incident.title
                    }}</span>
                </span>
                <span
                    class="hidden text-(--ds-text-secondary) md:block"
                    role="cell"
                    >{{ incidentStates[incident.state] }}</span
                >
                <span
                    class="hidden truncate text-(--ds-text-muted) md:block"
                    role="cell"
                    >{{ incident.machine ?? $t('Workspace page') }}</span
                >
                <span
                    class="text-right text-xs text-(--ds-text-muted) tabular-nums"
                    role="cell"
                    >{{ ago(incident.startedAt, now) }}</span
                >
            </button>
        </div>

        <div
            v-if="monitors.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <Activity class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ $t('No monitors yet') }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Add a URL or a port. ServerOS checks it every :value and emails you, or posts to Slack, when it stops answering.',
                        { value: every(Math.max(60, minInterval)) },
                    ) +
                    ' '
                }}
            </p>
            <button
                v-if="canManage && limit > 0"
                type="button"
                :class="appButtonClasses('primary')"
                class="mt-6"
                @click="creating = true"
            >
                <Plus class="size-3.5" /> {{ $t('New monitor') + ' ' }}
            </button>
            <p
                v-else-if="limit === 0"
                class="mt-4 text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ ' ' + $t('Monitors come with every plan.') + ' ' }}
            </p>
        </div>

        <div v-else class="flex flex-col gap-5">
            <div
                v-if="down.length"
                class="ds-tile overflow-hidden shadow-[inset_3px_0_0_var(--ds-red)]"
                role="status"
            >
                <Link
                    v-for="monitor in down"
                    :key="monitor.id"
                    :href="`/monitors/${monitor.id}`"
                    class="ds-tile-row flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-5 py-3 text-[13px]/5 hover:bg-(--ds-row-hover)"
                >
                    <span class="font-medium text-(--ds-red)">
                        {{ $t(':name is down', { name: monitor.name }) + ' ' }}
                    </span>
                    <span class="text-(--ds-text-secondary)">
                        {{ monitor.error }}
                    </span>
                    <span class="ml-auto text-(--ds-text-muted) tabular-nums">
                        {{
                            ' ' +
                            $t('for :value', {
                                value: span(monitor.since, now),
                            })
                        }}
                    </span>
                </Link>
            </div>

            <div
                class="ds-tile overflow-x-auto"
                role="table"
                :aria-label="$t('Monitors')"
            >
                <div
                    class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-5 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                    :class="columns"
                    role="row"
                >
                    <span role="columnheader">{{ $t('Monitor') }}</span>
                    <span role="columnheader">{{ $t('Last 30 days') }}</span>
                    <span role="columnheader" class="text-right">{{
                        $t('Uptime')
                    }}</span>
                    <span role="columnheader" class="text-right">{{
                        $t('Response')
                    }}</span>
                    <span role="columnheader" class="text-right">{{
                        $t('Checked')
                    }}</span>
                </div>

                <Link
                    v-for="monitor in shown"
                    :key="monitor.id"
                    :href="`/monitors/${monitor.id}`"
                    class="ds-tile-row px-5 py-3 text-[13px]/4 transition-colors hover:bg-(--ds-row-hover)"
                    :class="[columns, focusRing]"
                    role="row"
                >
                    <span class="flex min-w-0 items-center gap-3" role="cell">
                        <span
                            class="relative flex size-2 shrink-0"
                            :title="statusLook[monitor.status].label"
                        >
                            <span
                                v-if="monitor.status === 'down'"
                                class="absolute inline-flex size-full animate-ping rounded-full bg-(--ds-red) opacity-60 motion-reduce:hidden"
                            />
                            <span
                                class="relative inline-flex size-2 rounded-full"
                                :class="[
                                    statusLook[monitor.status].dot,
                                    monitor.status === 'paused'
                                        ? 'opacity-50'
                                        : '',
                                ]"
                            />
                        </span>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-medium">
                                {{ monitor.name }}
                            </span>
                            <span
                                class="truncate font-ui-mono text-xs text-(--ds-text-muted)"
                            >
                                {{ monitor.target }}
                            </span>
                        </span>
                    </span>

                    <span
                        class="hidden h-5 gap-px md:flex"
                        role="cell"
                        :aria-label="`${percent(monitor.uptime30)} over 30 days`"
                    >
                        <span
                            v-for="(day, i) in monitor.days"
                            :key="i"
                            class="min-w-0 flex-1 rounded-[1px]"
                            :class="dayTone(day)"
                        />
                    </span>

                    <span
                        class="hidden text-right tabular-nums md:block"
                        role="cell"
                    >
                        {{ percent(monitor.uptime30) }}
                    </span>

                    <span
                        class="hidden text-right tabular-nums md:block"
                        role="cell"
                    >
                        {{
                            monitor.status === 'up'
                                ? ms(monitor.responseMs)
                                : '—'
                        }}
                    </span>

                    <span
                        class="text-right text-xs text-(--ds-text-muted) tabular-nums"
                        role="cell"
                    >
                        <span
                            v-if="monitor.status === 'paused'"
                            class="text-(--ds-text-muted)"
                        >
                            {{ ' ' + $t('Paused') + ' ' }}
                        </span>
                        <template v-else>
                            {{ ago(monitor.lastCheckedAt, now) }}
                            <span
                                class="block text-(--ds-text-faint) md:hidden"
                            >
                                {{ percent(monitor.uptime30) }}
                            </span>
                        </template>
                    </span>
                </Link>

                <p
                    v-if="shown.length === 0"
                    class="px-5 py-10 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('Nothing matches.') + ' ' }}
                </p>
            </div>

            <p class="text-xs/4 text-(--ds-text-faint)">
                {{
                    $t(':length of :limit monitors on your plan.', {
                        length: String(monitors.length ?? ''),
                        limit: String(limit ?? ''),
                    }) + ' '
                }}
            </p>
        </div>

        <MonitorDialog
            v-model:open="creating"
            :machines="machines"
            :min-interval="minInterval"
        />
        <AlertsDialog v-model:open="alertsOpen" :alerts="alerts" />
        <IncidentDialog
            v-model:open="incidentOpen"
            :incident="incidentShown"
            :machines="machines"
            :can-manage="canManage"
        />
    </WorkspacePage>
</template>
