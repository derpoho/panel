<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import DiskUsagePanel from '@/components/machines/DiskUsagePanel.vue';
import ProviderName from '@/components/machines/ProviderName.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Check,
    ChevronDown,
    ChevronRight,
    Ellipsis,
    LoaderCircle,
    Plus,
    Search,
} from '@lucide/vue';
import {
    SolarCpu as Cpu,
    SolarCircleCheck as CircleCheck,
    SolarCircleSlash as CircleSlash,
    SolarCircleX as CircleX,
    SolarHardDrive as HardDrive,
    SolarLayers as Layers,
    SolarMemoryStick as MemoryStick,
    SolarRefreshCw as RefreshCw,
    SolarSquareTerminal as SquareTerminal,
    SolarShieldCheck as ShieldCheck,
    SolarCircleArrowUp as CircleArrowUp,
    SolarSettings as Settings,
    SolarWrench as Wrench,
} from '@/lib/solarIcons';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { appButtonClasses } from '@/lib/appButton';
import { toast } from '@/lib/toast';
import type { Machine } from '@/components/machines/machines';
import FileBrowser from '@/components/machines/FileBrowser.vue';
import FirewallPanel from '@/components/machines/FirewallPanel.vue';
import type { FilePlace } from '@/components/machines/FileBrowser.vue';
import MachineConsole from '@/components/machines/MachineConsole.vue';
import JobLogDialog from '@/components/machines/JobLogDialog.vue';
import SecurityScan from '@/components/machines/SecurityScan.vue';
import UpdatesDialog from '@/components/machines/UpdatesDialog.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DaemonJob } from '@/components/machines/JobLogDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { bytes, statusGroup } from '@/components/machines/serviceFormat';
import ServicesTable from '@/components/machines/ServicesTable.vue';
import type { BuildingService } from '@/components/machines/ServicesTable.vue';
import Sparkline from '@/components/machines/Sparkline.vue';
import TimeSeriesChart from '@/components/machines/TimeSeriesChart.vue';
import type {
    ServiceGroup,
    ServiceStat,
} from '@/components/machines/ServicesTable.vue';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import MaintenanceBanner from '@/components/maintenance/MaintenanceBanner.vue';
import MachineIssues from '@/components/machines/MachineIssues.vue';
import type { HealthIssue } from '@/lib/healthIssues';
import MaintenanceDialog from '@/components/maintenance/MaintenanceDialog.vue';
import PlannedMaintenance from '@/components/maintenance/PlannedMaintenance.vue';
import DeployWorkflowsMenu from '@/components/machines/DeployWorkflowsMenu.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePathTab } from '@/composables/usePathTab';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { timeAgo } from '@/lib/timeAgo';
import type { Component } from 'vue';
import { jobStatus } from '@/lib/jobStatus';
import type { Tone } from '@/lib/jobStatus';
import type { Workspace, WorkspaceAccess } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

// The deploy dialog and its catalogue's brand icons arrive just after the
// page, rather than holding up its first paint.
const DeployServiceDialog = defineAsyncComponent(
    () => import('@/components/workspace/DeployServiceDialog.vue'),
);

/** A cron job or systemd timer the last scan found. */
type ScheduledTask = {
    command: string;
    schedule: string;
    /** The schedule in plain words, when it's one we can say. */
    when: string | null;
    user: string | null;
    kind: 'cron' | 'timer';
    source: string;
    description: string | null;
    nextRun: string | null;
    lastRun: string | null;
    service: { key: string; name: string } | null;
    ours: boolean;
};

type UsagePoint = {
    ts: number;
    cpu: number | null;
    cpuMax: number | null;
    mem: number | null;
    memMax: number | null;
    disk: number | null;
};

const props = defineProps<{
    workspace: Workspace;
    machine: Machine & { serviceStats?: Record<string, ServiceStat> };
    jobs: DaemonJob[];
    deployEnabled: boolean;
    /** Whether workflows are on the plan, and how many already react to deploys. */
    deployWorkflows: { available: boolean; count: number };
    templates: ServiceTemplate[];
    serviceGroups: ServiceGroup[];
    scheduled: ScheduledTask[];
    /** The last hour, a point a minute, for the stat tiles. */
    recent: UsagePoint[];
    range: string;
    /** Loaded once the Usage tab opens. */
    history?: UsagePoint[];
    files: { places: FilePlace[]; maxWrite: number; maxRead: number };
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    /** What's wrong with it, as Home lists it; Ask AI can fix some. */
    issues?: HealthIssue[];
}>();
const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();

/* The console: commands in any of this machine's containers. A link with
   ?console opens it straight away (the machine menu's Console). */
const consoleOpen = ref(
    typeof window !== 'undefined' &&
        new URLSearchParams(window.location.search).has('console'),
);
const consoleContainers = computed(() =>
    (props.machine.inventory?.services ?? [])
        .filter(
            (service) => service.key.startsWith('docker:') && !service.hidden,
        )
        .map((service) => ({
            key: service.key,
            name: service.label ?? service.app ?? service.found ?? service.name,
        })),
);
const maintenanceOpen = ref(false);
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);
/*
 * A service being removed leaves the list as soon as the remove is sent,
 * not at the next reload, and the page checks often until it's done.
 */
const removingKeys = computed(
    () =>
        new Set(
            props.jobs
                .filter(
                    (job) =>
                        job.kind === 'service_remove' &&
                        !!job.service &&
                        ['queued', 'accepted', 'running', 'succeeded'].includes(
                            job.state,
                        ) &&
                        Date.now() - new Date(job.createdAt ?? 0).getTime() <
                            10 * 60_000,
                )
                .map((job) => job.service as string),
        ),
);
const listedServices = computed(() =>
    (props.machine.inventory?.services ?? []).filter(
        (service) => !removingKeys.value.has(service.key),
    ),
);
/*
 * Deploys the last scan doesn't show yet sit at the top of Services while
 * they build, so a new service shows up without a reload. A finished one
 * stays as "Starting" until the scan that runs after a deploy brings in
 * its real row; one that failed stays a few minutes, to open its log.
 */
