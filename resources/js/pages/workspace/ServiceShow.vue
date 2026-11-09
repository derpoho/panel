<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ellipsis, LoaderCircle, Search, X } from '@lucide/vue';
import {
    SolarCopyPlus as CopyPlus,
    SolarEye as Eye,
    SolarEyeOff as EyeOff,
    SolarFileText as FileText,
    SolarFileX as FileX,
    SolarGlobe as Globe,
    SolarHistory as History,
    SolarLock as Lock,
    SolarPackage as Package,
    SolarPencil as Pencil,
    SolarPlay as Play,
    SolarRefreshCw as RefreshCw,
    SolarRotateCcw as RotateCcw,
    SolarShieldCheck as ShieldCheck,
    SolarSquareTerminal as SquareTerminal,
    SolarSparkles as Sparkles,
    SolarSettings as Settings,
    SolarSquare as Square,
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
import { toast } from '@/lib/toast';
import InputError from '@/components/InputError.vue';
import AppVariablesDialog from '@/components/machines/AppVariablesDialog.vue';
import CloneServiceDialog from '@/components/machines/CloneServiceDialog.vue';
import FileBrowser from '@/components/machines/FileBrowser.vue';
import type { FilePlace } from '@/components/machines/FileBrowser.vue';
import JobStatusBadge from '@/components/machines/JobStatusBadge.vue';
import type { Machine } from '@/components/machines/machines';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import type {
    Service,
    ServiceStat,
} from '@/components/machines/ServicesTable.vue';
import TimeSeriesChart from '@/components/machines/TimeSeriesChart.vue';
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
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import ContainerConfig from '@/components/workspace/ContainerConfig.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { usePathTab } from '@/composables/usePathTab';
import { useZipUpload } from '@/composables/useZipUpload';
import { timeAgo } from '@/lib/timeAgo';
import { edit as securityEdit } from '@/routes/security';
import type { Workspace, WorkspaceAccess } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

type ServiceJob = {
    id: string;
    kind: string;
    action: string | null;
    command: string | null;
    state: string;
    log: string[];
    result: Record<string, unknown> | null;
    error: { message?: string; next_step?: string } | null;
    createdAt: string | null;
};

type Point = {
    ts: number;
    cpu: number | null;
    mem: number | null;
    rx: number | null;
    tx: number | null;
};

const props = defineProps<{
    workspace: Workspace;
    machine: Machine;
    service: Service & { managed: boolean; container: boolean };
    stats: ServiceStat | null;
    /** Whether ServerOS can remove it: containers, and systemd services on a new enough daemon. */
    removable: boolean;
    /** Containers: whether the daemon can show their docker inspect. */
    inspect: { available: boolean } | null;
    /** Containers: why it can't be cloned now, or the name and port a copy starts with. */
    clone: { unavailable: string | null; name: string; port: number } | null;
    /** Where its ports open from this computer, on a local test node. */
    links: string[];
    range: string;
    history: Point[];
    jobs: ServiceJob[];
    commandsEnabled: boolean;
    files: { places: FilePlace[]; maxWrite: number; maxRead: number };
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    /** Set when ServerOS built this from code, so it can ship a new version. */
    app: {
        service: string;
        commit: string | null;
        fromUpload: boolean;
        repo: string | null;
        domains: string[];
        /** Settings that mention the current domain (names only). */
        mentions: string[];
        /** Whether its code is still on hand to redeploy elsewhere. */
        canMove: boolean;
        deployedAt: string | null;
        /** Whether a deploy snapshots its data first. */
        snapshotBefore: boolean;
        /** The database snapshotted with it: auto, none, or one chosen. */
        database: {
            setting: string;
            current: { name: string; display: string } | null;
            options: { value: string; label: string }[];
        };
        /** Newest first, the versions the machine keeps to roll back to. */
        versions: AppVersion[];
        /** Its environment variables' names; values never leave the panel. */
        variables: string[];
    } | null;
}>();

type AppVersion = {
    commit: string;
    live: boolean;
    deployedAt: string | null;
    by: string | null;
    /** A snapshot of its data can go back with it. */
    data: boolean;
    /** When that snapshot was taken: just before the next deploy. */
    dataAt: string | null;
};

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
/** Adopted, by the last scan or by an adopt that just finished. */
const adoptedNow = ref(false);
const managed = computed(() => props.service.managed || adoptedNow.value);
const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);
/**
 * Deploys and the console need a plan (a self-hosted install needs none);
 * starting and stopping don't.
 */
const hasPlan = computed(
    () =>
        props.workspace.billing.plan !== null ||
        props.workspace.billing.selfHosted === true,
);
const canControl = computed(
    () =>
        canManage.value &&
        props.commandsEnabled &&
        online.value &&
        managed.value,
);
/**
 * This service's address. Apps deployed from code keep one name and
 * address across versions.
 */
const serviceUrl = computed(
    () =>
        `/machines/${props.machine.id}/services/${props.service.app ? `app:${props.service.app}` : props.service.key}`,
);
const displayName = computed(
    () =>
        props.service.label ??
        props.service.app ??
        props.service.found ??
        props.service.name,
);

/* A name of your own for it, kept in the panel; the machine doesn't change. */
const renaming = ref(false);
const newLabel = ref('');
const renameBusy = ref(false);
const renameError = ref<string | null>(null);

function openRename(): void {
    newLabel.value = props.service.label ?? '';
    renameError.value = null;
    renaming.value = true;
}

function saveLabel(label: string | null): void {
    renameBusy.value = true;
    router.put(
        `${serviceUrl.value}/label`,
        { label },
        {
            preserveScroll: true,
            onSuccess: () => {
                renaming.value = false;
                toast.success(
                    label
                        ? trans('Renamed to :label.', { label: String(label) })
                        : trans('Back to its original name.'),
                );
            },
            onError: (errors) =>
                (renameError.value =
                    errors.label ?? trans('Couldn’t rename it.')),
            onFinish: () => (renameBusy.value = false),
        },
    );
}
const actionUrl = computed(
    () =>
        `/machines/${props.machine.id}/services/${props.service.app ? `app:${props.service.app}` : props.service.key}/actions`,
);

/* Tabs, kept in the URL hash so a refresh lands back on the same one */

const tab = usePathTab(serviceUrl.value, [
    'overview',
    'logs',
    'console',
    'files',
    'details',
] as const);

const tabs = computed(() =>
    (['overview', 'logs', 'files', 'details'] as const).map((value) => ({
        value,
        label: value[0].toUpperCase() + value.slice(1),
        current: tab.value === value,
    })),
);

/* Live data: poll quickly while a job runs, slowly otherwise */

const activeJob = computed(() =>
    props.jobs.some((job) => ['queued', 'running'].includes(job.state)),
);
let timer: ReturnType<typeof setTimeout> | undefined;

/*
 * After start, stop or restart the daemon rescans, which takes a few
 * seconds to reach the page. Until it does, show the state the action led
 * to, and check quickly; give up on that after a minute.
 */
const expected = ref<{ status: string; until: number } | null>(null);
const awaitingScan = computed(
    () =>
        !!expected.value &&
        props.service.status !== expected.value.status &&
        Date.now() < expected.value.until,
);

/** Running, as the last scan says or as an action just made it. */
const running = computed(() =>
    awaitingScan.value && expected.value
        ? expected.value.status === 'running'
        : props.service.status === 'running',
);

watch(
    () => props.service.status,
    (status) => {
        if (expected.value && status === expected.value.status) {
            expected.value = null;
        }
    },
);

function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.reload({
                only: ['service', 'stats', 'history', 'jobs', 'machine', 'app'],
                onFinish: poll,
            }),
        activeJob.value || awaitingScan.value ? 1500 : 5000,
    );
}

onMounted(() => {
    poll();
});

onBeforeUnmount(() => {
    clearTimeout(timer);
});

function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    if (value >= 1024 ** 3) return `${(value / 1024 ** 3).toFixed(2)} GiB`;
    if (value >= 1024 ** 2) return `${(value / 1024 ** 2).toFixed(1)} MiB`;
    if (value >= 1024) return `${Math.round(value / 1024)} KiB`;

    return `${Math.round(value)} B`;
}

function uptime(startedAt: number | null | undefined): string {
    if (!startedAt) return '—';

    const s = Math.max(0, Math.floor(Date.now() / 1000 - startedAt));
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);

    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : m ? `${m}m` : `${s}s`;
}

const memLimit = computed(() =>
    props.stats?.memLimit && props.stats.memLimit < 2 ** 50
        ? props.stats.memLimit
        : null,
);

const statusTone: Record<string, { dot: string; label: string }> = {
    running: { dot: 'bg-(--ds-green)', label: trans('Running') },
    stopped: { dot: 'bg-(--ds-text-faint)', label: trans('Stopped') },
    restarting: { dot: 'bg-(--ds-amber)', label: trans('Restarting') },
    failed: { dot: 'bg-(--ds-red)', label: trans('Failed') },
};
const status = computed(() =>
    pending.value && pending.value.action !== 'adopt'
        ? {
              dot: 'bg-(--ds-amber) animate-pulse',
              label: `${lifecycleLabels[pending.value.action]}…`,
          }
        : (statusTone[
              awaitingScan.value && expected.value
                  ? expected.value.status
                  : props.service.status
          ] ?? {
              dot: 'bg-(--ds-text-faint)',
              label: props.service.status,
          }),
);

const ranges = ['15m', '1h', '6h', '24h'];

