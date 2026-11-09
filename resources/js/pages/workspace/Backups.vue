<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Ellipsis, Plus } from '@lucide/vue';
import {
    SolarArchive as Archive,
    SolarCloud as Cloud,
    SolarPencil as Pencil,
    SolarPlay as Play,
    SolarRotateCcw as RotateCcw,
    SolarRotateCw as RotateCw,
    SolarServer as Server,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import {
    siBackblaze,
    siCloudflare,
    siDigitalocean,
    siHetzner,
    siMinio,
    siWasabi,
} from 'simple-icons';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { bytes } from '@/components/machines/serviceFormat';
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
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import BackupDestinationDialog from '@/components/workspace/BackupDestinationDialog.vue';
import type { BackupDestination } from '@/components/workspace/BackupDestinationDialog.vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { usePathTab } from '@/composables/usePathTab';
import { appButtonClasses } from '@/lib/appButton';
import { useWhenVisible } from '@/composables/useWhenVisible';
import type { Workspace, WorkspaceAccess } from '@/types';

type MachineRow = {
    id: number;
    name: string;
    ip: string;
    connection: string;
    destinationId: number | null;
    schedule: 'off' | 'daily' | 'weekly';
    lastBackup: string | null;
    services: number;
    unavailable: string | null;
};

type BackupRow = {
    id: number;
    machine: { id: number; name: string };
    destination: string | null;
    service: string;
    serviceName: string;
    trigger: 'manual' | 'scheduled' | 'workflow' | 'pre-restore' | 'pre-deploy';
    state: 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped';
    bytes: number | null;
    location: string | null;
    error: string | null;
    /** Its latest restore, if it has been restored. */
    restore: {
        state: 'queued' | 'running' | 'succeeded' | 'failed';
        error: string | null;
        at: string | null;
    } | null;
    /** Why it can't be restored now; null when it can. */
    cannotRestore: string | null;
    createdAt: string;
    finishedAt: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    machines: MachineRow[];
    destinations: BackupDestination[];
    history: BackupRow[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const tab = usePathTab(
    '/backups',
    ['machines', 'destinations', 'history'] as const,
    props.destinations.length ? 'machines' : 'destinations',
);

const tabs = computed(() => [
    {
        value: 'machines' as const,
        label: trans('Machines'),
        count: props.machines.length,
    },
    {
        value: 'destinations' as const,
        label: trans('Destinations'),
        count: props.destinations.length,
    },
    {
        value: 'history' as const,
        label: trans('History'),
        count: props.history.length,
    },
]);

const covered = computed(
    () =>
        props.machines.filter((m) => m.destinationId && m.schedule !== 'off')
            .length,
);

/* Machines: where each one's backups go and how often */

const saving = ref<number | null>(null);
const rowError = ref<Record<number, string>>({});

function saveMachine(
    machine: MachineRow,
    change: Partial<Pick<MachineRow, 'destinationId' | 'schedule'>>,
): void {
    const destinationId =
        change.destinationId !== undefined
            ? change.destinationId
            : machine.destinationId;
    // Picking a destination for a machine with backups off turns on daily.
    const schedule =
        change.schedule ??
        (destinationId && machine.schedule === 'off' && !machine.destinationId
            ? 'daily'
            : destinationId
              ? machine.schedule
              : 'off');

    router.put(
        `/machines/${machine.id}/backups`,
        { destination_id: destinationId, schedule },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['machines', 'destinations'],
            onStart: () => (saving.value = machine.id),
            onFinish: () => (saving.value = null),
            onSuccess: () => delete rowError.value[machine.id],
            onError: (bag) =>
                (rowError.value[machine.id] = Object.values(bag)[0] ?? ''),
        },
    );
}

const running = ref<number | null>(null);

async function backUpNow(machine: MachineRow): Promise<void> {
    running.value = machine.id;
    delete rowError.value[machine.id];

    try {
        const response = await fetch(`/machines/${machine.id}/backups/run`, {
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
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            rowError.value[machine.id] =
                body.error ?? trans('The backup could not start.');

            return;
        }

        tab.value = 'history';
        refresh();
    } finally {
        running.value = null;
    }
}

function destinationName(id: number | null): string {
    return props.destinations.find((d) => d.id === id)?.name ?? '';
}

/** Destinations a machine can send to: any but one kept on itself. */
function choicesFor(machine: MachineRow): BackupDestination[] {
    return props.destinations.filter((d) => d.machineId !== machine.id);
}

/** Destinations on the machine itself: listed, but can't be picked. */
function selfDestinations(machine: MachineRow): BackupDestination[] {
    return props.destinations.filter((d) => d.machineId === machine.id);
}

const dialogOpen = ref(false);
const editing = ref<BackupDestination | null>(null);
const initialType = ref<'s3' | 'machine'>('s3');

function addDestination(type: 's3' | 'machine' = 's3'): void {
    editing.value = null;
    initialType.value = type;
    dialogOpen.value = true;
}

function editDestination(destination: BackupDestination): void {
    editing.value = destination;
    dialogOpen.value = true;
}

const removing = ref<BackupDestination | null>(null);
const confirmingRemove = ref(false);
const removeProcessing = ref(false);

function askRemove(destination: BackupDestination): void {
    removing.value = destination;
    confirmingRemove.value = true;
}

function removeDestination(): void {
    if (!removing.value) return;

    router.delete(`/backup-destinations/${removing.value.id}`, {
        preserveScroll: true,
        onStart: () => (removeProcessing.value = true),
        onFinish: () => (removeProcessing.value = false),
        onSuccess: () => (confirmingRemove.value = false),
    });
}

function retry(destination: BackupDestination): void {
    router.post(
        `/backup-destinations/${destination.id}/retry`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

const dialogMachines = computed(() =>
    props.machines.map((m) => ({
        id: m.id,
        name: m.name,
        online: ['online', 'degraded'].includes(m.connection),
    })),
);

const destinationStatus: Record<
    BackupDestination['status'],
    { dot: string; label: string }
> = {
    ready: { dot: 'bg-(--ds-green)', label: trans('Ready') },
    setting_up: {
        dot: 'bg-(--ds-amber) animate-pulse',
        label: trans('Setting up'),
    },
    failed: { dot: 'bg-(--ds-red)', label: trans('Failed') },
};

const backupStatus: Record<BackupRow['state'], { dot: string; label: string }> =
    {
        queued: { dot: 'bg-(--ds-text-faint)', label: trans('Queued') },
        running: {
            dot: 'bg-(--ds-blue) animate-pulse',
            label: trans('Running'),
        },
        succeeded: { dot: 'bg-(--ds-green)', label: trans('Done') },
        failed: { dot: 'bg-(--ds-red)', label: trans('Failed') },
        skipped: {
            dot: 'bg-(--ds-text-faint)',
            label: trans('Nothing to keep'),
        },
    };

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function ago(iso: string | null): string {
    if (!iso) return trans('Never');

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return trans('just now');
}

const triggers: Record<BackupRow['trigger'], string> = {
    manual: trans('By hand'),
    scheduled: trans('Scheduled'),
    workflow: trans('By a workflow'),
    'pre-restore': trans('Taken before a restore'),
    'pre-deploy': trans('Taken before a deploy'),
};

/** Under the service: where it is and how its latest restore went. */
function restoreNote(backup: BackupRow): string | null {
    const restore = backup.restore;
    if (!restore) return null;
    if (restore.state === 'failed') return trans('Restore failed');
    if (restore.state === 'succeeded')
        return trans('Restored :ago', { ago: String(ago(restore.at)) });

    return trans('Restoring…');
}

const restoring = ref<BackupRow | null>(null);
const restoreOpen = ref(false);
const restoreConfirm = ref('');
const restoreBusy = ref(false);
const restoreError = ref<string | null>(null);

function askRestore(backup: BackupRow): void {
    restoring.value = backup;
    restoreConfirm.value = '';
    restoreError.value = null;
    restoreOpen.value = true;
}

async function restore(): Promise<void> {
    const backup = restoring.value;
    if (!backup || restoreConfirm.value !== backup.serviceName) return;
    restoreBusy.value = true;
    restoreError.value = null;

    try {
        const response = await fetch(`/backups/${backup.id}/restore`, {
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
            body: JSON.stringify({ confirm: restoreConfirm.value }),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            restoreError.value =
                body.errors?.confirm?.[0] ??
                body.error ??
                trans('The restore could not start.');

            return;
        }

        restoreOpen.value = false;
        refresh();
    } finally {
        restoreBusy.value = false;
    }
}

/* Keep it fresh: quickly while something is moving. */

const busy = computed(
    () =>
        props.history.some(
            (b) =>
                ['queued', 'running'].includes(b.state) ||
                ['queued', 'running'].includes(b.restore?.state ?? ''),
        ) || props.destinations.some((d) => d.status === 'setting_up'),
);

let timer: ReturnType<typeof setTimeout> | undefined;
// A hidden tab doesn't poll; it picks up again once it's shown.
const whenVisible = useWhenVisible();

function refresh(): void {
    router.reload({ only: ['machines', 'destinations', 'history'] });
}

function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({
                    only: ['machines', 'destinations', 'history'],
                    onFinish: poll,
                }),
            ),
        busy.value ? 3000 : 15000,
    );
}

onMounted(poll);
onBeforeUnmount(() => clearTimeout(timer));

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const selectClass =
    'ds-select ds-raised-button h-8 w-full min-w-0 cursor-pointer appearance-none pr-8! text-[13px] disabled:cursor-not-allowed disabled:opacity-60';
const machineColumns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1.5fr)_8.5rem_5.5rem_7.5rem_8.5rem]';
const destinationColumns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,2fr)_8rem_minmax(0,1.4fr)_7rem_2rem]';
const historyColumns =
    'grid grid-cols-[minmax(0,1fr)_auto_2rem] items-center gap-x-4 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_8rem_6rem_minmax(0,1.8fr)_7rem_2rem]';