const buildingServices = computed<BuildingService[]>(() => {
    const inScan = new Set<string>();
    for (const service of props.machine.inventory?.services ?? []) {
        const project = service.details?.compose_project;
        if (project?.startsWith('serveros-')) {
            inScan.add(project.slice('serveros-'.length));
        }
        if (service.app) {
            inScan.add(service.app);
        }
    }
    const scannedAt = (props.machine.inventory?.scanned_at ?? 0) * 1000;
    const seen = new Set<string>();
    const rows: BuildingService[] = [];

    // Newest first, so a redeploy's latest job speaks for its service.
    for (const job of props.jobs) {
        if (job.kind !== 'deploy' || !job.service || seen.has(job.service)) {
            continue;
        }
        seen.add(job.service);
        const created = new Date(job.createdAt ?? 0).getTime();
        const moving = ['queued', 'accepted', 'running'].includes(job.state);
        const recent = Date.now() - created < 10 * 60_000;
        // A redeploy of something already listed shows on its own row.
        if (inScan.has(job.service) || (!moving && !recent)) {
            continue;
        }

        let state: BuildingService['state'];
        if (job.state === 'queued' || job.state === 'accepted') {
            state = 'queued';
        } else if (job.state === 'running') {
            state = 'building';
        } else if (job.state === 'succeeded') {
            // Built and scanned since, but not found: nothing to wait for.
            if (scannedAt > created + 60_000) continue;
            state = 'starting';
        } else if (['failed', 'timed_out'].includes(job.state)) {
            state = 'failed';
        } else {
            continue;
        }

        rows.push({
            jobId: job.id,
            name: job.service,
            state,
            phase: job.phase,
            progress: job.progress,
        });
    }

    return rows;
});
const removeInFlight = computed(() =>
    props.jobs.some(
        (job) =>
            job.kind === 'service_remove' &&
            ['queued', 'accepted', 'running'].includes(job.state),
    ),
);
let removePoll: ReturnType<typeof setInterval> | undefined;
watch(
    removeInFlight,
    (active) => {
        clearInterval(removePoll);
        removePoll = active ? setInterval(refresh, 1500) : undefined;
    },
    { immediate: true },
);

/**
 * Deploys need a plan (a self-hosted install needs none); everything
 * already running keeps running.
 */
const hasPlan = computed(
    () =>
        props.workspace.billing.plan !== null ||
        props.workspace.billing.selfHosted === true,
);
const canDeploy = computed(
    () =>
        canManage.value && props.deployEnabled && online.value && hasPlan.value,
);
const tab = usePathTab(`/machines/${props.machine.id}`, [
    'services',
    'scheduled',
    'usage',
    'ports',
    'files',
    'specs',
    'deployments',
] as const);

/* Usage: charts over a chosen window, loaded when the tab opens. */

const ranges = ['1h', '6h', '24h', '7d', '30d'];
const loadingHistory = ref(false);

function loadHistory(range = props.range): void {
    router.get(
        window.location.pathname,
        { range },
        {
            only: ['history', 'range'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loadingHistory.value = true),
            onFinish: () => (loadingHistory.value = false),
        },
    );
}

watch(tab, (value) => {
    if (value === 'usage' && !props.history) loadHistory();
});

const history = computed(() => props.history ?? []);
const timestamps = computed(() => history.value.map((p) => p.ts));
const percent = (v: number) => `${v < 10 ? v.toFixed(1) : Math.round(v)}%`;

/* Stat tiles: what's happening now, with the last hour beneath it. */

function recentPeak(pick: (point: UsagePoint) => number | null): number | null {
    const values = props.recent
        .map(pick)
        .filter((value): value is number => value !== null);
    return values.length ? Math.max(...values) : null;
}

/** Telemetry lists disks fullest first. */
const fullestDisk = computed(() => props.machine.disks?.[0] ?? null);

/** The tile shows the fullest disk; this says there are others (Specs lists them). */
const otherDisks = computed(() => {
    const more = (props.machine.disks?.length ?? 0) - 1;
    if (more < 1) return '';
    return (
        ' · ' +
        trans(more === 1 ? '+:count disk' : '+:count disks', {
            count: String(more),
        })
    );
});

const chartTiles = computed(() => {
    const cpuPeak = recentPeak((p) => p.cpuMax);
    const memPeak = recentPeak((p) => p.memMax);
    const diskPeak = recentPeak((p) => p.disk);
    const disk = fullestDisk.value;

    return [
        {
            label: trans('CPU'),
            icon: Cpu,
            value: `${props.machine.cpu}%`,
            detail: trans('right now'),
            tone: 'text-(--ds-blue)',
            values: props.recent.map((p) => p.cpu),
            floor: 10,
            max: 100,
            peak: cpuPeak === null ? null : percent(cpuPeak),
        },
        {
            label: trans('Memory used'),
            icon: MemoryStick,
            value: props.machine.memory,
            detail: trans('of :total', {
                total: formatBytes(props.machine.facts?.memory_bytes),
            }),
            tone: 'text-(--ds-green)',
            values: props.recent.map((p) => p.mem),
            floor: 0,
            max: props.machine.facts?.memory_bytes || undefined,
            peak: memPeak === null ? null : bytes(memPeak),
        },
        {
            label: trans('Disk'),
            icon: HardDrive,
            value:
                props.machine.diskPercent === null ||
                props.machine.diskPercent === undefined
                    ? '—'
                    : `${props.machine.diskPercent}%`,
            detail: disk
                ? trans(':value free on :mount', {
                      value: String(bytes(Math.max(0, disk.total - disk.used))),
                      mount: String(disk.mount),
                  }) + otherDisks.value
                : trans('no disk readings'),
            tone: 'text-(--ds-amber)',
            values: props.recent.map((p) => p.disk),
            floor: 10,
            max: 100,
            peak: diskPeak === null ? null : `${diskPeak}%`,
        },
    ];
});

const serviceCounts = computed(() => {
    const services = props.machine.inventory?.services ?? [];
    return (
        [
            ['running', trans('Running').toLowerCase(), 'bg-(--ds-green)'],
            ['stopped', trans('Stopped').toLowerCase(), 'bg-(--ds-text-faint)'],
            ['problems', trans('with problems'), 'bg-(--ds-red)'],
        ] as const
    ).map(([group, label, color]) => {
        const count = services.filter(
            (service) => statusGroup(service) === group,
        ).length;
        return {
            group,
            label,
            color,
            count,
            share: services.length ? (count / services.length) * 100 : 0,
        };
    });
});