function setRange(range: string): void {
    router.get(
        window.location.pathname,
        { range },
        {
            only: ['history', 'range'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const timestamps = computed(() => props.history.map((p) => p.ts));
const percent = (v: number) => `${v < 10 ? v.toFixed(1) : Math.round(v)}%`;
const rate = (v: number) => `${bytes(v)}/s`;

const busy = ref<string | null>(null);

async function act(
    action: string,
    extra: Record<string, unknown> = {},
): Promise<{ ok: boolean; body: Record<string, unknown> }> {
    busy.value = action;

    try {
        const response = await fetch(actionUrl.value, {
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
            body: JSON.stringify({ action, ...extra }),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok && response.status !== 422) {
            toast.error(body.error ?? trans('That didn’t work.'));
        }

        if (response.ok) {
            router.reload({ only: ['jobs'], onFinish: poll });
        }

        return { ok: response.ok, body };
    } catch {
        toast.error(trans('Couldn’t reach the panel.'));

        return { ok: false, body: {} };
    } finally {
        busy.value = null;
    }
}

const lifecycleLabels: Record<string, string> = {
    start: trans('Starting'),
    stop: trans('Stopping'),
    restart: trans('Restarting'),
    reload: trans('Reloading'),
    rollback: trans('Rolling back'),
};

const doneLabels: Record<string, string> = {
    start: trans('Started'),
    stop: trans('Stopped'),
    restart: trans('Restarted'),
    reload: trans('Reloaded'),
    adopt: trans('Adopted'),
    repair: trans('Repaired'),
    rollback: trans('Rolled back'),
    clone: trans('Cloned'),
};

/*
 * An action in flight: the daemon runs it as a job, which the page polls.
 * Until it finishes the status shows what's happening, the buttons wait,
 * and the toast turns into the result.
 */
const pending = ref<{
    id: string;
    action: string;
    toast: string | number;
} | null>(null);
const pendingJob = computed(() =>
    pending.value
        ? (props.jobs.find((job) => job.id === pending.value?.id) ?? null)
        : null,
);
/** Set while an action is starting or running, so buttons wait for it. */
const working = computed(() => busy.value ?? pending.value?.action ?? null);

async function track(action: string, verb: string): Promise<void> {
    const { ok, body } = await act(action);
    if (!ok) return;

    const toastId = toast.loading(
        trans(':verb :name…', { verb, name: displayName.value }),
    );
    if (typeof body.jobId === 'string') {
        pending.value = { id: body.jobId, action, toast: toastId };
    } else {
        toast.success(
            trans(':verb :name.', {
                verb: doneLabels[action],
                name: displayName.value,
            }),
            {
                id: toastId,
            },
        );
    }
}

/* A copy to try things on; the dialog starts the job and this follows it. */
const cloning = ref(false);
function cloneStarted(jobId: string, name: string): void {
    const toastId = toast.loading(
        trans('Cloning :name as :as…', { name: displayName.value, as: name }),
    );
    pending.value = { id: jobId, action: 'clone', toast: toastId };
    router.reload({ only: ['jobs'], onFinish: poll });
}

watch(
    () => pendingJob.value?.state,
    (state) => {
        const current = pending.value;
        if (!current || !state || ['queued', 'running'].includes(state)) {
            return;
        }
        if (state === 'succeeded') {
            toast.success(
                trans(':verb :name.', {
                    verb: doneLabels[current.action],
                    name: displayName.value,
                }),
                {
                    id: current.toast,
                },
            );
            if (current.action === 'adopt') {
                adoptedNow.value = true;
            }
            // Repairing recreates the container, so it has a new key.
            const repairedKey = pendingJob.value?.result?.service;
            if (
                current.action === 'repair' &&
                typeof repairedKey === 'string'
            ) {
                pending.value = null;
                router.visit(
                    `/machines/${props.machine.id}/services/${encodeURIComponent(repairedKey)}`,
                );
                return;
            }
            if (['start', 'restart', 'stop'].includes(current.action)) {
                expected.value = {
                    status: current.action === 'stop' ? 'stopped' : 'running',
                    until: Date.now() + 60_000,
                };
            }
        } else {
            toast.error(
                pendingJob.value?.error?.message ??
                    trans('Couldn’t :action :displayName.', {
                        action: String(current.action),
                        displayName: String(displayName.value),
                    }),
                { id: current.toast },
            );
        }
        pending.value = null;
        // The daemon rescans after an action; pick up the new state soon.
        poll();
    },
);

/* Versions: roll back, and snapshot before deploying */

/** The live version's commit, which a rollback changes. */
const liveCommit = computed(
    () =>
        props.app?.versions.find((v) => v.live)?.commit ??
        props.app?.commit ??
        null,
);

const rollingBack = ref<AppVersion | null>(null);
const rollbackOpen = ref(false);
const rollbackData = ref(false);
const rollbackConfirm = ref('');
const rollbackBusy = ref(false);
const rollbackError = ref<string | null>(null);

function askRollback(version: AppVersion): void {
    rollingBack.value = version;
    rollbackData.value = false;
    rollbackConfirm.value = '';
    rollbackError.value = null;
    rollbackOpen.value = true;
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function rollback(): Promise<void> {
    const version = rollingBack.value;
    if (!props.app || !version) return;
    rollbackBusy.value = true;
    rollbackError.value = null;

    try {
        const response = await fetch(
            `/machines/${props.machine.id}/apps/${props.app.service}/rollback`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    commit: version.commit,
                    with_data: rollbackData.value,
                    confirm: rollbackConfirm.value,
                }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            rollbackError.value =
                body.errors?.confirm?.[0] ??
                body.error ??
                trans('The rollback could not start.');

            return;
        }

        rollbackOpen.value = false;
        pending.value = {
            id: body.jobId,
            action: 'rollback',
            toast: toast.loading(
                trans('Rolling :displayName back to :slice…', {
                    displayName: String(displayName.value),
                    slice: String(version.commit.slice(0, 7)),
                }),
            ),
        };
        router.reload({ only: ['jobs'], onFinish: poll });
    } finally {
        rollbackBusy.value = false;
    }
}

const snapshotBefore = ref(props.app?.snapshotBefore ?? true);
watch(
    () => props.app?.snapshotBefore,
    (on) => {
        if (on !== undefined) snapshotBefore.value = on;
    },
);

async function saveDatabase(value: string): Promise<void> {
    if (!props.app) return;
    const response = await fetch(
        `/machines/${props.machine.id}/apps/${props.app.service}/settings`,
        {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ database: value }),
        },
    ).catch(() => null);
    if (!response?.ok) {
        toast.error(trans('Couldn’t save that.'));
    }
    router.reload({ only: ['app'] });
}

async function saveSnapshotBefore(on: boolean): Promise<void> {
    if (!props.app) return;
    const response = await fetch(
        `/machines/${props.machine.id}/apps/${props.app.service}/settings`,
        {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ snapshot_before: on }),
        },
    ).catch(() => null);
    if (!response?.ok) {
        snapshotBefore.value = !on;
        toast.error(trans('Couldn’t save that.'));
    }
}

/* The machine stopped answering: don't leave the spinner up forever. */
watch(
    () => pending.value?.id,
    (id) => {
        if (!id) return;
        setTimeout(() => {
            if (pending.value?.id === id) {
                toast.error(
                    trans(
                        'No answer from the machine yet. It may still finish; check back in a minute.',
                    ),
                    { id: pending.value.toast },
                );
                pending.value = null;
            }
        }, 90_000);
    },
);

function lifecycle(action: 'start' | 'stop' | 'restart' | 'reload') {
    void track(action, lifecycleLabels[action]);
}

function adopt() {
    void track('adopt', trans('Adopting'));
}

/** Hide it from the panel's lists, or show it again; the machine isn't touched. */
function toggleHidden(): void {
    const hidden = !props.service.hidden;
    router.put(
        `/machines/${props.machine.id}/hidden-services`,
        { services: [props.service.key], hidden },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    hidden
                        ? trans(
                              ':displayName is hidden from the lists. It still runs as before.',
                              { displayName: String(displayName.value) },
                          )
                        : trans(':displayName shows in the lists again.', {
                              displayName: String(displayName.value),
                          }),
                ),
        },
    );
}

/** Stop keeping its log lines, or start again; the service isn't touched. */
function toggleLogs(): void {
    const muted = !props.service.logsMuted;
    router.put(
        `/machines/${props.machine.id}/services/${encodeURIComponent(props.service.key)}/logs`,
        { muted },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    muted
                        ? trans(
                              'Logs from :displayName are no longer kept. It still runs as before.',
                              { displayName: String(displayName.value) },
                          )
                        : trans('Logs from :displayName are kept again.', {
                              displayName: String(displayName.value),
                          }),
                ),
        },
    );
}

/** Pterodactyl set it up, and the files Wings made for it are gone. */
const needsRepair = computed(
    () => props.service.details?.needs_repair === 'wings',
);
const repairing = ref(false);

function repair() {
    repairing.value = false;
    void track('repair', trans('Repairing'));
}

/* Environment variables: saving deploys it again; follow the build there. */
const variablesOpen = ref(false);

function variablesSaved(jobId: string | null): void {
    if (jobId && props.machine.id) {
        router.visit(`/machines/${props.machine.id}?job=${jobId}`);
    }
}

/*
 * A new version: upload a .zip, then redeploy with the last deploy's
 * domain, environment and port. The old version keeps serving until the
 * new one passes its health check. The container is renamed for the new
 * code, so follow the build on the machine's Deployments tab.
 */
const zip = useZipUpload();
const zipInput = useTemplateRef<HTMLInputElement>('zipInput');
const shipping = ref(false);
const dragging = ref(false);

