<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import {
    SolarBellOff as BellOff,
    SolarPause as Pause,
    SolarPencil as Pencil,
    SolarPlay as Play,
    SolarRefreshCw as RefreshCw,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import MaintenanceBanner from '@/components/maintenance/MaintenanceBanner.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import TimeSeriesChart from '@/components/machines/TimeSeriesChart.vue';
import MonitorDialog from '@/components/monitors/MonitorDialog.vue';
import {
    ago,
    dayTone,
    daysUntil,
    duration,
    every,
    ms,
    percent,
    span,
    statusLook,
} from '@/components/monitors/monitors';
import type { MachineOption, Monitor } from '@/components/monitors/monitors';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import type { Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    monitor: Monitor;
    machines: MachineOption[];
    minInterval: number;
    range: '24h' | '7d';
    chart: {
        timestamps: number[];
        response: (number | null)[];
        failed: number[];
    };
    uptime: {
        day: number | null;
        week: number | null;
        month: number | null;
        quarter: number | null;
    };
    days: { day: string; percent: number | null }[];
    incidents: {
        id: number;
        startedAt: string;
        resolvedAt: string | null;
        cause: string;
        seconds: number;
    }[];
    checks: {
        id: number;
        at: string;
        ok: boolean;
        ms: number | null;
        code: number | null;
        error: string | null;
    }[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const look = computed(() => statusLook[props.monitor.status]);
const machine = computed(
    () => props.machines.find((m) => m.id === props.monitor.machineId) ?? null,
);

const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    tick = setInterval(() => (now.value = Date.now()), 5000);
    reload = setInterval(() => {
        if (document.visibilityState === 'visible' && !editing.value) {
            router.reload({ except: ['machines', 'workspace'] });
        }
    }, 20000);
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
});

const statusLine = computed(() => {
    const m = props.monitor;
    if (m.status === 'paused') return trans('Paused');
    if (m.status === 'pending')
        return m.error
            ? trans('Failing: :error', { error: String(m.error) })
            : trans('Waiting for the first check');
    if (!m.since) return look.value.label;

    return `${look.value.label} for ${span(m.since, now.value)}`;
});

const certificate = computed(() => {
    const days = daysUntil(props.monitor.sslExpiresAt, now.value);
    if (days === null) return null;

    return {
        days,
        date: new Date(props.monitor.sslExpiresAt!).toLocaleDateString(
            undefined,
            { day: 'numeric', month: 'short', year: 'numeric' },
        ),
        tone:
            days < 7 ? 'text-(--ds-red)' : days < 14 ? 'text-(--ds-amber)' : '',
    };
});

const averageMs = computed(() => {
    const values = props.chart.response.filter((v): v is number => v !== null);

    return values.length
        ? Math.round(values.reduce((a, b) => a + b, 0) / values.length)
        : null;
});

const failedBuckets = computed(() => props.chart.failed.some((f) => f > 0));

const busy = ref<'check' | 'pause' | null>(null);
const editing = ref(false);
const removing = ref(false);

function checkNow(): void {
    busy.value = 'check';
    router.post(
        `/monitors/${props.monitor.id}/check`,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function mute(minutes: number): void {
    router.post(
        `/monitors/${props.monitor.id}/mute`,
        { minutes },
        { preserveScroll: true },
    );
}

function togglePause(): void {
    busy.value = 'pause';
    router.patch(
        `/monitors/${props.monitor.id}/pause`,
        { paused: !props.monitor.paused },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function remove(): void {
    router.delete(`/monitors/${props.monitor.id}`);
}

function setRange(range: '24h' | '7d'): void {
    router.get(
        `/monitors/${props.monitor.id}`,
        range === '24h' ? {} : { range },
        { only: ['chart', 'range'], preserveScroll: true, replace: true },
    );
}

function when(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function dayLabel(day: string, value: number | null): string {
    const date = new Date(`${day}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });

    return value === null
        ? trans(':date: no checks', { date: String(date) })
        : `${date}: ${value}% up`;
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="monitor.name" />

    <WorkspacePage :title="monitor.name">
        <template #title>
            <span class="flex min-w-0 items-center gap-3">
                <span
                    class="relative flex size-2.5 shrink-0"
                    aria-hidden="true"
                >
                    <span
                        v-if="monitor.status === 'down'"
                        class="absolute inline-flex size-full animate-ping rounded-full bg-(--ds-red) opacity-60 motion-reduce:hidden"
                    />
                    <span
                        class="relative inline-flex size-2.5 rounded-full"
                        :class="look.dot"
                    />
                </span>
                <h1
                    class="truncate text-[21px]/7 font-semibold tracking-[-0.01em]"
                >
                    {{ monitor.name }}
                </h1>
            </span>
        </template>

        <template #description>
            <span :class="look.text" class="font-medium">{{ statusLine }}</span>
            ·
            <a
                v-if="monitor.type === 'http'"
                :href="monitor.url ?? undefined"
                target="_blank"
                rel="noopener noreferrer"
                class="font-ui-mono text-[13px] text-(--ds-text) hover:underline hover:underline-offset-4"
                >{{ monitor.target }}</a
            >
            <span v-else class="font-ui-mono text-[13px] text-(--ds-text)">{{
                monitor.target
            }}</span>
            {{ $t('· every :value', { value: every(monitor.interval) }) }}
            <template v-if="machine">
                {{ ' ' + $t('· on') }}
                <a
                    :href="`/machines/${machine.id}`"
                    class="text-(--ds-text) hover:underline hover:underline-offset-4"
                    >{{ machine.name }}</a
                >
            </template>
        </template>

        <template v-if="canManage" #actions>
            <button
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :disabled="busy !== null || monitor.paused"
                @click="checkNow"
            >
                <RefreshCw
                    class="size-3.5 opacity-60"
                    :class="{ 'mi-spin': busy === 'check' }"
                    :stroke-width="1.75"
                />
                {{ $t('Check now') + ' ' }}
            </button>
            <button
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :disabled="busy !== null"
                @click="togglePause"
            >
                <component
                    :is="monitor.paused ? Play : Pause"
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                />
                {{ monitor.paused ? $t('Resume') : $t('Pause') }}
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer px-2!"
                        :class="focusRing"
                        :aria-label="$t('More actions')"
                    >
                        <Ellipsis class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="editing = true">
                        <Pencil class="size-4" :stroke-width="1.75" />
                        {{ $t('Edit monitor') + ' ' }}
                    </DropdownMenuItem>
                    <template v-if="!monitor.mutedUntil">
                        <DropdownMenuItem
                            v-for="[minutes, label] in [
                                [60, '1 hour'],
                                [240, '4 hours'],
                                [1440, '1 day'],
                            ] as const"
                            :key="minutes"
                            @select="mute(minutes)"
                        >
                            <BellOff class="size-4" :stroke-width="1.75" />
                            {{ $t('Mute alerts for :label', { label }) }}
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuItem
                        class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                        @select="removing = true"
                    >
                        <Trash2
                            class="size-4 text-current"
                            :stroke-width="1.75"
                        />
                        {{ $t('Delete monitor') + ' ' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <div class="flex flex-col gap-5">
            <MaintenanceBanner
                v-if="monitor.mutedUntil"
                :until="monitor.mutedUntil"
                :end-url="`/monitors/${monitor.id}/mute`"
                :can-manage="canManage"
                noun="Alerts muted"
            />
            <div
                v-if="monitor.status === 'down'"
                class="ds-tile px-5 py-3.5 text-[13px]/5 shadow-[inset_3px_0_0_var(--ds-red)]"
                role="status"
            >
                <span class="font-medium text-(--ds-red)">{{
                    $t('Down since :value.', {
                        value: monitor.since
                            ? when(monitor.since)
                            : $t('just now'),
                    })
                }}</span
                >{{ ' ' }}
                <span class="text-(--ds-text-secondary)">
                    {{
                        $t(
                            ":error Checking again every :value; you'll hear when it's back.",
                            {
                                error: String(monitor.error ?? ''),
                                value: every(monitor.interval),
                            },
                        )
                    }}
                </span>
            </div>

            <div
                class="ds-tile grid grid-cols-2 divide-(--ds-divider) md:grid-cols-5 md:divide-x"
            >
                <div
                    v-for="item in [
                        { label: 'Uptime, 24 h', value: percent(uptime.day) },
                        { label: '7 days', value: percent(uptime.week) },
                        { label: '30 days', value: percent(uptime.month) },
                        {
                            label: 'Response, avg',
                            value: ms(averageMs),
                        },
                    ]"
                    :key="item.label"
                    class="flex flex-col gap-1 px-5 py-4"
                >
                    <span class="text-xs/4 text-(--ds-text-muted)">
                        {{ item.label }}
                    </span>
                    <span class="text-xl/7 font-semibold tabular-nums">
                        {{ item.value }}
                    </span>
                </div>
                <div class="flex flex-col gap-1 px-5 py-4">
                    <span class="text-xs/4 text-(--ds-text-muted)">
                        {{
                            monitor.type === 'http' &&
                            monitor.url?.startsWith('https')
                                ? $t('Certificate')
                                : $t('Last check')
                        }}
                    </span>
                    <template
                        v-if="
                            monitor.type === 'http' &&
                            monitor.url?.startsWith('https')
                        "
                    >
                        <span
                            v-if="certificate"
                            class="text-xl/7 font-semibold tabular-nums"
                            :class="certificate.tone"
                            :title="
                                $t('Expires :date', {
                                    date: String(certificate.date),
                                })
                            "
                        >
                            {{
                                certificate.days < 0
                                    ? $t('Expired')
                                    : `${certificate.days} days`
                            }}
                        </span>
                        <span v-else class="text-xl/7 font-semibold">—</span>
                    </template>
                    <span v-else class="text-xl/7 font-semibold tabular-nums">
                        {{ ago(monitor.lastCheckedAt, now) }}
                    </span>
                </div>
            </div>

            <section
                class="flex flex-col gap-3"
                :aria-label="$t('Response time')"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-[13px]/5 font-semibold">
                        {{ $t('Response time') }}
                    </h2>
                    <div
                        class="ds-segments"
                        role="group"
                        :aria-label="$t('Range')"
                    >
                        <button
                            v-for="option in ['24h', '7d'] as const"
                            :key="option"
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="range === option"
                            @click="setRange(option)"
                        >
                            {{ option === '24h' ? '24 hours' : '7 days' }}
                        </button>
                    </div>
                </div>
                <TimeSeriesChart
                    :title="
                        range === '24h'
                            ? $t('Average every 10 minutes')
                            : $t('Average every 70 minutes')
                    "
                    :timestamps="chart.timestamps"
                    :series="[
                        {
                            label: $t('Response'),
                            color: '#60A5FA',
                            values: chart.response,
                        },
                    ]"
                    :format="(v: number) => ms(Math.round(v))"
                    :min-max="100"
                />
                <div
                    v-if="failedBuckets"
                    class="flex items-center gap-3 text-[11px]/4 text-(--ds-text-muted)"
                >
                    <span class="shrink-0">{{ $t('Failed checks') }}</span>
                    <span
                        class="flex h-1.5 grow gap-px"
                        role="img"
                        :aria-label="$t('When checks failed over the range')"
                    >
                        <span
                            v-for="(failed, i) in chart.failed"
                            :key="i"
                            class="flex-1 rounded-[1px]"
                            :class="
                                failed > 0
                                    ? 'bg-(--ds-red)'
                                    : 'bg-(--ds-active)'
                            "
                        />
                    </span>
                </div>
            </section>

            <section class="ds-tile px-5 py-4" :aria-label="$t('Last 90 days')">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-[13px]/5 font-semibold">
                        {{ $t('Last 90 days') }}
                    </h2>
                    <span class="text-xs/4 text-(--ds-text-muted) tabular-nums">
                        {{
                            $t(':value up', {
                                value: percent(uptime.quarter),
                            }) + ' '
                        }}
                    </span>
                </div>
                <div class="mt-3 flex h-7 gap-px">
                    <span
                        v-for="(day, i) in days"
                        :key="day.day"
                        class="min-w-0 flex-1 rounded-[1px]"
                        :class="[
                            dayTone(day.percent),
                            { 'max-sm:hidden': i < days.length - 30 },
                        ]"
                        :title="dayLabel(day.day, day.percent)"
                    />
                </div>
                <div
                    class="mt-1.5 flex justify-between text-[11px]/4 text-(--ds-text-faint)"
                >
                    <span class="max-sm:hidden">{{ $t('90 days ago') }}</span>
                    <span class="sm:hidden">{{ $t('30 days ago') }}</span>
                    <span>{{ $t('Today') }}</span>
                </div>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section
                    class="ds-tile overflow-hidden"
                    :aria-label="$t('Incidents')"
                >
                    <h2
                        class="border-b border-(--ds-divider) px-5 py-3 text-[13px]/5 font-semibold"
                    >
                        {{ ' ' + $t('Incidents') + ' ' }}
                    </h2>
                    <ul v-if="incidents.length">
                        <li
                            v-for="incident in incidents"
                            :key="incident.id"
                            class="ds-tile-row flex flex-col gap-0.5 px-5 py-3 text-[13px]/5"
                        >
                            <span
                                class="flex items-baseline justify-between gap-3"
                            >
                                <span class="font-medium">
                                    {{ when(incident.startedAt) }}
                                </span>
                                <span
                                    class="shrink-0 text-xs tabular-nums"
                                    :class="
                                        incident.resolvedAt
                                            ? 'text-(--ds-text-muted)'
                                            : 'font-medium text-(--ds-red)'
                                    "
                                >
                                    {{
                                        incident.resolvedAt
                                            ? $t('Down :duration', {
                                                  duration: String(
                                                      duration(
                                                          incident.seconds,
                                                      ),
                                                  ),
                                              })
                                            : $t('Ongoing, :span', {
                                                  span: String(
                                                      span(
                                                          incident.startedAt,
                                                          now,
                                                      ),
                                                  ),
                                              })
                                    }}
                                </span>
                            </span>
                            <span class="text-(--ds-text-secondary)">
                                {{ incident.cause }}
                            </span>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="px-5 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{ ' ' + $t('No downtime recorded.') + ' ' }}
                    </p>
                </section>

                <section
                    class="ds-tile overflow-hidden"
                    :aria-label="$t('Recent checks')"
                >
                    <h2
                        class="border-b border-(--ds-divider) px-5 py-3 text-[13px]/5 font-semibold"
                    >
                        {{ ' ' + $t('Recent checks') + ' ' }}
                    </h2>
                    <ul v-if="checks.length">
                        <li
                            v-for="check in checks"
                            :key="check.id"
                            class="ds-tile-row grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 px-5 py-2 text-[13px]/5"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="
                                    check.ok
                                        ? 'bg-(--ds-green)'
                                        : 'bg-(--ds-red)'
                                "
                            />
                            <span
                                class="truncate"
                                :class="
                                    check.ok
                                        ? 'text-(--ds-text-secondary)'
                                        : 'text-(--ds-red)'
                                "
                                :title="check.error ?? undefined"
                            >
                                <template v-if="check.ok">
                                    {{ check.code ? `${check.code} · ` : ''
                                    }}{{ ms(check.ms) }}
                                </template>
                                <template v-else>{{ check.error }}</template>
                            </span>
                            <time
                                class="text-xs text-(--ds-text-muted) tabular-nums"
                                :datetime="check.at"
                            >
                                {{ ago(check.at, now) }}
                            </time>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="px-5 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t('The first check runs within a few seconds.') +
                            ' '
                        }}
                    </p>
                </section>
            </div>
        </div>

        <MonitorDialog
            v-model:open="editing"
            :monitor="monitor"
            :machines="machines"
            :min-interval="minInterval"
        />

        <Dialog v-model:open="removing">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <div class="flex flex-col gap-1 p-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{
                            ' ' +
                            $t('Delete :name?', { name: monitor.name }) +
                            ' '
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Its checks, incidents and uptime history go with it. Pause it instead to keep the history.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="removing = false"
                    >
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <form @submit.prevent="remove">
                        <DangerButton :label="$t('Delete monitor')" />
                    </form>
                </div>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