/* Deployments: filter by status, search by service, sort. */

type JobFilter = 'all' | 'active' | 'done' | 'failed';
type JobSort = 'newest' | 'oldest' | 'service';

const jobFilter = ref<JobFilter>('all');

/* The log modal follows the job by id, so polling keeps it live. */
const logJobId = ref<string | null>(null);
const logOpen = ref(false);
const logJob = computed(
    () => props.jobs.find((job) => job.id === logJobId.value) ?? null,
);

/* The deployments list: an icon and colour per state, and one detail. */
const jobTone: Record<Tone, string> = {
    green: 'text-(--ds-green)',
    red: 'text-(--ds-red)',
    amber: 'text-(--ds-amber)',
    blue: 'text-(--ds-blue)',
    neutral: 'text-(--ds-text-faint)',
};

function jobIcon(job: DaemonJob): Component {
    const { tone, busy } = jobStatus(job.state);
    if (busy) return LoaderCircle;
    if (tone === 'green') return CircleCheck;
    if (tone === 'red') return CircleX;
    return CircleSlash;
}

/** Why it failed, where it runs, or which step it's on. */
function jobDetail(job: DaemonJob): string {
    if (job.error) return job.explanation?.cause ?? job.error.message ?? '';
    if (job.state === 'succeeded' && job.result?.container) {
        return trans('Container: :container · port :port', {
            container: String(job.result.container ?? ''),
            port: String(job.result.port ?? ''),
        });
    }

    return '';
}

function openLog(job: DaemonJob): void {
    logJobId.value = job.id;
    logOpen.value = true;
}

function openLogById(jobId: string): void {
    logJobId.value = jobId;
    logOpen.value = true;
}

function onRetried(jobId: string): void {
    logJobId.value = jobId;
    refresh();
}

function openLibraryFromLog(): void {
    logOpen.value = false;
    showDeploy.value = true;
}
const jobSearch = ref('');
const jobSort = ref<JobSort>('newest');

const FAILED_STATES = ['failed', 'refused', 'timed_out', 'cancelled'];

function jobGroup(state: string): Exclude<JobFilter, 'all'> {
    if (state === 'succeeded') return 'done';
    if (FAILED_STATES.includes(state)) return 'failed';

    return 'active';
}

/** The Deployments tab's jobs: not console commands, which the console shows. */
const deployJobs = computed(() =>
    props.jobs.filter((job) => job.kind !== 'service_exec'),
);

const jobTabs = computed(() =>
    (
        [
            ['all', trans('All')],
            ['active', trans('In progress')],
            ['done', trans('Done')],
            ['failed', trans('Failed')],
        ] as const
    ).map(([value, label]) => ({
        value,
        label,
        count:
            value === 'all'
                ? deployJobs.value.length
                : deployJobs.value.filter(
                      (job) => jobGroup(job.state) === value,
                  ).length,
    })),
);

const jobSortOptions: { value: JobSort; label: string }[] = [
    { value: 'newest', label: trans('Newest') },
    { value: 'oldest', label: trans('Oldest') },
    { value: 'service', label: trans('Service') },
];

const jobSortLabel = computed(
    () =>
        jobSortOptions.find((option) => option.value === jobSort.value)
            ?.label ?? trans('Newest'),
);

const visibleJobs = computed(() => {
    const needle = jobSearch.value.trim().toLowerCase();
    const time = (job: DaemonJob) =>
        job.createdAt ? Date.parse(job.createdAt) : 0;

    return deployJobs.value
        .filter(
            (job) =>
                jobFilter.value === 'all' ||
                jobGroup(job.state) === jobFilter.value,
        )
        .filter(
            (job) =>
                !needle || (job.service ?? '').toLowerCase().includes(needle),
        )
        .sort((a, b) =>
            jobSort.value === 'service'
                ? (a.service ?? '').localeCompare(b.service ?? '')
                : jobSort.value === 'oldest'
                  ? time(a) - time(b)
                  : time(b) - time(a),
        );
});
const showDeploy = ref(false);
const scanning = ref(false);
const updatesOpen = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

function formatBytes(bytes: number | undefined): string {
    if (!bytes || bytes < 0) return trans('Unknown');
    return `${(bytes / 1024 ** 3).toFixed(1)} GiB`;
}

function formatDate(timestamp: number | undefined): string {
    return timestamp
        ? new Date(timestamp * 1000).toLocaleString()
        : trans('Not scanned yet');
}

function refresh(): void {
    router.reload({ only: ['machine', 'jobs'] });
}

/*
 * Refresh asks the machine to look at what's running now (otherwise it
 * scans hourly), then keeps reloading until the new scan is in. The icon
 * spins the whole time.
 */
const refreshing = ref(false);
let refreshTries = 0;
let refreshPoll: ReturnType<typeof setTimeout> | undefined;

function reloadOnce(): Promise<void> {
    return new Promise((resolve) =>
        router.reload({ only: ['machine', 'jobs'], onFinish: () => resolve() }),
    );
}

async function refreshNow(): Promise<void> {
    if (refreshing.value || !props.machine.id) return;
    refreshing.value = true;
    const before = props.machine.inventory?.scanned_at ?? null;

    let note: string | null = null;
    try {
        const response = await fetch(`/machines/${props.machine.id}/refresh`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
        });
        const json = await response.json().catch(() => ({}));
        note = response.ok
            ? (json.note ?? null)
            : trans('Couldn’t ask for a scan.');
    } catch {
        note = trans('Couldn’t ask for a scan.');
    }

    if (note) {
        await reloadOnce();
        refreshing.value = false;
        toast.error(note);
        return;
    }

    refreshTries = 0;
    const check = async () => {
        await reloadOnce();
        if ((props.machine.inventory?.scanned_at ?? null) !== before) {
            refreshing.value = false;
            toast.success(trans('Services are up to date.'));
        } else if (++refreshTries >= 22) {
            refreshing.value = false;
            toast.error(
                trans(
                    'The scan is taking a while. It’ll show up here when it’s done.',
                ),
            );
        } else {
            refreshPoll = setTimeout(check, 2000);
        }
    };
    refreshPoll = setTimeout(check, 1500);
}