async function shipVersion(file: File | undefined) {
    if (!props.app || !props.machine.id || shipping.value) return;
    const source = await zip.upload(props.machine.id, file);
    if (!source) return;

    shipping.value = true;
    try {
        const json = await zip.post(
            `/machines/${props.machine.id}/apps/${props.app.service}/versions`,
            JSON.stringify({
                source: source.repo
                    .split('/')
                    .pop()
                    ?.replace(/\.git$/, ''),
            }),
            'application/json',
        );
        toast.success(trans('Building the new version…'));
        router.visit(`/machines/${props.machine.id}?job=${json.jobId}`);
    } catch (cause) {
        zip.error.value =
            cause instanceof Error
                ? cause.message
                : trans('Couldn’t start the deploy.');
    } finally {
        shipping.value = false;
        if (zipInput.value) zipInput.value.value = '';
    }
}

function pickVersion(event: Event) {
    void shipVersion((event.target as HTMLInputElement).files?.[0]);
}

function dropVersion(event: DragEvent) {
    dragging.value = false;
    void shipVersion(event.dataTransfer?.files[0]);
}

/*
 * A new domain: the same code and settings, served from somewhere else.
 * The DNS check says whether the new name points here yet.
 */
type DomainCheck = {
    status: 'ready' | 'missing' | 'proxied' | 'elsewhere';
    record: { type: string; name: string; value: string } | null;
    wildcard?: Omit<DomainCheck, 'wildcard'>;
};

const moving = ref(false);
const newDomain = ref('');
const moveSettings = ref(true);
const moveWildcard = ref(false);
const moveBusy = ref(false);
const moveError = ref<string | null>(null);
const moveDns = ref<DomainCheck | null>(null);
let moveDnsTimer: ReturnType<typeof setTimeout> | undefined;

function bareDomain(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z]+:\/\//, '')
        .replace(/[/?#].*$/, '')
        .replace(/:\d+$/, '');
}

/** Whether the app serves every subdomain of its domain (*.domain). */
const servesWildcard = computed(() => {
    const domains = props.app?.domains ?? [];

    return domains.length > 0 && domains.includes(`*.${domains[0]}`);
});

/** Moving somewhere new, rather than changing what's served at the same one. */
const movingAway = computed(
    () =>
        !!props.app?.domains.length &&
        bareDomain(newDomain.value) !== props.app.domains[0],
);

function openMove() {
    newDomain.value = props.app?.domains[0] ?? '';
    moveError.value = null;
    moveDns.value = null;
    moveSettings.value = true;
    moveWildcard.value = servesWildcard.value;
    moving.value = true;
}

watch([newDomain, moveWildcard], ([value]) => {
    clearTimeout(moveDnsTimer);
    moveDns.value = null;
    const domain = bareDomain(value);
    if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(domain)) return;

    moveDnsTimer = setTimeout(async () => {
        try {
            const check = (await zip.post(
                `/machines/${props.machine.id}/domain-check`,
                JSON.stringify({ domain, wildcard: moveWildcard.value }),
                'application/json',
            )) as DomainCheck;
            if (bareDomain(newDomain.value) === domain) moveDns.value = check;
        } catch {
            moveDns.value = null;
        }
    }, 500);
});

async function moveDomain() {
    if (!props.app || moveBusy.value) return;
    moveBusy.value = true;
    moveError.value = null;

    try {
        const json = await zip.post(
            `/machines/${props.machine.id}/apps/${props.app.service}/domain`,
            JSON.stringify({
                domain: newDomain.value,
                wildcard: moveWildcard.value,
                move_settings: moveSettings.value && movingAway.value,
            }),
            'application/json',
        );
        moving.value = false;
        toast.success(
            trans('Moving to :bareDomain…', {
                bareDomain: String(bareDomain(newDomain.value)),
            }),
        );
        router.visit(`/machines/${props.machine.id}?job=${json.jobId}`);
    } catch (cause) {
        moveError.value =
            cause instanceof Error
                ? cause.message
                : trans('Couldn’t change the domain.');
    } finally {
        moveBusy.value = false;
    }
}

const logLines = ref(300);
const logFilter = ref('');
/** The Logs page, showing only this service. */
const historyUrl = computed(
    () =>
        `/logs?${new URLSearchParams({ machine: String(props.machine.id), service: props.service.name })}`,
);

const logsJob = computed(
    () => props.jobs.find((job) => job.kind === 'service_logs') ?? null,
);
const logs = computed(() => {
    const lines = (logsJob.value?.result?.lines as string[] | undefined) ?? [];
    const needle = logFilter.value.trim().toLowerCase();

    return needle
        ? lines.filter((line) => line.toLowerCase().includes(needle))
        : lines;
});
const logBox = useTemplateRef<HTMLElement>('logBox');

function fetchLogs(): void {
    if (canControl.value) {
        void act('logs', { lines: logLines.value });
    }
}

/* Details: the container's docker inspect, read when the tab opens. */
const inspectJob = computed(
    () => props.jobs.find((job) => job.kind === 'service_inspect') ?? null,
);
const canInspect = computed(
    () =>
        props.inspect?.available === true &&
        canManage.value &&
        props.commandsEnabled &&
        online.value,
);

function loadInspect(): void {
    if (canInspect.value) {
        void act('inspect');
    }
}

watch(
    tab,
    (next) => {
        if (next === 'details' && !inspectJob.value) loadInspect();
    },
    { immediate: true },
);

watch(tab, (next) => {
    if (next === 'logs' && !logsJob.value) {
        fetchLogs();
    }
});

/* The console: a terminal that slides up over the page. Old links to the
   console tab open it too. */
const consoleSheet = ref(false);

function openConsole(): void {
    consoleSheet.value = true;
    void nextTick(() => consoleInput.value?.focus());
}

watch(
    tab,
    (next) => {
        if (next === 'console') {
            tab.value = 'overview';
            openConsole();
        }
    },
    { immediate: true },
);

watch(
    () => logs.value.length,
    () =>
        nextTick(() => {
            if (logBox.value) {
                logBox.value.scrollTop = logBox.value.scrollHeight;
            }
        }),
);

/* Console lock: 2FA on the account, then a fresh code per session */

const unlockCode = ref('');
const unlockError = ref<string | null>(null);
const unlocking = ref(false);
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

const unlockedMs = computed(() =>
    props.consoleLock.unlockedUntil
        ? Date.parse(props.consoleLock.unlockedUntil) - now.value
        : 0,
);
const consoleOpen = computed(() => unlockedMs.value > 0);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(unlockedMs.value / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(clock));

async function consoleRequest(path: 'unlock' | 'lock', body = {}) {
    return fetch(`/console/${path}`, {
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
        body: JSON.stringify(body),
    });
}

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) {
        return;
    }

    unlocking.value = true;
    unlockError.value = null;

    try {
        const response = await consoleRequest('unlock', {
            code: unlockCode.value,
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            unlockError.value =
                body.errors?.code?.[0] ??
                body.error ??
                trans('Couldn’t unlock.');
            unlockCode.value = '';

            return;
        }

        unlockCode.value = '';
        router.reload({
            only: ['consoleLock'],
            onFinish: () => nextTick(() => consoleInput.value?.focus()),
        });
    } finally {
        unlocking.value = false;
    }
}

async function lockConsole(): Promise<void> {
    await consoleRequest('lock');
    router.reload({ only: ['consoleLock'] });
}

const command = ref('');
const consoleInput = useTemplateRef<HTMLInputElement>('consoleInput');
const consoleBox = useTemplateRef<HTMLElement>('consoleBox');
const commandError = ref<string | null>(null);
let historyIndex = -1;

const transcript = computed(() =>
    props.jobs
        .filter((job) => job.kind === 'service_exec')
        .slice()
        .reverse(),
);

async function run(): Promise<void> {
    const text = command.value.trim();

    if (!text || busy.value) {
        return;
    }

    commandError.value = null;
    const { ok, body } = await act('exec', { command: text });

    if (ok) {
        command.value = '';
        historyIndex = -1;
    } else if (body.code === 'console_locked') {
        router.reload({ only: ['consoleLock'] });
    } else if (body.errors) {
        commandError.value = Object.values(
            body.errors as Record<string, string[]>,
        )[0]?.[0];
    }
}

function recall(direction: 1 | -1): void {
    const past = transcript.value.map((job) => job.command ?? '').reverse();

    historyIndex = Math.max(
        -1,
        Math.min(past.length - 1, historyIndex + direction),
    );
    command.value = historyIndex === -1 ? '' : past[historyIndex];
}

watch(
    () => transcript.value.map((job) => job.log.length).join(),
    () =>
        nextTick(() => {
            if (consoleBox.value) {
                consoleBox.value.scrollTop = consoleBox.value.scrollHeight;
            }
        }),
);

/** The daemon echoes the command as the first line; skip it. */
function output(job: ServiceJob): string[] {
    return job.log[0]?.startsWith('$ ') ? job.log.slice(1) : job.log;
}

const removing = ref(false);
const removeConfirm = ref('');
const removeData = ref(false);
const removeDataSure = ref(false);
const removeAdopted = computed(() => props.service.origin !== 'created');
/** Removing doesn't need it adopted first: the panel adopts it on the way. */
const canRemove = computed(
    () => canManage.value && props.commandsEnabled && online.value,
);
const removeError = ref<string | null>(null);
const removeBusy = ref(false);

async function remove(): Promise<void> {
    removeBusy.value = true;
    removeError.value = null;

    const { ok, body } = await act('remove', {
        confirm: removeConfirm.value,
        delete_data: removeData.value,
        delete_adopted_volumes: removeAdopted.value && removeDataSure.value,
    });

    removeBusy.value = false;

    if (ok) {
        removing.value = false;
        toast.success(
            trans('Removing :displayName…', {
                displayName: String(displayName.value),
            }),
        );
        router.visit(`/machines/${props.machine.id}`);
    } else {
        removeError.value =
            (body.errors as Record<string, string[]> | undefined)
                ?.confirm?.[0] ??
            (body.errors as Record<string, string[]> | undefined)
                ?.delete_adopted_volumes?.[0] ??
            (body.error as string | undefined) ??
            trans('Couldn’t remove it.');
    }
}