/* Where backups can go, for the empty state: each with a line and, for
   S3, the logos of providers people use with it. */
const destinationKinds = computed(() => [
    {
        kind: 's3' as const,
        icon: Cloud,
        title: trans('S3 bucket'),
        description: trans(
            'AWS, Cloudflare R2, Backblaze, Hetzner or any S3-compatible storage',
        ),
        logos: [
            siCloudflare,
            siBackblaze,
            siHetzner,
            siDigitalocean,
            siWasabi,
            siMinio,
        ],
    },
    {
        kind: 'machine' as const,
        icon: Server,
        title: trans('Another machine'),
        description: trans("A VPS you've connected, over SFTP"),
        logos: [],
    },
]);
</script>

<template>
    <Head :title="$t('Backups')" />

    <WorkspacePage :title="$t('Backups')" toolbar>
        <template #description>
            {{
                ' ' +
                $t(
                    'Copies of your apps, sites and databases kept off the machine, in an S3 bucket or on another of your machines.',
                )
            }}
            <template v-if="machines.length">
                {{
                    $t(':covered of :length :value covered.', {
                        covered: String(covered ?? ''),
                        length: String(machines.length ?? ''),
                        value:
                            machines.length === 1
                                ? $t('machine is')
                                : $t('machines are'),
                    }) + ' '
                }}
            </template>
        </template>

        <template v-if="machines.length" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Backups')"
                >
                    <button
                        v-for="option in tabs"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="tab === option.value"
                        @click="tab = option.value"
                    >
                        {{ option.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                tab === option.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                        >
                            {{ option.count }}
                        </span>
                    </button>
                </div>
            </div>
        </template>

        <template v-if="machines.length && canManage" #actions>
            <button
                type="button"
                :class="appButtonClasses('primary', 'sm')"
                @click="addDestination()"
            >
                <Plus class="mi-plus size-3.5" :stroke-width="2" />
                {{ $t('Add destination') + ' ' }}
            </button>
        </template>

        <div
            v-if="machines.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <Archive class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ ' ' + $t('Nothing to back up yet') + ' ' }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Connect a machine first. Its apps, sites and databases can then be backed up every day.',
                    ) +
                    ' '
                }}
            </p>
            <ConnectMachineButton class="mt-6" />
        </div>

        <!-- No destinations yet: pick a kind -->
        <section
            v-else-if="destinations.length === 0 && tab !== 'history'"
            class="flex flex-col gap-3"
            aria-labelledby="destination-heading"
        >
            <div class="flex flex-col gap-0.5">
                <h2
                    id="destination-heading"
                    class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                >
                    {{ $t('Where should backups go?') }}
                </h2>
                <p class="text-[13px]/5 text-pretty text-(--ds-text-muted)">
                    {{
                        $t(
                            "Somewhere off the machine, so a dead disk or a bad deploy doesn't take them with it. Then pick a schedule for each machine.",
                        )
                    }}
                </p>
            </div>
            <ul class="ds-tile ds-tile-rows flex flex-col">
                <li
                    v-for="option in destinationKinds"
                    :key="option.kind"
                    class="ds-tile-row"
                >
                    <button
                        type="button"
                        class="group flex min-h-16 w-full items-center gap-4 px-4 py-3 text-left transition-colors enabled:cursor-pointer enabled:hover:bg-(--ds-row-hover) disabled:opacity-60"
                        :class="focusRing"
                        :disabled="!canManage"
                        @click="addDestination(option.kind)"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-[9px] border border-(--ds-border) bg-(--ds-subtle) text-(--ds-text-muted)"
                            aria-hidden="true"
                        >
                            <component
                                :is="option.icon"
                                class="size-4"
                                :stroke-width="1.75"
                            />
                        </span>
                        <span class="flex min-w-0 grow flex-col">
                            <span class="text-[14px]/5 font-medium">{{
                                option.title
                            }}</span>
                            <span
                                class="text-[13px]/5 text-(--ds-text-muted)"
                                >{{ option.description }}</span
                            >
                        </span>
                        <span
                            v-if="option.logos.length"
                            class="hidden items-center gap-2.5 sm:flex"
                            aria-hidden="true"
                        >
                            <svg
                                v-for="logo in option.logos"
                                :key="logo.title"
                                viewBox="0 0 24 24"
                                class="size-4 text-(--ds-text-faint) transition-colors duration-200 group-hover:text-(--brand)"
                                :style="{ '--brand': `#${logo.hex}` }"
                                fill="currentColor"
                            >
                                <title>{{ logo.title }}</title>
                                <path :d="logo.path" />
                            </svg>
                        </span>
                        <ArrowRight
                            v-if="canManage"
                            class="mi-arrow size-3.5 shrink-0 text-(--ds-text-faint) transition-colors group-hover:text-(--ds-text)"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                    </button>
                </li>
            </ul>
            <p v-if="!canManage" class="text-[13px]/5 text-(--ds-text-muted)">
                {{ $t('Ask someone who manages machines to add one.') }}
            </p>
        </section>

        <div
            v-else-if="tab === 'machines'"
            class="ds-tile overflow-x-auto"
            role="table"
            :aria-label="$t('Machines')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="machineColumns"
                role="row"
            >
                <span role="columnheader">{{ $t('Machine') }}</span>
                <span role="columnheader">{{ $t('Destination') }}</span>
                <span role="columnheader">{{ $t('Schedule') }}</span>
                <span role="columnheader">{{ $t('Services') }}</span>
                <span role="columnheader">{{ $t('Last backup') }}</span>
                <span role="columnheader"
                    ><span class="sr-only">{{ $t('Actions') }}</span></span
                >
            </div>
            <div
                v-for="machine in machines"
                :key="machine.id"
                class="border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                role="rowgroup"
            >
                <div :class="machineColumns" role="row">
                    <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                        <a
                            :href="`/machines/${machine.id}`"
                            class="truncate font-medium hover:underline hover:underline-offset-4"
                            >{{ machine.name }}</a
                        >
                        <span
                            class="truncate font-ui-mono text-[11px] text-(--ds-text-muted)"
                            >{{ machine.ip }}</span
                        >
                    </span>

                    <span class="hidden md:block" role="cell">
                        <select
                            :class="selectClass"
                            :value="machine.destinationId ?? ''"
                            :disabled="!canManage || saving === machine.id"
                            :aria-label="
                                $t('Where :name\'s backups go', {
                                    name: String(machine.name),
                                })
                            "
                            @change="
                                saveMachine(machine, {
                                    destinationId:
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ) || null,
                                })
                            "
                        >
                            <option value="">{{ $t('Not backed up') }}</option>
                            <option
                                v-for="destination in choicesFor(machine)"
                                :key="destination.id"
                                :value="destination.id"
                            >
                                {{ destination.name }}
                            </option>
                            <option
                                v-for="destination in selfDestinations(machine)"
                                :key="destination.id"
                                :value="destination.id"
                                disabled
                            >
                                {{
                                    $t(':name (on this machine)', {
                                        name: destination.name,
                                    }) + ' '
                                }}
                            </option>
                        </select>
                    </span>

                    <span class="hidden md:block" role="cell">
                        <select
                            :class="selectClass"
                            :value="machine.schedule"
                            :disabled="
                                !canManage ||
                                !machine.destinationId ||
                                saving === machine.id
                            "
                            :aria-label="
                                $t('How often :name is backed up', {
                                    name: String(machine.name),
                                })
                            "
                            @change="
                                saveMachine(machine, {
                                    schedule: (
                                        $event.target as HTMLSelectElement
                                    ).value as MachineRow['schedule'],
                                })
                            "
                        >
                            <option value="off">{{ $t('Manual only') }}</option>
                            <option value="daily">{{ $t('Daily') }}</option>
                            <option value="weekly">{{ $t('Weekly') }}</option>
                        </select>
                    </span>

                    <span
                        class="hidden tabular-nums md:block"
                        :class="{ 'text-(--ds-text-faint)': !machine.services }"
                        role="cell"
                        :title="
                            machine.services
                                ? undefined
                                : $t(
                                      'Only services ServerOS manages are backed up. Deploy or adopt one first.',
                                  )
                        "
                    >
                        {{ machine.services }}
                    </span>

                    <span
                        class="hidden items-center gap-1.75 md:flex"
                        role="cell"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                machine.lastBackup
                                    ? 'bg-(--ds-green)'
                                    : 'bg-(--ds-red)'
                            "
                        />
                        {{ ago(machine.lastBackup) }}
                    </span>

                    <span class="flex justify-end" role="cell">
                        <AppButton
                            v-if="canManage"
                            size="sm"
                            :disabled="
                                !!machine.unavailable || running === machine.id
                            "
                            :title="machine.unavailable ?? undefined"
                            @click="backUpNow(machine)"
                        >
                            <Play class="size-3" :stroke-width="2" />
                            {{
                                running === machine.id
                                    ? $t('Starting…')
                                    : $t('Back up now')
                            }}
                        </AppButton>
                    </span>
                </div>
                <p
                    v-if="rowError[machine.id]"
                    role="alert"
                    class="mt-1.5 text-xs/4 text-(--ds-red)"
                >
                    {{ rowError[machine.id] }}
                </p>
                <p
                    v-else-if="
                        machine.destinationId &&
                        machine.unavailable &&
                        !machine.services
                    "
                    class="mt-1.5 text-xs/4 text-(--ds-text-muted)"
                >
                    {{ machine.unavailable }}
                </p>
                <p
                    v-else-if="
                        !machine.destinationId &&
                        destinations.length &&
                        !choicesFor(machine).length
                    "
                    class="mt-1.5 text-xs/4 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'A machine can’t keep its own backups, and :value.',
                            {
                                value:
                                    destinations.length === 1
                                        ? $t(':value is on it', {
                                              value: String(
                                                  destinations[0].name,
                                              ),
                                          })
                                        : $t('every destination is on it'),
                            },
                        )
                    }}
                    <button
                        v-if="canManage"
                        type="button"
                        class="cursor-pointer rounded font-medium text-(--ds-text-secondary) underline-offset-4 hover:text-(--ds-text) hover:underline"
                        :class="focusRing"
                        @click="addDestination('s3')"
                    >
                        {{
                            ' ' +
                            $t('Add an S3 bucket or another machine') +
                            ' '
                        }}
                    </button>
                </p>
            </div>
        </div>

        <div
            v-else-if="tab === 'destinations'"
            class="ds-tile overflow-x-auto"
            role="table"
            :aria-label="$t('Destinations')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="destinationColumns"
                role="row"
            >
                <span role="columnheader">{{ $t('Destination') }}</span>
                <span role="columnheader">{{ $t('Kind') }}</span>
                <span role="columnheader">{{ $t('Status') }}</span>
                <span role="columnheader">{{ $t('Used by') }}</span>
                <span role="columnheader"
                    ><span class="sr-only">{{ $t('Actions') }}</span></span
                >
            </div>
            <div
                v-for="destination in destinations"
                :key="destination.id"
                class="border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                :class="destinationColumns"
                role="row"
            >
                <span class="flex min-w-0 items-center gap-3" role="cell">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-(--ds-border-strong) bg-(--ds-surface) text-(--ds-text-muted)"
                        aria-hidden="true"
                    >
                        <component
                            :is="destination.type === 's3' ? Cloud : Server"
                            class="size-4"
                            :stroke-width="1.75"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate font-medium">{{
                            destination.name
                        }}</span>
                        <span
                            class="truncate font-ui-mono text-[11px] text-(--ds-text-muted)"
                            >{{ destination.where }}</span
                        >
                    </span>
                </span>
                <span
                    class="hidden text-(--ds-text-secondary) md:block"
                    role="cell"
                >
                    {{
                        destination.type === 's3' ? 'S3 bucket' : $t('Machine')
                    }}
                </span>
                <span
                    class="hidden min-w-0 flex-col gap-0.5 md:flex"
                    role="cell"
                >
                    <span class="flex items-center gap-1.75">
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="destinationStatus[destination.status].dot"
                        />
                        {{ destinationStatus[destination.status].label }}
                        <button
                            v-if="destination.status === 'failed' && canManage"
                            type="button"
                            class="ml-1 inline-flex cursor-pointer items-center gap-1 font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                            @click="retry(destination)"
                        >
                            <RotateCw class="size-3" :stroke-width="2" />
                            {{ $t('Retry') + ' ' }}
                        </button>
                    </span>
                    <span
                        v-if="destination.statusMessage"
                        class="truncate text-xs text-(--ds-red)"
                        :title="destination.statusMessage"
                        >{{ destination.statusMessage }}</span
                    >
                </span>
                <span
                    class="hidden text-(--ds-text-secondary) tabular-nums md:block"
                    role="cell"
                >
                    {{ destination.machines }}
                    {{
                        destination.machines === 1
                            ? $t('machine')
                            : $t('machines')
                    }}
                </span>
                <span class="flex justify-end" role="cell">
                    <DropdownMenu v-if="canManage">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                                :aria-label="
                                    $t('Actions for :name', {
                                        name: String(destination.name),
                                    })
                                "
                            >
                                <Ellipsis class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-40 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem
                                @select="editDestination(destination)"
                            >
                                <Pencil class="size-4" :stroke-width="1.75" />
                                {{ $t('Edit') + ' ' }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                @select="askRemove(destination)"
                            >
                                <Trash2
                                    class="size-4 text-current"
                                    :stroke-width="1.75"
                                />
                                {{ $t('Remove') + ' ' }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </span>
            </div>
        </div>

        <div
            v-else
            class="ds-tile overflow-x-auto"
            role="table"
            :aria-label="$t('Backup history')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="historyColumns"
                role="row"
            >
                <span role="columnheader">{{ $t('Service') }}</span>
                <span role="columnheader">{{ $t('Machine') }}</span>
                <span role="columnheader">{{ $t('Status') }}</span>
                <span role="columnheader">{{ $t('Size') }}</span>
                <span role="columnheader">{{ $t('Where') }}</span>
                <span role="columnheader">{{ $t('When') }}</span>
                <span role="columnheader"
                    ><span class="sr-only">{{ $t('Actions') }}</span></span
                >
            </div>
            <div
                v-for="backup in history"
                :key="backup.id"
                class="border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                :class="historyColumns"
                role="row"
            >
                <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                    <a
                        :href="`/machines/${backup.machine.id}/services/${backup.service}`"
                        class="truncate font-medium hover:underline hover:underline-offset-4"
                        >{{ backup.serviceName }}</a
                    >
                    <span class="truncate text-xs text-(--ds-text-muted)">
                        {{ triggers[backup.trigger] ?? $t('By hand') }}
                        <template v-if="backup.destination">
                            {{
                                ' ' +
                                $t('· to :destination', {
                                    destination: backup.destination,
                                })
                            }}</template
                        >
                        <template v-if="restoreNote(backup)">
                            ·
                            <span
                                :class="{
                                    'text-(--ds-red)':
                                        backup.restore?.state === 'failed',
                                    'text-(--ds-blue)': [
                                        'queued',
                                        'running',
                                    ].includes(backup.restore?.state ?? ''),
                                }"
                                :title="backup.restore?.error ?? undefined"
                                >{{ restoreNote(backup) }}</span
                            ></template
                        >
                    </span>
                </span>
                <span
                    class="hidden truncate text-(--ds-text-secondary) md:block"
                    role="cell"
                    >{{ backup.machine.name }}</span
                >
                <span class="hidden items-center gap-1.75 md:flex" role="cell">
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="backupStatus[backup.state].dot"
                    />
                    {{ backupStatus[backup.state].label }}
                </span>
                <span class="hidden tabular-nums md:block" role="cell">
                    {{ backup.bytes === null ? '—' : bytes(backup.bytes) }}
                </span>
                <span
                    class="hidden truncate md:block"
                    :class="
                        backup.error
                            ? 'text-xs text-(--ds-red)'
                            : 'font-ui-mono text-xs text-(--ds-text-secondary)'
                    "
                    :title="backup.error ?? backup.location ?? undefined"
                    role="cell"
                >
                    {{
                        backup.error ??
                        backup.location ??
                        (backup.trigger === 'pre-restore'
                            ? $t('Kept on :name', {
                                  name: String(backup.machine.name),
                              })
                            : '—')
                    }}
                </span>
                <span
                    class="text-right text-(--ds-text-muted) md:text-left"
                    role="cell"
                    :title="new Date(backup.createdAt).toLocaleString()"
                >
                    {{ ago(backup.createdAt) }}
                </span>
                <span class="flex justify-end" role="cell">
                    <DropdownMenu
                        v-if="canManage && backup.state === 'succeeded'"
                    >
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                                :aria-label="
                                    $t('Actions for the :serviceName backup', {
                                        serviceName: String(backup.serviceName),
                                    })
                                "
                            >
                                <Ellipsis class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal max-w-72 min-w-44 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem
                                :disabled="backup.cannotRestore !== null"
                                @select="askRestore(backup)"
                            >
                                <RotateCcw
                                    class="size-4"
                                    :stroke-width="1.75"
                                />
                                {{
                                    backup.trigger === 'pre-restore'
                                        ? $t('Undo the restore…')
                                        : $t('Restore…')
                                }}
                            </DropdownMenuItem>
                            <p
                                v-if="backup.cannotRestore"
                                class="px-2 pt-1 pb-1.5 text-xs/4 text-(--ds-text-muted)"
                            >
                                {{ backup.cannotRestore }}
                            </p>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </span>
            </div>
            <p
                v-if="history.length === 0"
                class="px-4 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'No backups have run yet. Pick a destination for a machine, then press Back up now or wait for its schedule.',
                    ) +
                    ' '
                }}
            </p>
        </div>

        <BackupDestinationDialog
            v-model:open="dialogOpen"
            :machines="dialogMachines"
            :editing="editing"
            :initial-type="initialType"
        />

        <Dialog v-model:open="restoreOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            restoring?.trigger === 'pre-restore'
                                ? $t('Undo the restore of :serviceName?', {
                                      serviceName: String(
                                          restoring?.serviceName,
                                      ),
                                  })
                                : $t('Restore :serviceName?', {
                                      serviceName: String(
                                          restoring?.serviceName,
                                      ),
                                  })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'Its data on :name goes back to how it was :value. Anything written since is replaced. ServerOS takes a snapshot first, so you can undo this from the history.',
                                {
                                    name: String(restoring?.machine.name ?? ''),
                                    value: restoring
                                        ? new Date(
                                              restoring.createdAt,
                                          ).toLocaleString()
                                        : '',
                                },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="restore">
                    <div class="grid gap-2">
                        <label for="restore-confirm" class="text-[13px]/5">
                            {{ ' ' + $t('Type') }}
                            <span class="font-ui-mono text-(--ds-text)">{{
                                restoring?.serviceName
                            }}</span>
                            {{ $t('to confirm.') + ' ' }}
                        </label>
                        <Input
                            id="restore-confirm"
                            v-model="restoreConfirm"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono"
                        />
                    </div>
                    <InputError :message="restoreError ?? undefined" />
                    <div class="flex justify-end gap-2">
                        <AppButton size="sm" @click="restoreOpen = false">
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <DangerButton
                            :label="$t('Restore')"
                            busy-label="Starting…"
                            :processing="restoreBusy"
                            :disabled="
                                !restoreBusy &&
                                restoreConfirm !== restoring?.serviceName
                            "
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="confirmingRemove">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Remove :name?', {
                            name: String(removing?.name ?? ''),
                        })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'Machines that send backups here stop until you pick another destination for them. Backups already there are left where they are.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex justify-end gap-2"
                    @submit.prevent="removeDestination"
                >
                    <AppButton size="sm" @click="confirmingRemove = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Remove destination')"
                        :processing="removeProcessing"
                    />
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