/*
 * While a job is under way the page asks every 5 seconds, so its progress
 * moves; otherwise the machine's figures only change with its heartbeat,
 * so every 15 seconds is plenty. Usage history moves a minute at a time,
 * so it refreshes far less often. A hidden tab asks nothing, and catches
 * up as soon as it's shown.
 */
const jobsMoving = computed(() =>
    props.jobs.some((job) =>
        ['queued', 'accepted', 'running'].includes(job.state),
    ),
);
let ticks = 0;
const whenVisible = useWhenVisible();

function reloadHistory(): void {
    if (tab.value === 'services') {
        router.reload({ only: ['recent'] });
    }
    if (tab.value === 'usage') {
        router.reload({ only: ['history'] });
    }
}

function tick(): void {
    ticks += 1;
    if (jobsMoving.value || ticks % 3 === 0) {
        refresh();
    }
    if (ticks % 12 === 0) {
        reloadHistory();
    }
}

/** Back on a tab that was hidden: everything at once, then as usual. */
function catchUp(): void {
    ticks = 0;
    refresh();
    reloadHistory();
}

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            tick();
        } else {
            whenVisible(catchUp);
        }
    }, 5000);

    // Arriving from Cloud's Deploy an app: open the deploy dialog.
    if (
        new URLSearchParams(window.location.search).has('deploy') &&
        canDeploy.value
    ) {
        showDeploy.value = true;
    }

    // Arriving from a service page's new version: open that build's log.
    const job = new URLSearchParams(window.location.search).get('job');
    if (job) {
        tab.value = 'deployments';
        logJobId.value = job;
        logOpen.value = true;
    }
});
onBeforeUnmount(() => {
    clearTimeout(refreshPoll);
    clearInterval(removePoll);
    if (refreshTimer) clearInterval(refreshTimer);
});

/** After a template deploy is queued, show its progress. */
function onDeployed(): void {
    tab.value = 'deployments';
    refresh();
}

/**
 * Disks from the machine's facts, plus any live usage reports that the
 * facts don't know about yet (a volume attached since they were gathered).
 */
const diskRows = computed(() => {
    const facts = (props.machine.facts?.disks ?? []).map((disk) => ({
        key: `${disk.device}-${disk.mount}`,
        mount: disk.mount,
        label: `${disk.mount} · ${disk.device} · ${disk.fs_type}`,
        total: disk.total_bytes,
    }));
    const known = new Set(facts.map((disk) => disk.mount));
    const live = (props.machine.disks ?? [])
        .filter((disk) => !known.has(disk.mount))
        .map((disk) => ({
            key: disk.mount,
            mount: disk.mount,
            label: disk.mount,
            total: disk.total,
        }));

    return [...facts, ...live];
});

/** Live use of a mount, when telemetry reports it. */
function usage(mount: string): { percent: number; free: number } | null {
    const disk = props.machine.disks?.find((d) => d.mount === mount);
    if (!disk || disk.total <= 0) return null;

    return {
        percent: Math.min(100, Math.floor((disk.used / disk.total) * 100)),
        free: Math.max(0, disk.total - disk.used),
    };
}
</script>