watch(removing, (open) => {
    if (open) {
        removeConfirm.value = '';
        removeData.value = false;
        removeDataSure.value = false;
        removeError.value = null;
    }
});

watch(removeData, (on) => {
    if (!on) {
        removeDataSure.value = false;
    }
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="displayName" />

    <WorkspacePage :title="displayName">
        <template #title>
            <div class="flex min-w-0 items-center gap-3">
                <ServiceLogo
                    :name="service.name"
                    :kind="service.kind"
                    :service-key="service.key"
                    :image="service.details?.image"
                    :icon-url="service.icon"
                />
                <h1
                    class="truncate text-[21px]/7 font-semibold tracking-[-0.01em]"
                >
                    {{ displayName }}
                </h1>
                <button
                    v-if="canManage"
                    type="button"
                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                    :class="focusRing"
                    :title="$t('Edit name and icon')"
                    :aria-label="$t('Edit service')"
                    @click="openRename"
                >
                    <Pencil class="size-3.5" :stroke-width="1.75" />
                </button>
                <span
                    class="flex shrink-0 items-center gap-1.5 rounded-full border border-(--ds-border-strong) px-2.5 py-0.5 text-xs/4 font-medium"
                >
                    <span class="size-1.5 rounded-full" :class="status.dot" />
                    {{ status.label }}
                </span>
            </div>
        </template>
        <template #description>
            {{ service.manager }} · {{ service.kind }}
            <template v-if="service.ports.length">
                ·
                <span class="font-ui-mono text-[13px]">{{
                    service.ports.map((p) => `:${p}`).join(' ')
                }}</span>
            </template>
            <template v-for="link in links" :key="link">
                ·
                <a
                    :href="link"
                    target="_blank"
                    rel="noopener"
                    class="font-ui-mono text-[13px] text-(--ds-text) hover:underline hover:underline-offset-4"
                    >{{ link.replace('http://', '') }}</a
                >
            </template>
            {{ $t('· on') }}
            <a
                :href="`/machines/${machine.id}`"
                class="text-(--ds-text) hover:underline hover:underline-offset-4"
                >{{ machine.name }}</a
            >
        </template>

        <template #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Service')"
                >
                    <button
                        v-for="option in tabs"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="option.current"
                        @click="tab = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
        </template>

        <template v-if="managed && canManage" #actions>
            <Link
                :href="`/fixes?machine=${machine.id}&service=${encodeURIComponent(service.key)}`"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :title="
                    $t('The agent reads its logs and explains what’s wrong')
                "
            >
                <Sparkles class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Look into it') }}
            </Link>
            <button
                v-if="service.container"
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                @click="openConsole"
            >
                <SquareTerminal
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                />
                {{ $t('Console') }}
            </button>
            <button
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :disabled="!canControl || working !== null"
                @click="lifecycle('restart')"
            >
                <LoaderCircle
                    v-if="working === 'restart'"
                    class="size-3.5 animate-spin opacity-60"
                    :stroke-width="2"
                />
                <RotateCcw
                    v-else
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                />
                {{ $t('Restart') + ' ' }}
            </button>
            <button
                v-if="running"
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :disabled="!canControl || working !== null"
                @click="lifecycle('stop')"
            >
                <LoaderCircle
                    v-if="working === 'stop'"
                    class="size-3.5 animate-spin opacity-60"
                    :stroke-width="2"
                />
                <Square
                    v-else
                    class="size-3.5 opacity-60"
                    :stroke-width="1.75"
                />
                {{ $t('Stop') + ' ' }}
            </button>
            <button
                v-else
                type="button"
                class="ds-raised-button cursor-pointer"
                :class="focusRing"
                :disabled="!canControl || working !== null"
                @click="lifecycle('start')"
            >
                <LoaderCircle
                    v-if="working === 'start'"
                    class="size-3.5 animate-spin opacity-60"
                    :stroke-width="2"
                />
                <Play v-else class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Start') + ' ' }}
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
                    class="ds-modal min-w-48 font-ui"
                    align="end"
                >
                    <DropdownMenuItem
                        :disabled="!canControl || working !== null"
                        @select="lifecycle('reload')"
                    >
                        <RefreshCw class="size-4" :stroke-width="1.75" />
                        {{ $t('Reload config') + ' ' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canManage && clone"
                        :disabled="
                            clone.unavailable !== null || working !== null
                        "
                        :title="clone.unavailable ?? undefined"
                        @select="cloning = true"
                    >
                        <CopyPlus class="size-4" :stroke-width="1.75" />
                        {{ $t('Clone') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canManage" @select="toggleHidden">
                        <component
                            :is="service.hidden ? Eye : EyeOff"
                            class="size-4"
                            :stroke-width="1.75"
                        />
                        {{
                            service.hidden
                                ? $t('Show in lists again')
                                : $t('Hide from lists')
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canManage" @select="toggleLogs">
                        <component
                            :is="service.logsMuted ? FileText : FileX"
                            class="size-4"
                            :stroke-width="1.75"
                        />
                        {{
                            service.logsMuted
                                ? $t('Keep its logs again')
                                : $t('Stop keeping its logs')
                        }}
                    </DropdownMenuItem>
                    <template v-if="removable">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                            :disabled="!canRemove"
                            @select="removing = true"
                        >
                            <Trash2
                                class="size-4 text-current"
                                :stroke-width="1.75"
                            />
                            {{ $t('Remove service') + ' ' }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <!-- Not adopted yet: say so, and offer it -->
        <div
            v-if="!managed"
            class="ds-tile flex flex-wrap items-center gap-4 px-5 py-4"
        >
            <div class="flex min-w-0 grow flex-col gap-1">
                <p class="text-sm font-medium">
                    {{
                        ' ' +
                        $t(
                            'ServerOS found this service but doesn’t control it yet',
                        ) +
                        ' '
                    }}
                </p>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Adopt it to start, stop and restart it, read its logs and run commands. Adopting changes nothing about the service itself.',
                        ) +
                        ' '
                    }}
                </p>
            </div>
            <AppButton
                v-if="canManage"
                variant="primary"
                size="sm"
                :disabled="!commandsEnabled || !online || working !== null"
                @click="adopt"
            >
                {{
                    working === 'adopt' ? $t('Adopting…') : $t('Adopt service')
                }}
            </AppButton>
        </div>

        <!-- Pterodactyl's Wings files are gone: offer the repair -->
        <div
            v-if="needsRepair"
            class="ds-tile flex flex-wrap items-center gap-4 px-5 py-4"
        >
            <div class="flex min-w-0 grow flex-col gap-1">
                <p class="text-sm font-medium">
                    {{
                        ' ' +
                        $t(
                            'This container needs repairing before it can start',
                        ) +
                        ' '
                    }}
                </p>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Pterodactyl set it up with files Wings creates each time it starts a server. Wings isn’t running any more, so Docker won’t start it. :value',
                            {
                                value: managed
                                    ? $t(
                                          'ServerOS can recreate it without those files and keep its data.',
                                      )
                                    : $t(
                                          'Adopt it first, then ServerOS can repair it.',
                                      ),
                            },
                        )
                    }}
                </p>
            </div>
            <AppButton
                v-if="canManage && managed"
                variant="primary"
                size="sm"
                :disabled="!commandsEnabled || !online || working !== null"
                @click="repairing = true"
            >
                {{ working === 'repair' ? $t('Repairing…') : $t('Repair') }}
            </AppButton>
        </div>

        <div v-if="tab === 'overview'" class="flex flex-col gap-5">
            <div v-if="app" class="grid gap-4 lg:grid-cols-2">
                <section
                    class="ds-tile flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center"
                    :class="dragging && 'ring-2 ring-[#3B7BFF]/60'"
                    :aria-label="$t('Code')"
                    @dragover.prevent="dragging = canManage"
                    @dragleave="dragging = false"
                    @drop.prevent="canManage && dropVersion($event)"
                >
                    <span class="ds-icon-chip shrink-0">
                        <Package class="size-4" />
                    </span>
                    <div class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="text-[13px] text-(--ds-text-muted)">{{
                            $t('Code')
                        }}</span>
                        <span class="truncate text-sm font-medium">
                            {{
                                app.fromUpload ? $t('Uploaded .zip') : app.repo
                            }}
                            <span
                                v-if="liveCommit"
                                class="font-mono text-xs font-normal text-(--ds-text-faint)"
                                >{{ liveCommit.slice(0, 7) }}</span
                            >
                        </span>
                        <span
                            v-if="zip.uploading.value"
                            class="text-xs text-(--ds-text-faint) tabular-nums"
                        >
                            {{
                                zip.progress.value >= 1
                                    ? $t('Unpacking…')
                                    : $t('Uploading :round%', {
                                          round: String(
                                              Math.round(
                                                  zip.progress.value * 100,
                                              ),
                                          ),
                                      })
                            }}
                        </span>
                        <span
                            v-else-if="zip.error.value"
                            class="text-xs text-(--ds-red)"
                            >{{ zip.error.value }}</span
                        >
                        <span v-else class="text-xs text-(--ds-text-faint)">{{
                            $t(
                                'Drop a .zip here to ship a new version. Domain and environment stay the same, and the current version keeps running until the new one is healthy.',
                            )
                        }}</span>
                    </div>
                    <template v-if="canManage">
                        <input
                            ref="zipInput"
                            type="file"
                            accept=".zip,application/zip"
                            class="hidden"
                            @change="pickVersion"
                        />
                        <AppButton
                            class="shrink-0"
                            :disabled="
                                zip.uploading.value ||
                                shipping ||
                                !online ||
                                !hasPlan
                            "
                            @click="zipInput?.click()"
                        >
                            {{
                                zip.uploading.value || shipping
                                    ? $t('Uploading…')
                                    : $t('Upload new version')
                            }}
                        </AppButton>
                    </template>
                </section>

                <section
                    class="ds-tile flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center"
                    :aria-label="$t('Domain')"
                >
                    <span class="ds-icon-chip shrink-0">
                        <Globe class="size-4" />
                    </span>
                    <div class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="text-[13px] text-(--ds-text-muted)">{{
                            $t('Domain')
                        }}</span>
                        <a
                            v-if="app.domains.length"
                            :href="`https://${app.domains[0]}`"
                            target="_blank"
                            rel="noopener"
                            class="truncate text-sm font-medium hover:underline"
                            >{{ app.domains[0] }}</a
                        >
                        <span
                            v-if="servesWildcard"
                            class="text-xs text-(--ds-text-muted)"
                            >{{
                                $t('and every subdomain, *.:domain', {
                                    domain: app.domains[0],
                                })
                            }}</span
                        >
                        <span
                            v-if="!app.domains.length"
                            class="text-sm font-medium"
                            >{{ $t('None yet') }}</span
                        >
                        <span class="text-xs text-(--ds-text-faint)">{{
                            app.canMove
                                ? $t(
                                      'Moves with the same code and settings. HTTPS is set up for the new one.',
                                  )
                                : $t(
                                      'Upload the code again as a new version to change this.',
                                  )
                        }}</span>
                    </div>
                    <AppButton
                        v-if="canManage"
                        class="shrink-0"
                        :disabled="!app.canMove || !online"
                        @click="openMove"
                    >
                        {{
                            app.domains.length
                                ? $t('Change domain')
                                : $t('Add domain')
                        }}
                    </AppButton>
                </section>

                <section
                    class="ds-tile flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center lg:col-span-2"
                    :aria-label="$t('Environment variables')"
                >
                    <span class="ds-icon-chip shrink-0">
                        <Settings class="size-4" />
                    </span>
                    <div class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="text-[13px] text-(--ds-text-muted)">{{
                            $t('Environment variables')
                        }}</span>
                        <span
                            v-if="app.variables.length"
                            class="truncate font-ui-mono text-xs/5"
                            :title="app.variables.join(', ')"
                            >{{ app.variables.join(', ') }}</span
                        >
                        <span v-else class="text-sm font-medium">{{
                            $t('None yet')
                        }}</span>
                        <span class="text-xs text-(--ds-text-faint)">{{
                            $t(
                                'Saving a change deploys it again. This version keeps serving until the new one is up.',
                            )
                        }}</span>
                    </div>
                    <AppButton
                        v-if="canManage"
                        class="shrink-0"
                        :disabled="!online"
                        @click="variablesOpen = true"
                    >
                        {{ $t('Edit') }}
                    </AppButton>
                </section>
            </div>
            <AppVariablesDialog
                v-if="canManage && app"
                v-model:open="variablesOpen"
                :machine-id="machine.id!"
                :app="app.service"
                @saved="variablesSaved"
            />

            <section
                v-if="app && app.versions.length"
                class="ds-tile overflow-hidden"
                aria-labelledby="versions-title"
            >
                <div
                    class="ds-tile-head flex flex-wrap items-center justify-between gap-3 border-b border-(--ds-divider) px-5 py-3"
                >
                    <div class="flex min-w-0 flex-col">
                        <h2 id="versions-title" class="text-sm font-medium">
                            {{ ' ' + $t('Versions') + ' ' }}
                        </h2>
                        <p class="text-xs text-(--ds-text-faint)">
                            {{
                                ' ' +
                                $t(
                                    'The machine keeps the last :value to roll back to.',
                                    {
                                        value:
                                            app.versions.length === 1
                                                ? $t('one')
                                                : $t('few'),
                                    },
                                ) +
                                ' '
                            }}
                        </p>
                    </div>
                    <label
                        class="flex items-center gap-2.5 text-[13px] text-(--ds-text-secondary)"
                    >
                        {{ ' ' + $t('Snapshot data before each deploy') }}
                        <ToggleSwitch
                            v-model="snapshotBefore"
                            :label="$t('Snapshot data before each deploy')"
                            :disabled="!canManage"
                            @update:model-value="saveSnapshotBefore"
                        />
                    </label>
                    <label
                        v-if="snapshotBefore"
                        class="flex w-full flex-wrap items-center gap-2.5 text-[13px] text-(--ds-text-secondary)"
                    >
                        {{ ' ' + $t('Its database') }}
                        <select
                            class="ds-select ds-raised-button h-8 cursor-pointer appearance-none pr-8! text-[13px]"
                            :value="app.database.setting"
                            :disabled="!canManage"
                            @change="
                                saveDatabase(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="auto">
                                {{
                                    app.database.setting === 'auto' &&
                                    app.database.current
                                        ? $t('From its settings: :display', {
                                              display: String(
                                                  app.database.current.display,
                                              ),
                                          })
                                        : $t('From its settings')
                                }}
                            </option>
                            <option
                                v-for="option in app.database.options"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                            <option value="none">{{ $t('None') }}</option>
                        </select>
                        <span class="text-xs text-(--ds-text-faint)">{{
                            app.database.current
                                ? $t(
                                      'Snapshotted with each deploy, and put back by Code and data.',
                                  )
                                : app.database.options.length
                                  ? $t(
                                        'None found in its settings. Pick one if it keeps its data there.',
                                    )
                                  : $t(
                                        'No database ServerOS manages on this machine.',
                                    )
                        }}</span>
                    </label>
                </div>
                <ul class="text-[13px]/5">
                    <li
                        v-for="version in app.versions"
                        :key="version.commit"
                        class="flex items-center gap-3 border-b border-(--ds-divider) px-5 py-2.5 last:border-b-0"
                    >
                        <span class="font-mono text-xs">{{
                            version.commit.slice(0, 7)
                        }}</span>
                        <span
                            v-if="version.live"
                            class="rounded-md bg-(--ds-green)/15 px-1.5 text-xs/5 font-medium text-(--ds-green)"
                            >{{ $t('Live') }}</span
                        >
                        <span
                            class="min-w-0 grow truncate text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t('Deployed :value', {
                                    value: version.deployedAt
                                        ? timeAgo(version.deployedAt)
                                        : '',
                                })
                            }}<template v-if="version.by">
                                {{
                                    ' ' + $t('by :by', { by: version.by })
                                }}</template
                            >
                        </span>
                        <AppButton
                            v-if="!version.live && canManage"
                            size="sm"
                            :disabled="!online || working !== null || !hasPlan"
                            @click="askRollback(version)"
                        >
                            <RotateCcw class="size-3.5" :stroke-width="1.75" />
                            {{ $t('Roll back') + ' ' }}
                        </AppButton>
                    </li>
                </ul>
            </section>

            <ul
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                :aria-label="$t('Now')"
            >
                <li class="ds-tile flex flex-col gap-1 px-5 py-4">
                    <span class="text-[13px] text-(--ds-text-muted)">{{
                        $t('CPU')
                    }}</span>
                    <span class="text-[28px]/9 font-medium tabular-nums">{{
                        stats?.cpu === null || stats?.cpu === undefined
                            ? '—'
                            : percent(stats.cpu)
                    }}</span>
                    <span class="text-xs text-(--ds-text-faint)">{{
                        $t('of the whole machine')
                    }}</span>
                </li>
                <li class="ds-tile flex flex-col gap-1 px-5 py-4">
                    <span class="text-[13px] text-(--ds-text-muted)">{{
                        $t('Memory')
                    }}</span>
                    <span class="text-[28px]/9 font-medium tabular-nums">{{
                        bytes(stats?.memUsed)
                    }}</span>
                    <span class="text-xs text-(--ds-text-faint)">{{
                        memLimit
                            ? $t('limit :value', { value: bytes(memLimit) })
                            : $t('no limit set')
                    }}</span>
                </li>
                <li class="ds-tile flex flex-col gap-1 px-5 py-4">
                    <span class="text-[13px] text-(--ds-text-muted)">{{
                        $t('Uptime')
                    }}</span>
                    <span class="text-[28px]/9 font-medium tabular-nums">{{
                        uptime(stats?.startedAt)
                    }}</span>
                    <span
                        class="text-xs"
                        :class="
                            (stats?.restarts ?? 0) > 0
                                ? 'text-(--ds-amber)'
                                : 'text-(--ds-text-faint)'
                        "
                        >{{
                            stats?.restarts === null ||
                            stats?.restarts === undefined
                                ? $t('restarts unknown')
                                : $t(
                                      stats.restarts === 1
                                          ? ':count restart'
                                          : ':count restarts',
                                      { count: String(stats.restarts) },
                                  )
                        }}</span
                    >
                </li>
                <li class="ds-tile flex flex-col gap-1 px-5 py-4">
                    <span class="text-[13px] text-(--ds-text-muted)">{{
                        $t('Processes')
                    }}</span>
                    <span class="text-[28px]/9 font-medium tabular-nums">{{
                        stats?.pids ?? '—'
                    }}</span>
                    <span class="text-xs text-(--ds-text-faint)">{{
                        $t('threads and processes')
                    }}</span>
                </li>
            </ul>

            <div class="flex items-center justify-between gap-3">
                <h2 class="text-[15px] font-medium">{{ $t('Usage') }}</h2>
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
                        @click="setRange(option)"
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
                        },
                    ]"
                    :format="bytes"
                    :min-max="1024 * 1024"
                />
                <TimeSeriesChart
                    v-if="service.container"
                    class="lg:col-span-2"
                    :title="$t('Network')"
                    :timestamps="timestamps"
                    :series="[
                        {
                            label: $t('In'),
                            color: '#A78BFA',
                            values: history.map((p) => p.rx),
                        },
                        {
                            label: $t('Out'),
                            color: '#FBBF24',
                            values: history.map((p) => p.tx),
                        },
                    ]"
                    :format="rate"
                    :min-max="1024"
                />
            </div>
        </div>

        <div v-else-if="tab === 'logs'" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <label class="ds-raised-button w-full cursor-text sm:w-64">
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Filter log lines') }}</span>
                    <input
                        v-model="logFilter"
                        type="search"
                        :placeholder="$t('Filter lines')"
                        class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                    />
                </label>
                <div class="ml-auto flex items-center gap-2">
                    <a
                        :href="historyUrl"
                        class="ds-raised-button cursor-pointer text-[13px]"
                        :class="focusRing"
                        :title="
                            $t(
                                'Every line kept for this service, searchable and live',
                            )
                        "
                    >
                        <History
                            class="size-3.5 opacity-60"
                            :stroke-width="1.75"
                        />
                        {{ $t('Search history') + ' ' }}
                    </a>
                    <label class="relative">
                        <span class="sr-only">{{ $t('Lines') }}</span>
                        <select
                            v-model.number="logLines"
                            class="ds-select ds-raised-button h-8 cursor-pointer appearance-none pr-8! text-[13px]"
                        >
                            <option :value="100">
                                {{ $t('Last 100 lines') }}
                            </option>
                            <option :value="300">
                                {{ $t('Last 300 lines') }}
                            </option>
                            <option :value="1000">
                                {{ $t('Last 1000 lines') }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer"
                        :disabled="!canControl || busy !== null"
                        @click="fetchLogs"
                    >
                        <RefreshCw
                            class="size-3.5 opacity-60"
                            :class="{
                                'mi-spin':
                                    logsJob &&
                                    ['queued', 'running'].includes(
                                        logsJob.state,
                                    ),
                            }"
                            :stroke-width="1.75"
                        />
                        {{ $t('Refresh') + ' ' }}
                    </button>
                </div>
            </div>
            <div
                ref="logBox"
                class="ds-tile h-[60vh] overflow-auto bg-(--ds-code)! py-3 font-ui-mono text-xs/5"
                role="log"
                :aria-label="$t('Service logs')"
            >
                <p v-if="!managed" class="px-4 text-(--ds-text-faint)">
                    {{ ' ' + $t('Adopt the service to read its logs.') + ' ' }}
                </p>
                <p
                    v-else-if="
                        !logsJob ||
                        (['queued', 'running'].includes(logsJob.state) &&
                            logs.length === 0)
                    "
                    class="px-4 text-(--ds-text-faint)"
                >
                    {{ ' ' + $t('Fetching logs…') + ' ' }}
                </p>
                <p v-else-if="logsJob.error" class="px-4 text-(--ds-red)">
                    {{ logsJob.error.message }}
                </p>
                <p
                    v-else-if="logs.length === 0"
                    class="px-4 text-(--ds-text-faint)"
                >
                    {{
                        logFilter ? $t('No lines match.') : $t('No log output.')
                    }}
                </p>
                <div
                    v-for="(line, index) in logs"
                    :key="index"
                    class="px-4 break-words whitespace-pre-wrap text-(--ds-text-secondary) hover:bg-white/3"
                >
                    {{ line }}
                </div>
            </div>
        </div>

        <FileBrowser
            v-else-if="tab === 'files'"
            :machine-id="machine.id ?? 0"
            :places="files.places"
            :max-write="files.maxWrite"
            :max-read="files.maxRead"
            :console-lock="consoleLock"
            :available="canManage && commandsEnabled && online"
            :empty-hint="
                managed
                    ? $t(
                          'ServerOS didn’t find a folder for this service when it adopted it, so there’s nowhere it may look.',
                      )
                    : $t(
                          'ServerOS can open the files of services it deployed or adopted. Adopt this service to browse its files here.',
                      )
            "
        />

        <div v-else class="flex flex-col gap-5">
            <dl
                class="ds-tile grid gap-x-6 gap-y-4 p-5 text-[13px]/5 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div>
                    <dt class="text-(--ds-text-muted)">{{ $t('Key') }}</dt>
                    <dd class="font-ui-mono break-all">{{ service.key }}</dd>
                </div>
                <div>
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Managed by ServerOS') }}
                    </dt>
                    <dd>
                        {{
                            managed
                                ? service.origin === 'created'
                                    ? $t('Yes, ServerOS created it')
                                    : 'Yes, adopted'
                                : $t('No, discovered only')
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-(--ds-text-muted)">{{ $t('Started') }}</dt>
                    <dd>
                        {{
                            stats?.startedAt
                                ? new Date(
                                      stats.startedAt * 1000,
                                  ).toLocaleString()
                                : $t('Unknown')
                        }}
                    </dd>
                </div>
                <div v-if="service.details?.image">
                    <dt class="text-(--ds-text-muted)">{{ $t('Image') }}</dt>
                    <dd class="font-ui-mono break-all">
                        {{ service.details.image }}
                    </dd>
                </div>
                <div v-if="service.details?.compose_project">
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Compose project') }}
                    </dt>
                    <dd class="font-ui-mono">
                        {{ service.details.compose_project }}
                    </dd>
                </div>
                <div v-if="service.details?.restart_policy">
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Restart policy') }}
                    </dt>
                    <dd>{{ service.details.restart_policy }}</dd>
                </div>
                <div>
                    <dt class="text-(--ds-text-muted)">{{ $t('Version') }}</dt>
                    <dd>{{ service.version || $t('Unknown') }}</dd>
                </div>
                <div>
                    <dt class="text-(--ds-text-muted)">{{ $t('Run as') }}</dt>
                    <dd>{{ service.user || $t('Unknown') }}</dd>
                </div>
                <div>
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Capabilities') }}
                    </dt>
                    <dd>
                        {{
                            service.capabilities?.join(', ') ||
                            $t('Observation only')
                        }}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Working directory') }}
                    </dt>
                    <dd class="font-ui-mono break-all">
                        {{ service.working_dir || $t('Unknown') }}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Executable') }}
                    </dt>
                    <dd class="font-ui-mono break-all">
                        {{ service.exec || $t('Unknown') }}
                    </dd>
                </div>
                <div
                    v-if="service.config_paths?.length"
                    class="sm:col-span-2 lg:col-span-3"
                >
                    <dt class="text-(--ds-text-muted)">
                        {{ $t('Config files') }}
                    </dt>
                    <dd class="font-ui-mono break-all">
                        {{ service.config_paths.join(', ') }}
                    </dd>
                </div>
                <div
                    v-if="service.details?.warning"
                    class="sm:col-span-2 lg:col-span-3"
                >
                    <dt class="text-(--ds-amber)">{{ $t('Heads up') }}</dt>
                    <dd>{{ service.details.warning }}</dd>
                </div>
            </dl>

            <ContainerConfig
                v-if="inspect"
                :job="inspectJob"
                :can-load="canInspect"
                :unavailable="
                    inspect.available
                        ? null
                        : $t(
                              'This machine’s daemon is too old to show container config. Update it with “sudo serverosd update” on the server.',
                          )
                "
                :loading="busy === 'inspect'"
                @load="loadInspect"
            />
        </div>

        <!-- Domain -->
        <Dialog v-if="app" v-model:open="moving">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        app.domains.length
                            ? $t('Change domain')
                            : $t('Add a domain')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'The new version starts beside the current one, and traffic moves once it’s healthy.',
                            )
                        }}
                        <template v-if="movingAway">{{
                            $t(':domains stops working after that.', {
                                domains: app.domains[0],
                            })
                        }}</template>
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="moveDomain">
                    <div class="grid gap-2">
                        <label for="new-domain" class="text-[13px]/5">{{
                            $t('Domain')
                        }}</label>
                        <Input
                            id="new-domain"
                            v-model="newDomain"
                            :placeholder="$t('app.example.com')"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono"
                        />
                        <p
                            v-if="moveDns?.status === 'ready'"
                            class="text-xs text-(--ds-green)"
                        >
                            {{ ' ' + $t('Points at this machine.') + ' ' }}
                        </p>
                        <p
                            v-else-if="moveDns?.record"
                            class="text-xs text-(--ds-text-muted)"
                        >
                            {{
                                moveDns.status === 'proxied'
                                    ? $t(
                                          'It’s behind Cloudflare’s proxy. Set it to DNS only until HTTPS is issued, with',
                                      )
                                    : $t('Add a DNS record:')
                            }}
                            <span class="font-ui-mono text-(--ds-text)"
                                >{{ moveDns.record.type }}
                                {{ moveDns.record.name }} →
                                {{ moveDns.record.value }}</span
                            >{{
                                $t('. HTTPS starts once it points here.') + ' '
                            }}
                        </p>
                    </div>
                    <label
                        class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox v-model="moveWildcard" class="mt-0.5" />
                        <span class="flex flex-col gap-0.5">
                            <span class="text-(--ds-text)">{{
                                $t('Allow wildcard subdomains')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-faint)">{{
                                $t(
                                    'Every subdomain, like tenant.:domain, is served by this app and gets its own certificate the first time it’s visited. No DNS provider needed.',
                                    {
                                        domain:
                                            bareDomain(newDomain) ||
                                            'example.com',
                                    },
                                )
                            }}</span>
                            <span
                                v-if="
                                    moveWildcard &&
                                    moveDns?.wildcard &&
                                    moveDns.wildcard.status !== 'ready' &&
                                    moveDns.wildcard.record
                                "
                                class="text-xs/4 text-(--ds-text-muted)"
                                >{{ $t('Subdomains need a record too:') }}
                                <span class="font-ui-mono text-(--ds-text)"
                                    >{{ moveDns.wildcard.record.type }}
                                    {{ moveDns.wildcard.record.name }} →
                                    {{ moveDns.wildcard.record.value }}</span
                                ></span
                            >
                        </span>
                    </label>
                    <label
                        v-if="app.mentions.length && movingAway"
                        class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox v-model="moveSettings" class="mt-0.5" />
                        <span
                            >{{ $t('Also update') }}
                            <span class="font-ui-mono text-(--ds-text)">{{
                                app.mentions.join(', ')
                            }}</span>
                            {{ $t('to the new domain.') }}</span
                        >
                    </label>
                    <InputError :message="moveError ?? undefined" />
                    <div class="flex justify-end gap-2">
                        <AppButton size="sm" @click="moving = false">
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <SubmitButton
                            :label="
                                !app.domains.length
                                    ? $t('Add domain')
                                    : movingAway
                                      ? $t('Change domain')
                                      : $t('Save')
                            "
                            busy-label="Starting…"
                            :processing="moveBusy"
                            :disabled="!newDomain.trim()"
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="renaming">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('Edit service') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'A name and icon to show in ServerOS, so it’s clear what this is. Nothing changes on the machine. It was found as',
                            )
                        }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            service.app ?? service.name
                        }}</span
                        >.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex min-w-0 flex-col gap-4"
                    @submit.prevent="saveLabel(newLabel.trim() || null)"
                >
                    <div class="grid gap-2">
                        <label for="service-label" class="text-[13px]/5">{{
                            $t('Name')
                        }}</label>
                        <Input
                            id="service-label"
                            v-model="newLabel"
                            maxlength="60"
                            autocomplete="off"
                            :placeholder="service.app ?? service.name"
                        />
                        <InputError :message="renameError ?? undefined" />
                    </div>
                    <ImageUploadField
                        field="icon"
                        max-size="2 MB"
                        :label="$t('Icon')"
                        :image-url="service.icon ?? null"
                        :fallback="displayName.charAt(0).toUpperCase()"
                        :upload-url="`${serviceUrl}/icon`"
                        :remove-url="`${serviceUrl}/icon`"
                    />
                    <div class="flex items-center justify-between gap-2">
                        <AppButton
                            v-if="service.label"
                            size="sm"
                            variant="quiet"
                            :disabled="renameBusy"
                            @click="saveLabel(null)"
                        >
                            {{ ' ' + $t('Use original name') + ' ' }}
                        </AppButton>
                        <span v-else />
                        <div class="flex gap-2">
                            <AppButton size="sm" @click="renaming = false">
                                {{ ' ' + $t('Cancel') + ' ' }}
                            </AppButton>
                            <SubmitButton
                                :label="$t('Save')"
                                busy-label="Saving…"
                                :processing="renameBusy"
                            />
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="rollbackOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            ' ' +
                            $t('Roll :service back to :value?', {
                                service: String(app?.service ?? ''),
                                value: String(
                                    rollingBack?.commit.slice(0, 7) ?? '',
                                ),
                            }) +
                            ' '
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'The version from :value starts again and takes over its address. The newer one stops, and you can roll forward to it later.',
                                {
                                    value: rollingBack?.deployedAt
                                        ? new Date(
                                              rollingBack.deployedAt,
                                          ).toLocaleString()
                                        : 'before',
                                },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="rollback">
                    <div
                        class="ds-segments self-start"
                        role="group"
                        :aria-label="$t('What to roll back')"
                    >
                        <button
                            type="button"
                            class="cursor-pointer"
                            :aria-pressed="!rollbackData"
                            @click="rollbackData = false"
                        >
                            {{ ' ' + $t('Code only') + ' ' }}
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                            :aria-pressed="rollbackData"
                            :disabled="!rollingBack?.data"
                            @click="rollbackData = true"
                        >
                            {{ ' ' + $t('Code and data') + ' ' }}
                        </button>
                    </div>
                    <p class="text-[13px]/5 text-(--ds-text-secondary)">
                        <template v-if="rollbackData && rollingBack?.dataAt">
                            {{
                                ' ' +
                                $t(
                                    'Its data goes back to the snapshot from :value, just before the next deploy. Anything written since is replaced. A snapshot is taken first, so you can undo it from Backups.',
                                    {
                                        value: new Date(
                                            rollingBack.dataAt,
                                        ).toLocaleString(),
                                    },
                                ) +
                                ' '
                            }}
                        </template>
                        <template v-else-if="rollingBack?.data">
                            {{
                                ' ' +
                                $t(
                                    'Its data stays as it is now. Choose Code and data if the newer version changed it, with a migration say.',
                                ) +
                                ' '
                            }}
                        </template>
                        <template v-else>
                            {{
                                ' ' +
                                $t(
                                    'There’s no snapshot of its data from then, so only the code goes back. Restore a backup for the data.',
                                ) +
                                ' '
                            }}
                        </template>
                    </p>
                    <div class="grid gap-2">
                        <label for="rollback-confirm" class="text-[13px]/5">
                            {{ ' ' + $t('Type') }}
                            <span class="font-ui-mono text-(--ds-text)">{{
                                app?.service
                            }}</span>
                            {{ $t('to confirm.') + ' ' }}
                        </label>
                        <Input
                            id="rollback-confirm"
                            v-model="rollbackConfirm"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono"
                        />
                    </div>
                    <InputError :message="rollbackError ?? undefined" />
                    <div class="flex justify-end gap-2">
                        <AppButton size="sm" @click="rollbackOpen = false">
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <DangerButton
                            :label="$t('Roll back')"
                            busy-label="Starting…"
                            :processing="rollbackBusy"
                            :disabled="
                                !rollbackBusy &&
                                rollbackConfirm !== app?.service
                            "
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="repairing">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        ' ' + $t('Repair :name?', { name: service.name }) + ' '
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'ServerOS recreates the container with the same name, image, settings, ports and data, without the Wings files, and starts it. It will also come back after a reboot. If it won’t start, the old one is put back.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="repairing = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <AppButton variant="primary" size="sm" @click="repair">
                        {{ $t('Repair') }}
                    </AppButton>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="removing">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Remove :name?', { name: service.name })
                    }}</DialogTitle>
                    <DialogDescription v-if="!service.container">
                        {{
                            ' ' +
                            $t(
                                'It stops, won’t start again at boot, and its unit file is deleted if it was made on this machine. A service installed by a package keeps its files: uninstall the package to remove it completely. Files and data it uses are never touched.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                    <DialogDescription v-else-if="removeAdopted">
                        {{
                            ' ' +
                            $t(
                                'You adopted this one, so only its container stops and is deleted. :value Its volumes are kept unless you choose otherwise, and folders it uses on the machine are never touched.',
                                {
                                    value: service.details?.compose_project
                                        ? $t(
                                              'Nothing else in the :compose_project project is touched.',
                                              {
                                                  compose_project: String(
                                                      service.details
                                                          .compose_project,
                                                  ),
                                              },
                                          )
                                        : '',
                                },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                    <DialogDescription v-else>
                        {{
                            ' ' +
                            $t(
                                'Its containers stop and are deleted. :value Data in volumes is kept unless you choose otherwise.',
                                {
                                    value: service.details?.compose_project
                                        ? $t(
                                              'Everything in the :compose_project project goes with it.',
                                              {
                                                  compose_project: String(
                                                      service.details
                                                          .compose_project,
                                                  ),
                                              },
                                          )
                                        : '',
                                },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="remove">
                    <div class="grid gap-2">
                        <label for="remove-confirm" class="text-[13px]/5">
                            {{ ' ' + $t('Type') }}
                            <span class="font-ui-mono text-(--ds-text)">{{
                                service.name
                            }}</span>
                            {{ $t('to confirm.') + ' ' }}
                        </label>
                        <Input
                            id="remove-confirm"
                            v-model="removeConfirm"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono"
                        />
                    </div>
                    <p
                        v-if="!managed"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'ServerOS doesn’t control it yet, so it adopts it first, then removes it.',
                            )
                        }}
                    </p>
                    <label
                        v-if="service.container"
                        class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox v-model="removeData" class="mt-0.5" />
                        <span>{{
                            $t(
                                'Also delete its data (volumes). This can’t be undone.',
                            )
                        }}</span>
                    </label>
                    <label
                        v-if="removeAdopted && removeData"
                        class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox v-model="removeDataSure" class="mt-0.5" />
                        <span>{{
                            $t(
                                'ServerOS didn’t create this service. Delete its Docker volumes anyway.',
                            )
                        }}</span>
                    </label>
                    <InputError :message="removeError ?? undefined" />
                    <div class="flex justify-end gap-2">
                        <AppButton size="sm" @click="removing = false">
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <DangerButton
                            :label="$t('Remove service')"
                            busy-label="Removing…"
                            :processing="removeBusy"
                            :disabled="
                                !removeBusy &&
                                (removeConfirm !== service.name ||
                                    (removeAdopted &&
                                        removeData &&
                                        !removeDataSure))
                            "
                            :error="removeError"
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>
        <CloneServiceDialog
            v-if="clone"
            v-model:open="cloning"
            :machine-id="machine.id!"
            :service-key="service.key"
            :display-name="String(displayName)"
            :defaults="{ name: clone.name, port: clone.port }"
            @started="cloneStarted"
        />
        <!-- Console: a terminal sliding up over the page -->
        <Teleport to="body">
            <Transition name="console-sheet">
                <div
                    v-if="consoleSheet"
                    class="fixed inset-0 z-50 flex flex-col justify-end"
                    @keydown.esc="consoleSheet = false"
                >
                    <div
                        class="console-scrim absolute inset-0 bg-black/40"
                        aria-hidden="true"
                        @click="consoleSheet = false"
                    />
                    <section
                        role="dialog"
                        aria-modal="true"
                        :aria-label="$t('Console')"
                        class="console-panel dark workspace-panel relative mx-2 mb-2 flex h-[min(80vh,780px)] flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-[#151514] font-ui text-(--ds-text) shadow-[0_-24px_80px_-20px_rgb(0_0_0/0.6)]"
                    >
                        <header
                            class="flex h-11 shrink-0 items-center gap-2.5 border-b border-white/[0.06] bg-[#1b1b1a] px-4"
                        >
                            <SquareTerminal
                                class="size-4 text-(--ds-text-muted)"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span class="font-ui-mono text-[13px]/5">{{
                                displayName
                            }}</span>
                            <span
                                v-if="machine.name"
                                class="text-[12.5px] text-(--ds-text-faint)"
                                >{{
                                    $t('on :machine', { machine: machine.name })
                                }}</span
                            >
                            <span
                                v-if="hasPlan && consoleOpen"
                                class="ml-auto flex items-center gap-2 text-[12.5px] text-(--ds-text-faint)"
                            >
                                <ShieldCheck
                                    class="size-3.5 text-(--ds-green)"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                {{ $t('Locks in') }}
                                <span class="font-ui-mono tabular-nums">{{
                                    countdown
                                }}</span>
                                <button
                                    type="button"
                                    class="ml-1 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-white/[0.06] hover:text-(--ds-text)"
                                    :title="$t('Lock now')"
                                    :aria-label="$t('Lock now')"
                                    @click="lockConsole"
                                >
                                    <Lock
                                        class="size-3.5"
                                        :stroke-width="1.75"
                                    />
                                </button>
                            </span>
                            <button
                                type="button"
                                class="flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-white/[0.06] hover:text-(--ds-text)"
                                :class="{
                                    'ml-auto': !(hasPlan && consoleOpen),
                                }"
                                :aria-label="$t('Close console')"
                                @click="consoleSheet = false"
                            >
                                <X
                                    class="mi-close size-4"
                                    :stroke-width="1.75"
                                />
                            </button>
                        </header>

                        <!-- Console: needs a plan -->
                        <div
                            v-if="!hasPlan"
                            class="flex grow flex-col items-center justify-center gap-5 px-6 py-14 text-center"
                        >
                            <div class="flex max-w-md flex-col gap-1.5">
                                <h2 class="text-base font-semibold">
                                    {{
                                        ' ' +
                                        $t('The console needs a plan') +
                                        ' '
                                    }}
                                </h2>
                                <p class="text-[13px]/5 text-(--ds-text-muted)">
                                    {{
                                        $t(
                                            ':displayName keeps running as it is. Choose a plan to run commands in it again.',
                                            { displayName },
                                        ) + ' '
                                    }}
                                </p>
                            </div>
                            <Link
                                v-if="upgrade"
                                :href="upgrade.href"
                                class="ds-raised-button text-[13px]!"
                                >{{ $t('Choose a plan') }}</Link
                            >
                        </div>

                        <!-- Console: locked until 2FA -->
                        <div
                            v-else-if="
                                !consoleLock.twoFactorEnabled || !consoleOpen
                            "
                            class="flex grow flex-col items-center justify-center gap-5 px-6 py-14 text-center"
                        >
                            <span
                                class="ds-icon-chip size-12! rounded-xl!"
                                aria-hidden="true"
                            >
                                <ShieldCheck class="size-5" :stroke-width="2" />
                            </span>
                            <template v-if="!consoleLock.twoFactorEnabled">
                                <div class="flex max-w-md flex-col gap-1.5">
                                    <h2 class="text-base font-semibold">
                                        {{
                                            ' ' +
                                            $t(
                                                'The console needs two-factor authentication',
                                            ) +
                                            ' '
                                        }}
                                    </h2>
                                    <p
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            ' ' +
                                            $t(
                                                'It runs commands on a real machine, so a password alone isn’t enough. Turn on two-factor for your account, then come back to unlock it.',
                                            ) +
                                            ' '
                                        }}
                                    </p>
                                </div>
                                <AppButton
                                    variant="primary"
                                    size="sm"
                                    :href="securityEdit()"
                                >
                                    {{ ' ' + $t('Set up two-factor') + ' ' }}
                                </AppButton>
                            </template>
                            <form
                                v-else
                                class="flex flex-col items-center gap-4"
                                @submit.prevent="unlock"
                            >
                                <div class="flex max-w-md flex-col gap-1.5">
                                    <h2 class="text-base font-semibold">
                                        {{ $t('Unlock the console') }}
                                    </h2>
                                    <p
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            ' ' +
                                            $t(
                                                'Enter the 6-digit code from your authenticator app. The console stays unlocked for :minutes minutes on this device.',
                                                {
                                                    minutes: String(
                                                        consoleLock.minutes ??
                                                            '',
                                                    ),
                                                },
                                            ) +
                                            ' '
                                        }}
                                    </p>
                                </div>
                                <InputOTP
                                    v-model="unlockCode"
                                    :maxlength="6"
                                    :disabled="unlocking"
                                    autofocus
                                    @complete="unlock"
                                >
                                    <InputOTPGroup>
                                        <InputOTPSlot
                                            v-for="index in 6"
                                            :key="index"
                                            :index="index - 1"
                                        />
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError
                                    :message="unlockError ?? undefined"
                                />
                                <SubmitButton
                                    :label="$t('Unlock')"
                                    busy-label="Checking…"
                                    :processing="unlocking"
                                    :disabled="unlockCode.length !== 6"
                                />
                            </form>
                        </div>

                        <!-- The terminal -->
                        <template v-else>
                            <div
                                ref="consoleBox"
                                class="min-h-0 grow overflow-auto px-5 pt-4 pb-2 font-ui-mono text-[12.5px]/5.5"
                                role="log"
                                :aria-label="$t('Command output')"
                            >
                                <p class="mb-3 text-(--ds-text-faint)">
                                    #
                                    {{
                                        $t(
                                            'One-off commands with sh -c, up to a minute each, no interactive input. Each one is recorded with your name.',
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="!service.container"
                                    class="text-(--ds-text-faint)"
                                >
                                    {{
                                        ' ' +
                                        $t(
                                            'Commands run inside containers, and this service isn’t one.',
                                        ) +
                                        ' '
                                    }}
                                </p>
                                <p
                                    v-else-if="transcript.length === 0"
                                    class="text-(--ds-text-faint)"
                                >
                                    {{ ' ' + $t('Nothing run yet. Try') }}
                                    <span class="text-(--ds-text-secondary)">{{
                                        $t('ls -la')
                                    }}</span>
                                    {{ $t('or') }}
                                    <span class="text-(--ds-text-secondary)">{{
                                        $t('env')
                                    }}</span
                                    >.
                                </p>
                                <div
                                    v-for="job in transcript"
                                    :key="job.id"
                                    class="mb-3 flex flex-col gap-0.5"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="text-(--ds-blue)">$</span>
                                        <span class="text-(--ds-text)">{{
                                            job.command
                                        }}</span>
                                        <JobStatusBadge
                                            v-if="job.state !== 'succeeded'"
                                            :state="job.state"
                                            class="ml-auto scale-90"
                                        />
                                        <span
                                            v-else-if="
                                                job.result?.exit_code !==
                                                undefined
                                            "
                                            class="ml-auto text-[11px]"
                                            :class="
                                                job.result.exit_code === 0
                                                    ? 'text-(--ds-text-faint)'
                                                    : 'text-(--ds-red)'
                                            "
                                        >
                                            {{
                                                ' ' +
                                                $t('exit :exit_code', {
                                                    exit_code: String(
                                                        job.result.exit_code ??
                                                            '',
                                                    ),
                                                })
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        v-for="(line, i) in output(job)"
                                        :key="i"
                                        class="break-words whitespace-pre-wrap text-(--ds-text-secondary)"
                                    >
                                        {{ line }}
                                    </div>
                                    <p v-if="job.error" class="text-(--ds-red)">
                                        {{ job.error.message }}
                                    </p>
                                </div>
                            </div>
                            <form
                                class="flex shrink-0 items-center gap-2 border-t border-white/[0.06] px-5 py-3 font-ui-mono text-[13px]/5"
                                @submit.prevent="run"
                            >
                                <span
                                    class="text-(--ds-green)"
                                    aria-hidden="true"
                                    >$</span
                                >
                                <span class="sr-only">{{ $t('Command') }}</span>
                                <input
                                    ref="consoleInput"
                                    v-model="command"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :disabled="
                                        !canControl || !service.container
                                    "
                                    :placeholder="
                                        $t('e.g. redis-cli INFO memory')
                                    "
                                    class="h-6 min-w-0 grow bg-transparent text-(--ds-text) caret-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                                    @keydown.up.prevent="recall(1)"
                                    @keydown.down.prevent="recall(-1)"
                                />
                                <LoaderCircle
                                    v-if="busy !== null"
                                    class="size-3.5 animate-spin text-(--ds-text-faint)"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                <span
                                    v-else
                                    class="text-[11px] text-(--ds-text-faint)"
                                    >{{ $t('Enter to run') }}</span
                                >
                            </form>
                            <InputError
                                v-if="commandError"
                                class="px-5 pb-3"
                                :message="commandError"
                            />
                        </template>
                    </section>
                </div>
            </Transition>
        </Teleport>
    </WorkspacePage>
</template>