<template>
    <Head :title="machine.name" />
    <WorkspacePage :title="machine.name">
        <template #title>
            <div class="flex min-w-0 flex-wrap items-center gap-3">
                <img
                    v-if="machine.iconUrl"
                    :src="machine.iconUrl"
                    alt=""
                    class="size-9 shrink-0 rounded-lg border border-(--ds-border-strong) object-cover"
                />
                <h1
                    class="truncate text-[21px]/7 font-semibold tracking-[-0.01em]"
                >
                    {{ machine.name }}
                </h1>
                <span
                    class="flex h-5 items-center rounded-full px-2 text-xs/4 font-semibold"
                    :class="
                        online
                            ? 'bg-(--ds-green)/15 text-(--ds-green)'
                            : 'bg-(--ds-active) text-(--ds-text-muted)'
                    "
                >
                    {{ ' ' + $t(`Daemon ${machine.connection ?? 'offline'}`) }}
                </span>
            </div>
        </template>
        <template #description>
            <span class="font-ui-mono text-[13px]">
                {{ machine.ip }} ·
                {{
                    machine.provider === 'Other'
                        ? $t('Other')
                        : machine.provider
                }}<span v-if="machine.daemonVersion">
                    {{
                        ' ' +
                        $t('· daemon :daemonVersion', {
                            daemonVersion: machine.daemonVersion,
                        })
                    }}</span
                >
            </span>
        </template>

        <template #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Machine sections')"
                >
                    <button
                        v-for="item in [
                            'services',
                            'scheduled',
                            'usage',
                            'ports',
                            'files',
                            'specs',
                            'deployments',
                        ] as const"
                        :key="item"
                        type="button"
                        class="cursor-pointer capitalize"
                        :aria-pressed="tab === item"
                        @click="tab = item"
                    >
                        {{ $t(item.charAt(0).toUpperCase() + item.slice(1)) }}
                    </button>
                </div>
            </div>
        </template>

        <template #actions>
            <button
                v-if="canManage"
                type="button"
                class="ds-raised-button cursor-pointer"
                @click="consoleOpen = true"
            >
                <SquareTerminal
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                />
                {{ $t('Console') }}
            </button>
            <!-- Rescan now; the one thing people reach for often -->
            <button
                type="button"
                class="ds-raised-button cursor-pointer px-2!"
                :aria-busy="refreshing || undefined"
                :aria-label="$t('Refresh services')"
                :title="$t('Refresh services')"
                @click="refreshNow"
            >
                <RefreshCw
                    class="size-4 opacity-70"
                    :class="{ 'mi-spin': refreshing }"
                    :stroke-width="1.75"
                />
            </button>

            <DropdownMenu v-if="machine.id">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer px-2!"
                        :aria-label="$t('More actions')"
                        :title="$t('More actions')"
                    >
                        <Ellipsis class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="end"
                >
                    <template v-if="canManage">
                        <DropdownMenuItem @select="scanning = true">
                            <ShieldCheck class="size-4" :stroke-width="1.75" />
                            {{ $t('Security scan') + ' ' }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="machine.uid"
                            @select="updatesOpen = true"
                        >
                            <CircleArrowUp
                                class="size-4"
                                :stroke-width="1.75"
                            />
                            {{ $t('System updates') + ' ' }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="maintenanceOpen = true">
                            <Wrench class="size-4" :stroke-width="1.75" />
                            {{ $t('Maintenance') + ' ' }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuItem
                        @select="
                            router.visit(`/machines/${machine.id}/settings`)
                        "
                    >
                        <Settings class="size-4" :stroke-width="1.75" />
                        {{ $t('Settings') + ' ' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <TooltipProvider :delay-duration="100">
                <Tooltip :disabled="hasPlan">
                    <TooltipTrigger as-child>
                        <!-- A disabled button gets no hover, so the wrapper does. -->
                        <span
                            class="inline-flex"
                            :tabindex="hasPlan ? undefined : 0"
                        >
                            <button
                                type="button"
                                :class="appButtonClasses('primary', 'md')"
                                :disabled="!canDeploy"
                                @click="showDeploy = true"
                            >
                                <Plus
                                    class="mi-plus size-3.5"
                                    :stroke-width="2"
                                />
                                {{ $t('Deploy service') + ' ' }}
                            </button>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        align="end"
                        class="max-w-64 px-3 py-2 text-left text-[13px]/5"
                    >
                        {{
                            ' ' +
                            $t(
                                'Your plan has ended, so deploys are paused. Everything already running keeps running.',
                            )
                        }}
                        <Link
                            v-if="upgrade"
                            :href="upgrade.href"
                            class="mt-1 block font-semibold underline underline-offset-2"
                            >{{ $t('Resubscribe in Billing') }}</Link
                        >
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </template>

        <div class="flex flex-col gap-5">
            <MaintenanceBanner
                v-if="machine.maintenance && machine.id"
                :until="machine.maintenance.until"
                :reason="machine.maintenance.reason"
                :end-url="`/machines/${machine.id}/maintenance`"
                :can-manage="canManage"
            />
            <MachineIssues v-if="issues?.length" :issues="issues" />
            <PlannedMaintenance
                v-if="machine.id && machine.plannedMaintenance?.length"
                :machine-id="machine.id"
                :windows="machine.plannedMaintenance"
                :can-manage="canManage"
            />
            <div
                v-if="!online"
                class="rounded-2xl bg-(--ds-amber)/8 px-5 py-3.5 text-sm text-amber-800 ring-1 ring-(--ds-amber)/25 dark:text-amber-200"
            >
                {{
                    ' ' +
                    $t(
                        'The daemon is offline. This page shows its last discovery scan; reconnect it to deploy or get fresh results.',
                    ) +
                    ' '
                }}
            </div>
            <div
                v-else-if="!deployEnabled"
                class="ds-tile px-5 py-3.5 text-sm text-(--ds-text-secondary)"
            >
                {{
                    ' ' +
                    $t(
                        'Deployments are not configured on this panel yet. Services and listening ports remain visible below.',
                    ) +
                    ' '
                }}
            </div>

            <ul
                v-if="tab === 'services'"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                :aria-label="$t('Usage')"
            >
                <li
                    v-for="tile in chartTiles"
                    :key="tile.label"
                    class="min-w-0"
                >
                    <button
                        type="button"
                        class="ds-tile flex size-full cursor-pointer items-center gap-4 px-4 py-3.5 text-left transition-colors hover:bg-(--ds-row-hover)"
                        :title="
                            $t('See :toLowerCase history', {
                                toLowerCase: String(tile.label.toLowerCase()),
                            })
                        "
                        @click="tab = 'usage'"
                    >
                        <div class="flex w-28 shrink-0 flex-col gap-1">
                            <h2
                                class="flex items-center gap-1.5 text-[13px]/4 font-medium text-(--ds-text-muted)"
                            >
                                <component
                                    :is="tile.icon"
                                    class="size-3.5 opacity-70"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                {{ tile.label }}
                            </h2>
                            <p
                                class="text-2xl/8 font-medium tracking-[-0.005em] tabular-nums"
                            >
                                {{ tile.value }}
                            </p>
                            <p
                                class="truncate text-xs/4 text-(--ds-text-faint)"
                                :title="tile.detail"
                            >
                                {{ tile.detail }}
                            </p>
                        </div>
                        <div
                            class="flex min-w-0 grow flex-col gap-1 self-stretch"
                        >
                            <span
                                class="text-right text-[11px]/3 text-(--ds-text-faint) tabular-nums"
                                >{{
                                    tile.peak
                                        ? $t('1h peak :peak', {
                                              peak: String(tile.peak),
                                          })
                                        : $t('Last hour')
                                }}</span
                            >
                            <div class="min-h-0 grow" :class="tile.tone">
                                <Sparkline
                                    :values="tile.values"
                                    :floor="tile.floor"
                                    :max="tile.max"
                                />
                            </div>
                        </div>
                    </button>
                </li>
                <li class="ds-tile flex min-w-0 items-center gap-4 px-4 py-3.5">
                    <div class="flex w-28 shrink-0 flex-col gap-1">
                        <h2
                            class="flex items-center gap-1.5 text-[13px]/4 font-medium text-(--ds-text-muted)"
                        >
                            <Layers
                                class="size-3.5 opacity-70"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            {{ $t('Services') + ' ' }}
                        </h2>
                        <p
                            class="text-2xl/8 font-medium tracking-[-0.005em] tabular-nums"
                        >
                            {{ machine.inventory?.services.length ?? 0 }}
                        </p>
                        <p class="truncate text-xs/4 text-(--ds-text-faint)">
                            {{
                                $t(':value listening ports', {
                                    value: String(
                                        machine.inventory?.listeners.length ??
                                            0,
                                    ),
                                }) + ' '
                            }}
                        </p>
                    </div>
                    <div class="flex min-w-0 grow flex-col gap-2">
                        <div
                            class="flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full bg-(--ds-active)"
                            role="img"
                            :aria-label="
                                serviceCounts
                                    .map((s) => `${s.count} ${s.label}`)
                                    .join(', ')
                            "
                        >
                            <span
                                v-for="segment in serviceCounts.filter(
                                    (s) => s.count,
                                )"
                                :key="segment.group"
                                class="h-full"
                                :class="segment.color"
                                :style="{ width: `${segment.share}%` }"
                            />
                        </div>
                        <p
                            class="flex flex-wrap justify-end gap-x-2.5 gap-y-0.5 text-[11px]/4 text-(--ds-text-faint) tabular-nums"
                        >
                            <span
                                v-for="segment in serviceCounts"
                                :key="segment.group"
                                class="flex items-center gap-1"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="segment.color"
                                />
                                {{ segment.count }} {{ segment.label }}
                            </span>
                        </p>
                    </div>
                </li>
            </ul>

            <section v-if="tab === 'services'" class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="font-medium">
                        {{ $t('Running and discovered services') }}
                    </h2>
                    <span class="text-xs text-(--ds-text-muted)">{{
                        $t('Last scan: :value', {
                            value: formatDate(machine.inventory?.scanned_at),
                        })
                    }}</span>
                </div>
                <p
                    v-if="machine.inventory && !machine.inventory.complete"
                    class="text-sm text-(--ds-amber-strong)"
                >
                    {{
                        ' ' +
                        $t(
                            'The last scan was incomplete; some services may be missing.',
                        ) +
                        ' '
                    }}
                </p>
                <ServicesTable
                    v-if="listedServices.length || buildingServices.length"
                    :services="listedServices"
                    :building="buildingServices"
                    :stats="machine.serviceStats ?? {}"
                    :machine-id="machine.id!"
                    :groups="serviceGroups"
                    :can-manage="canManage"
                    @open-log="openLogById"
                />
                <p
                    v-else
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        machine.inventory
                            ? $t(
                                  'No named services were found in the last scan.',
                              )
                            : $t(
                                  'Waiting for the daemon’s first discovery scan.',
                              )
                    }}
                </p>
            </section>

            <section v-else-if="tab === 'usage'" class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-medium">{{ $t('Usage history') }}</h2>
                    <div
                        class="ds-segments ds-segments--sm"
                        role="group"
                        :aria-label="$t('Time range')"
                    >
                        <button
                            v-for="option in ranges"
                            :key="option"
                            type="button"
                            class="cursor-pointer"
                            :aria-pressed="range === option"
                            :disabled="loadingHistory"
                            @click="loadHistory(option)"
                        >
                            {{ option }}
                        </button>
                    </div>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <TimeSeriesChart
                        :title="$t('CPU')"
                        :timestamps="timestamps"
                        :series="[
                            {
                                label: 'CPU',
                                color: '#60A5FA',
                                values: history.map((p) => p.cpu),
                                peaks: history.map((p) => p.cpuMax),
                            },
                        ]"
                        :format="percent"
                        :min-max="1"
                        :max="100"
                    />
                    <TimeSeriesChart
                        :title="$t('Memory')"
                        :timestamps="timestamps"
                        :series="[
                            {
                                label: $t('Memory'),
                                color: '#34D399',
                                values: history.map((p) => p.mem),
                                peaks: history.map((p) => p.memMax),
                            },
                        ]"
                        :format="bytes"
                        :min-max="1024 ** 3"
                        :max="machine.facts?.memory_bytes || undefined"
                    />
                    <TimeSeriesChart
                        class="lg:col-span-2"
                        :title="$t('Fullest disk')"
                        :timestamps="timestamps"
                        :series="[
                            {
                                label: $t('Disk'),
                                color: '#FBBF24',
                                values: history.map((p) => p.disk),
                            },
                        ]"
                        :format="percent"
                        :min-max="10"
                        :max="100"
                    />
                </div>
                <p class="text-xs text-(--ds-text-faint)">
                    {{
                        ' ' +
                        $t(
                            'Lines are averages; the shaded band above each one reaches the highest reading in that stretch. History is kept for 30 days.',
                        ) +
                        ' '
                    }}
                </p>
            </section>

            <section v-else-if="tab === 'scheduled'" class="space-y-3">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-medium">{{ $t('Scheduled tasks') }}</h2>
                    <span class="text-xs text-(--ds-text-muted)">{{
                        $t('Cron jobs and systemd timers, from the last scan')
                    }}</span>
                </div>
                <div
                    v-if="scheduled.length"
                    class="ds-tile overflow-x-auto"
                    role="table"
                    :aria-label="$t('Scheduled tasks')"
                >
                    <div
                        class="ds-tile-head hidden h-9.5 grid-cols-[minmax(0,2.4fr)_minmax(0,1.3fr)_6rem_minmax(0,1.2fr)] items-center gap-x-4 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                        role="row"
                    >
                        <span role="columnheader">{{ $t('Task') }}</span>
                        <span role="columnheader">{{ $t('Runs') }}</span>
                        <span role="columnheader">{{ $t('As') }}</span>
                        <span role="columnheader">{{
                            $t('Last and next')
                        }}</span>
                    </div>
                    <div
                        v-for="(task, index) in scheduled"
                        :key="`${task.source}-${task.command}-${index}`"
                        class="grid grid-cols-1 gap-x-4 gap-y-1 border-b border-(--ds-divider) px-4 py-3 text-[13px]/5 last:border-b-0 md:grid-cols-[minmax(0,2.4fr)_minmax(0,1.3fr)_6rem_minmax(0,1.2fr)] md:items-center"
                        role="row"
                    >
                        <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                            <span
                                class="truncate font-ui-mono text-xs"
                                :title="task.command"
                                >{{ task.command }}</span
                            >
                            <span
                                class="truncate text-xs text-(--ds-text-muted)"
                            >
                                {{
                                    task.description ??
                                    (task.kind === 'timer'
                                        ? task.source
                                        : task.source.replace(
                                              /^crontab:/,
                                              $t('crontab of') + ' ',
                                          ))
                                }}
                                <template v-if="task.service">
                                    ·
                                    <Link
                                        :href="`/machines/${machine.id}/services/${task.service.key}`"
                                        class="font-medium text-(--ds-text-secondary) hover:underline hover:underline-offset-4"
                                        >{{ task.service.name }}</Link
                                    >
                                </template>
                                <template v-if="task.ours">
                                    {{
                                        ' ' + $t('· set up by ServerOS')
                                    }}</template
                                >
                            </span>
                        </span>
                        <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                            <span
                                v-if="task.when"
                                class="first-letter:uppercase"
                                >{{ task.when }}</span
                            >
                            <span
                                class="truncate font-ui-mono text-xs"
                                :class="
                                    task.when
                                        ? 'text-(--ds-text-faint)'
                                        : 'text-(--ds-text-secondary)'
                                "
                                :title="task.schedule"
                                >{{ task.schedule }}</span
                            >
                        </span>
                        <span
                            class="truncate text-(--ds-text-secondary)"
                            role="cell"
                            >{{ task.user ?? '—' }}</span
                        >
                        <span
                            class="flex flex-col gap-0.5 text-xs text-(--ds-text-muted)"
                            role="cell"
                        >
                            <template v-if="task.lastRun || task.nextRun">
                                <span v-if="task.lastRun">{{
                                    $t('Last ran :value', {
                                        value: timeAgo(task.lastRun),
                                    })
                                }}</span>
                                <span v-if="task.nextRun">{{
                                    $t('Next :value', {
                                        value: timeAgo(task.nextRun),
                                    })
                                }}</span>
                            </template>
                            <span v-else class="text-(--ds-text-faint)">{{
                                task.kind === 'cron'
                                    ? $t('Cron doesn’t record runs')
                                    : $t('Not scheduled to run')
                            }}</span>
                        </span>
                    </div>
                </div>
                <p
                    v-else
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        machine.inventory
                            ? $t(
                                  'No cron jobs or timers were found in the last scan.',
                              )
                            : $t(
                                  'Waiting for the daemon’s first discovery scan.',
                              )
                    }}
                </p>
            </section>

            <section v-else-if="tab === 'ports'" class="space-y-3">
                <h2 class="font-medium">{{ $t('Firewall') }}</h2>
                <FirewallPanel
                    v-if="machine.id"
                    :machine-id="machine.id"
                    :machine-name="machine.name"
                    :firewall="machine.firewall ?? null"
                    :warnings="machine.inventory?.warnings ?? []"
                    :can-manage="canManage"
                    :online="online"
                />
            </section>

            <section v-else-if="tab === 'files'" class="space-y-3">
                <h2 class="font-medium">{{ $t('Files') }}</h2>
                <FileBrowser
                    :machine-id="machine.id ?? 0"
                    :places="files.places"
                    :max-write="files.maxWrite"
                    :max-read="files.maxRead"
                    :console-lock="consoleLock"
                    :available="canManage && deployEnabled && online"
                    empty-hint="ServerOS can open the folders of services it deployed or adopted, and there aren’t any on this machine yet. Deploy or adopt a service to browse its files here."
                />
            </section>

            <section v-else-if="tab === 'specs'" class="space-y-3">
                <h2 class="font-medium">{{ $t('Machine specs') }}</h2>
                <dl class="ds-tile grid gap-3 p-5 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Provider') }}
                        </dt>
                        <dd>
                            <ProviderName
                                :name="
                                    machine.facts?.hosting?.provider ||
                                    machine.provider
                                "
                            />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Region') }}
                        </dt>
                        <dd>
                            {{
                                machine.facts?.hosting?.region || $t('Unknown')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ ' ' + $t('Zone / datacenter') + ' ' }}
                        </dt>
                        <dd>
                            {{ machine.facts?.hosting?.zone || $t('Unknown') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('VPS plan') }}
                        </dt>
                        <dd>
                            {{
                                machine.facts?.hosting?.instance_type ||
                                $t('Unknown')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">{{ $t('OS') }}</dt>
                        <dd>
                            {{ machine.facts?.os || $t('Unknown') }}
                            {{ machine.facts?.os_version }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Kernel') }}
                        </dt>
                        <dd>{{ machine.facts?.kernel || $t('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Architecture') }}
                        </dt>
                        <dd>{{ machine.facts?.arch || $t('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">{{ $t('CPU') }}</dt>
                        <dd>
                            {{
                                $t(':valueValue vCPU · :value2', {
                                    valueValue: String(
                                        machine.facts?.cpu_cores ??
                                            $t('Unknown'),
                                    ),
                                    value2:
                                        machine.facts?.cpu_model ||
                                        $t('Unknown model'),
                                })
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">{{ $t('RAM') }}</dt>
                        <dd>{{ formatBytes(machine.facts?.memory_bytes) }}</dd>
                    </div>
                </dl>
                <h3 class="pt-2 text-sm font-medium">
                    {{ $t('Mounted disks') }}
                </h3>
                <div
                    v-if="diskRows.length"
                    class="ds-tile divide-y-2 divide-black/5 text-sm dark:divide-black/30"
                >
                    <div
                        v-for="disk in diskRows"
                        :key="disk.key"
                        class="flex justify-between gap-3 px-4 py-2"
                    >
                        <span class="min-w-0 truncate">{{ disk.label }}</span
                        ><span
                            v-if="usage(disk.mount)"
                            class="flex shrink-0 items-center gap-2.5 tabular-nums"
                            :title="
                                $t(':percent% used', {
                                    percent: String(usage(disk.mount)!.percent),
                                })
                            "
                        >
                            <span
                                class="h-1.5 w-20 overflow-hidden rounded-full bg-(--ds-active)"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full"
                                    :class="
                                        usage(disk.mount)!.percent >= 95
                                            ? 'bg-(--ds-red)'
                                            : usage(disk.mount)!.percent >= 85
                                              ? 'bg-(--ds-amber)'
                                              : 'bg-(--ds-green)'
                                    "
                                    :style="{
                                        width: `${usage(disk.mount)!.percent}%`,
                                    }"
                                />
                            </span>
                            {{
                                $t(':valueValue free of :value2', {
                                    valueValue: formatBytes(
                                        usage(disk.mount)!.free,
                                    ),
                                    value2: formatBytes(disk.total),
                                })
                            }}
                        </span>
                        <span v-else class="shrink-0">{{
                            formatBytes(disk.total)
                        }}</span>
                    </div>
                </div>
                <p v-else class="text-sm text-(--ds-text-muted)">
                    {{ ' ' + $t('No disk facts available.') + ' ' }}
                </p>
                <DiskUsagePanel
                    :machine-id="machine.id!"
                    :can-manage="canManage"
                    :online="online"
                />
                <h3 class="pt-2 text-sm font-medium">
                    {{ $t('Network interfaces') }}
                </h3>
                <div
                    v-if="machine.facts?.interfaces?.length"
                    class="ds-tile divide-y-2 divide-black/5 text-sm dark:divide-black/30"
                >
                    <div
                        v-for="network in machine.facts.interfaces"
                        :key="network.name"
                        class="px-4 py-2"
                    >
                        {{ network.name }} ·
                        {{ network.addresses.join(', ') || $t('No address') }}
                    </div>
                </div>
                <p v-else class="text-sm text-(--ds-text-muted)">
                    {{ ' ' + $t('No network facts available.') + ' ' }}
                </p>
            </section>

            <section v-else class="space-y-3">
                <h2 class="text-[15px]/6 font-semibold tracking-[-0.01em]">
                    {{ $t('Deployments') }}
                </h2>
                <div
                    v-if="deployJobs.length"
                    class="flex flex-wrap items-center gap-2"
                >
                    <div class="max-w-full overflow-x-auto">
                        <div
                            class="ds-segments"
                            role="group"
                            :aria-label="$t('Filter by status')"
                        >
                            <button
                                v-for="option in jobTabs"
                                :key="option.value"
                                type="button"
                                class="cursor-pointer gap-1.5"
                                :aria-pressed="jobFilter === option.value"
                                @click="jobFilter = option.value"
                            >
                                {{ option.label }}
                                <span
                                    class="text-xs/4 tabular-nums"
                                    :class="
                                        jobFilter === option.value
                                            ? 'text-(--ds-text-muted)'
                                            : 'text-(--ds-text-faint)'
                                    "
                                >
                                    {{ option.count }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <label
                            class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-64"
                        >
                            <Search
                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                                :stroke-width="1.6"
                            />
                            <span class="sr-only">{{
                                $t('Search deployments')
                            }}</span>
                            <input
                                v-model="jobSearch"
                                type="search"
                                :placeholder="$t('Search by service')"
                                class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                            />
                        </label>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="ds-raised-button cursor-pointer text-[13px]!"
                                >
                                    <span class="text-(--ds-text-muted)">
                                        {{ ' ' + $t('Sort') + ' ' }}
                                    </span>
                                    <span class="font-medium">
                                        {{ jobSortLabel }}
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
                                <DropdownMenuItem
                                    v-for="option in jobSortOptions"
                                    :key="option.value"
                                    @select="jobSort = option.value"
                                >
                                    {{ option.label }}
                                    <Check
                                        v-if="jobSort === option.value"
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <DeployWorkflowsMenu
                            v-if="canManage && deployWorkflows.count === 0"
                            :available="deployWorkflows.available"
                        />
                    </div>
                </div>
                <!-- One quiet row per deploy; the log opens on click -->
                <ul
                    v-if="visibleJobs.length"
                    class="ds-tile ds-tile-rows flex flex-col"
                >
                    <li
                        v-for="job in visibleJobs"
                        :key="job.id"
                        class="ds-tile-row"
                    >
                        <button
                            type="button"
                            class="group flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-2 text-left text-[13.5px]/5 transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                            :aria-label="
                                job.service
                                    ? $t('View the log for :service', {
                                          service: job.service,
                                      })
                                    : $t('View the log for this deploy')
                            "
                            @click="openLog(job)"
                        >
                            <component
                                :is="jobIcon(job)"
                                class="size-4 shrink-0"
                                :class="[
                                    jobTone[jobStatus(job.state).tone],
                                    {
                                        'animate-spin': jobStatus(job.state)
                                            .busy,
                                    },
                                ]"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            <span class="shrink-0 font-medium">
                                {{ job.service ?? $t('Deploy') }}
                            </span>
                            <span
                                class="min-w-0 truncate text-[13px] text-(--ds-text-faint)"
                            >
                                {{ jobDetail(job) }}
                            </span>
                            <span
                                class="ml-auto flex shrink-0 items-center gap-3 text-[13px] text-(--ds-text-muted) tabular-nums"
                            >
                                <span
                                    class="hidden sm:inline"
                                    :class="jobTone[jobStatus(job.state).tone]"
                                    >{{
                                        jobStatus(
                                            job.state,
                                            job.phase,
                                            job.progress,
                                        ).label
                                    }}</span
                                >
                                <span
                                    class="hidden w-24 text-right md:inline"
                                    :title="
                                        job.createdAt
                                            ? new Date(
                                                  job.createdAt,
                                              ).toLocaleString()
                                            : undefined
                                    "
                                    >{{ timeAgo(job.createdAt) }}</span
                                >
                                <ChevronRight
                                    class="size-3.5 text-(--ds-text-faint) transition-transform group-hover:translate-x-0.5"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                            </span>
                        </button>
                    </li>
                </ul>
                <p
                    v-else-if="deployJobs.length"
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('No deployments match these filters.') + ' ' }}
                </p>
                <p
                    v-else
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'No deployments yet. Deploy a service from the library to start one.',
                        ) +
                        ' '
                    }}
                </p>
            </section>
        </div>
        <Dialog v-model:open="scanning">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-xl"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('Security scan') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'What the daemon sees on :name: ports open to the internet, waiting security updates, services and the system.',
                                { name: machine.name },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <SecurityScan
                    v-if="scanning && machine.id"
                    :machine-id="machine.id"
                />
            </DialogContent>
        </Dialog>
        <UpdatesDialog
            v-if="machine.id && canManage"
            v-model:open="updatesOpen"
            :machine-id="machine.id"
            :machine-name="machine.name"
        />
        <JobLogDialog
            v-if="machine.id"
            v-model:open="logOpen"
            :job="logJob"
            :machine-id="machine.id"
            :can-manage="canManage"
            @retried="onRetried"
            @changed="refresh"
            @open-library="openLibraryFromLog"
        />
        <DeployServiceDialog
            v-if="machine.id"
            v-model:open="showDeploy"
            :templates="templates"
            :machine-id="machine.id"
            @deployed="onDeployed"
        />
        <MaintenanceDialog
            v-if="machine.id"
            v-model:open="maintenanceOpen"
            :machine-id="machine.id"
            :machine-name="machine.name"
        />
        <MachineConsole
            v-model:open="consoleOpen"
            :machine-id="machine.id ?? 0"
            :machine-name="machine.name"
            :containers="consoleContainers"
            :jobs="jobs"
            :console-lock="consoleLock"
        />
    </WorkspacePage>
</template>
