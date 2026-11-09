<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Plus, Search, X } from '@lucide/vue';
import {
    SolarPulse as Activity,
    SolarServer as Server,
    SolarFolderPlus as FolderPlus,
    SolarEraser as Eraser,
    SolarTag as Tag,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import {
    environmentLabels,
    tableColumns,
} from '@/components/machines/machines';
import type { Environment, Machine } from '@/components/machines/machines';
import ClearDataDialog from '@/components/machines/ClearDataDialog.vue';
import MachinesTable from '@/components/machines/MachinesTable.vue';
import type { MachineGroup } from '@/components/machines/MachinesTable.vue';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import IconTip from '@/components/workspace/IconTip.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import InputError from '@/components/InputError.vue';
import GroupDialog from '@/components/workspace/GroupDialog.vue';
import type { GroupDialogItem } from '@/components/workspace/GroupDialog.vue';
import type { PolicyChoice } from '@/lib/machinePolicies';
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
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import { fieldLabel } from '@/lib/machinePolicies';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import { useWhenVisible } from '@/composables/useWhenVisible';
import type { Workspace, WorkspaceAccess } from '@/types';

type Props = {
    workspace: Workspace;
    machines: Machine[];
    machineGroups: (MachineGroup & { policyId?: number | null })[];
    /** What a group's machines can follow. */
    machinePolicies: PolicyChoice[];
    fleetStatus: { live: boolean };
};

type EnvironmentFilter = Environment | 'all';

type SortKey = 'cpu' | 'memory' | 'name';

const props = defineProps<Props>();

const machines = computed(() => props.machines);

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const deleting = ref<Machine | null>(null);
const confirmingDelete = ref(false);
const deleteProcessing = ref(false);

function askDelete(machine: Machine): void {
    deleting.value = machine;
    confirmingDelete.value = true;
}

function deleteMachine(): void {
    const machine = deleting.value;

    if (!machine?.id || deleteProcessing.value) {
        return;
    }

    router.delete(
        onWorkspace(
            machinesRoutes.destroy({
                workspace: props.workspace.slug,
                machine: machine.id,
            }),
        ).url,
        {
            preserveScroll: true,
            onStart: () => (deleteProcessing.value = true),
            onFinish: () => (deleteProcessing.value = false),
            onSuccess: () => {
                confirmingDelete.value = false;
                selected.value = selected.value.filter(
                    (name) => name !== machine.name,
                );
            },
        },
    );
}

const search = ref('');
const environment = ref<EnvironmentFilter>('all');
/*
 * Filters by their details: provider, region, tier and labels,
 * one value per field, all of them at once.
 */
const detailFilters = ref<Record<string, string>>({});
const sort = ref<SortKey>('cpu');
const selected = ref<string[]>([]);
const detailId = ref<number | null>(null);
const detailOpen = ref(false);
const detail = computed(
    () =>
        machines.value.find((machine) => machine.id === detailId.value) ?? null,
);
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const connectButton =
    useTemplateRef<InstanceType<typeof ConnectMachineButton>>('connectButton');
// The empty state's own button: the toolbar's only exists once there are
// machines, so "Bring it in as is" opens whichever is there.
const firstConnectButton =
    useTemplateRef<InstanceType<typeof ConnectMachineButton>>(
        'firstConnectButton',
    );

const tabs = computed(() => [
    {
        value: 'all' as const,
        label: trans('All'),
        count: machines.value.length,
    },
    ...(Object.keys(environmentLabels) as Environment[]).map((value) => ({
        value,
        label: environmentLabels[value],
        count: machines.value.filter((machine) => machine.environment === value)
            .length,
    })),
]);

function detailValue(machine: Machine, field: string): string | null {
    const value = field.startsWith('label:')
        ? machine.labels?.[field.slice(6)]
        : field === 'provider'
          ? machine.provider
          : field === 'region'
            ? machine.region
            : field === 'tier'
              ? machine.tier
              : null;

    return value ? value : null;
}

/** Every detail the machines use, with its values: the filter and group menus. */
const detailFields = computed(() => {
    const labelKeys = [
        ...new Set(
            machines.value.flatMap((machine) =>
                Object.keys(machine.labels ?? {}),
            ),
        ),
    ].sort((a, b) => a.localeCompare(b));

    return [
        'provider',
        'region',
        'tier',
        ...labelKeys.map((key) => `label:${key}`),
    ]
        .map((field) => ({
            field,
            values: [
                ...new Set(
                    machines.value
                        .map((machine) => detailValue(machine, field))
                        .filter((value): value is string => value !== null),
                ),
            ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true })),
        }))
        .filter((entry) => entry.values.length > 0);
});

function toggleFilter(field: string, value: string): void {
    const next = { ...detailFilters.value };
    if (next[field] === value) delete next[field];
    else next[field] = value;
    detailFilters.value = next;
}

const filterLabel = computed(() => {
    const active = Object.values(detailFilters.value);
    if (active.length === 0) return trans('All');
    if (active.length === 1)
        return active[0] === 'Other' ? trans('Other') : active[0];

    return trans(':count on', { count: String(active.length) });
});

/** Rows by machine group, or by one of their details. */
const groupBy = ref<string>('groups');
const groupByFn = computed(() =>
    groupBy.value === 'groups'
        ? null
        : (machine: Machine) => detailValue(machine, groupBy.value),
);
const groupByLabel = computed(() =>
    groupBy.value === 'groups' ? trans('Groups') : fieldLabel(groupBy.value),
);

const sortOptions: { value: SortKey; label: string }[] = [
    { value: 'cpu', label: trans('CPU usage') },
    { value: 'memory', label: trans('Memory') },
    { value: 'name', label: trans('Name') },
];

const sortLabel = computed(
    () => sortOptions.find((option) => option.value === sort.value)?.label,
);

const comparators: Record<SortKey, (a: Machine, b: Machine) => number> = {
    cpu: (a, b) => b.cpu - a.cpu,
    memory: (a, b) => parseFloat(b.memory) - parseFloat(a.memory),
    name: (a, b) => a.name.localeCompare(b.name),
};

const visibleMachines = computed(() => {
    const query = search.value.trim().toLowerCase();

    return machines.value
        .filter(
            (machine) =>
                environment.value === 'all' ||
                machine.environment === environment.value,
        )
        .filter((machine) =>
            Object.entries(detailFilters.value).every(
                ([field, value]) => detailValue(machine, field) === value,
            ),
        )
        .filter(
            (machine) =>
                query === '' ||
                [
                    machine.name,
                    machine.ip,
                    machine.provider,
                    machine.region ?? '',
                    machine.tier ?? '',
                    ...Object.values(machine.labels ?? {}),
                ].some((value) => value.toLowerCase().includes(query)),
        )
        .toSorted(comparators[sort.value]);
});

const filtering = computed(
    () =>
        search.value.trim() !== '' ||
        environment.value !== 'all' ||
        Object.keys(detailFilters.value).length > 0,
);

/* Editing the ticked machines' details at once */

const detailsOpen = ref(false);
const detailsSaving = ref(false);
const detailsErrors = ref<Record<string, string>>({});
const detailsForm = ref({
    provider: '',
    region: '',
    tier: '',
    labels: [] as { key: string; value: string }[],
    removeLabels: '',
});

function editDetails(): void {
    detailsForm.value = {
        provider: '',
        region: '',
        tier: '',
        labels: [{ key: '', value: '' }],
        removeLabels: '',
    };
    detailsErrors.value = {};
    detailsOpen.value = true;
}

function saveDetails(): void {
    const form = detailsForm.value;
    // Empty fields stay as each machine has them.
    const filled = (value: string) => (value.trim() === '' ? {} : null);
    const payload = {
        machines: clearTargets.value.map((machine) => machine.id ?? 0),
        labels: form.labels.filter(
            (label) => label.key.trim() !== '' && label.value.trim() !== '',
        ),
        remove_labels: form.removeLabels
            .split(',')
            .map((key) => key.trim())
            .filter((key) => key !== ''),
        ...(filled(form.provider) ?? { provider: form.provider.trim() }),
        ...(filled(form.region) ?? { region: form.region.trim() }),
        ...(filled(form.tier) ?? { tier: form.tier.trim() }),
    };
    router.post('/machines/metadata', payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (detailsSaving.value = true),
        onFinish: () => (detailsSaving.value = false),
        onError: (bag) => (detailsErrors.value = bag),
        onSuccess: () => {
            detailsOpen.value = false;
            selected.value = [];
        },
    });
}

/* Clearing collected data from the ticked machines */

const clearOpen = ref(false);
const clearTargets = computed(() =>
    machines.value
        .filter(
            (machine) => machine.id && selected.value.includes(machine.name),
        )
        .map((machine) => ({ id: machine.id!, name: machine.name })),
);

const groupDialogOpen = ref(false);
const editingGroup = ref<MachineGroup | null>(null);
const groupInitialName = ref('');
const groupInitialSelected = ref<number[]>([]);
const groupSaving = ref(false);
const groupErrors = ref<Record<string, string>>({});
/** The policy the group's machines follow; null for the default. */
const groupPolicy = ref<number | null>(null);

const groupItems = computed<GroupDialogItem<number>[]>(() => {
    const names = new Map(
        props.machineGroups.map((group) => [group.id, group.name]),
    );

    return machines.value
        .filter((machine) => machine.id)
        .map((machine) => ({
            id: machine.id!,
            label: machine.name,
            detail: `${machine.ip} · ${machine.provider}`,
            groupName:
                machine.groupId && machine.groupId !== editingGroup.value?.id
                    ? (names.get(machine.groupId) ?? null)
                    : null,
        }));
});

function newGroup(): void {
    editingGroup.value = null;
    groupInitialName.value = '';
    groupPolicy.value = null;
    // Start from whatever's ticked in the table.
    groupInitialSelected.value = machines.value
        .filter(
            (machine) => machine.id && selected.value.includes(machine.name),
        )
        .map((machine) => machine.id!);
    groupErrors.value = {};
    groupDialogOpen.value = true;
}

function editGroup(group: MachineGroup): void {
    editingGroup.value = group;
    groupInitialName.value = group.name;
    groupPolicy.value =
        props.machineGroups.find((g) => g.id === group.id)?.policyId ?? null;
    groupInitialSelected.value = machines.value
        .filter((machine) => machine.id && machine.groupId === group.id)
        .map((machine) => machine.id!);
    groupErrors.value = {};
    groupDialogOpen.value = true;
}

function saveGroup({ name, ids }: { name: string; ids: number[] }): void {
    const group = editingGroup.value;
    const payload = { name, machines: ids, policy: groupPolicy.value };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (groupSaving.value = true),
        onFinish: () => (groupSaving.value = false),
        onError: (bag: Record<string, string>) => (groupErrors.value = bag),
        onSuccess: () => {
            groupDialogOpen.value = false;
            selected.value = [];
        },
    };

    if (group) {
        router.put(`/machine-groups/${group.id}`, payload, options);
    } else {
        router.post('/machine-groups', payload, options);
    }
}

function deleteGroup(group: MachineGroup): void {
    router.delete(`/machine-groups/${group.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (groupDialogOpen.value = false),
    });
}

/** "/" jumps to search; ⌘K belongs to the command palette. */
function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchInput.value?.focus();
    }
}

/*
 * While a new server sets up, keep the list fresh so its badge moves. Not
 * while the tab is hidden: it picks up again once it's shown.
 */
let setupTimer: ReturnType<typeof setTimeout> | undefined;
const whenVisible = useWhenVisible();

function pollSetup(): void {
    clearTimeout(setupTimer);
    const busy = props.machines.some(
        (m) => m.setup || m.connection === 'connecting',
    );
    setupTimer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({ only: ['machines'], onFinish: pollSetup }),
            ),
        busy ? 3000 : 15000,
    );
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    pollSetup();

    // Emails link to /machines?connect=1: open the install flow with a fresh
    // command, and drop the flag so a refresh doesn't make another.
    const url = new URL(window.location.href);
    if (url.searchParams.get('connect') === '1') {
        url.searchParams.delete('connect');
        window.history.replaceState(window.history.state, '', url);
        (connectButton.value ?? firstConnectButton.value)?.connect();
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(setupTimer);
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

function formatBytes(bytes: number | undefined): string {
    if (!bytes || bytes < 0) return trans('Unknown');
    return `${(bytes / 1024 ** 3).toFixed(1)} GiB`;
}
</script>

<template>
    <Head :title="$t('Machines')" />

    <WorkspacePage :title="$t('Machines')" toolbar>
        <template #description>
            {{
                ' ' +
                $t(
                    'Every machine you run, from any provider, in one place. Already have one with things running?',
                )
            }}
            <button
                type="button"
                class="cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                :class="focusRing"
                @click="(connectButton ?? firstConnectButton)?.connect()"
            >
                {{ ' ' + $t('Bring it in as is') + ' ' }}
            </button>
        </template>

        <template v-if="machines.length > 0" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Filter by environment')"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="environment === tab.value"
                        @click="environment = tab.value"
                    >
                        {{ tab.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                environment === tab.value
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

        <template v-if="machines.length > 0" #actions>
            <label
                class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-48 xl:w-60 xl:min-w-32"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{ $t('Search machines') }}</span>
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    :placeholder="$t('Search machines')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                />
                <kbd
                    class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px font-ui text-[11px]/3.5 text-(--ds-text-muted)"
                    aria-hidden="true"
                >
                    /
                </kbd>
            </label>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('Filter')
                        }}</span>
                        <span class="max-w-32 truncate font-medium">{{
                            filterLabel
                        }}</span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-[60svh] min-w-48 overflow-y-auto font-ui"
                    align="end"
                >
                    <DropdownMenuItem
                        :disabled="Object.keys(detailFilters).length === 0"
                        @select="detailFilters = {}"
                    >
                        {{ $t('All machines') }}
                        <Check
                            v-if="Object.keys(detailFilters).length === 0"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <template v-for="entry in detailFields" :key="entry.field">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-[11.5px]/4 font-medium text-(--ds-text-muted)"
                        >
                            {{ fieldLabel(entry.field) }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="value in entry.values"
                            :key="value"
                            @select.prevent="toggleFilter(entry.field, value)"
                        >
                            {{ value === 'Other' ? $t('Other') : value }}
                            <Check
                                v-if="detailFilters[entry.field] === value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
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
                            $t('Group')
                        }}</span>
                        <span class="font-medium">{{ groupByLabel }}</span>
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
                    <DropdownMenuItem @select="groupBy = 'groups'">
                        {{ $t('Machine groups') }}
                        <Check
                            v-if="groupBy === 'groups'"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="entry in detailFields"
                        :key="entry.field"
                        @select="groupBy = entry.field"
                    >
                        {{ fieldLabel(entry.field) }}
                        <Check
                            v-if="groupBy === entry.field"
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
                            $t('Sort')
                        }}</span>
                        <span class="font-medium">{{ sortLabel }}</span>
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
                        v-for="option in sortOptions"
                        :key="option.value"
                        @select="sort = option.value"
                    >
                        {{ option.label }}
                        <Check
                            v-if="sort === option.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <IconTip
                v-if="canManage"
                :label="
                    selected.length
                        ? $t('Group :selectedCount selected', {
                              selectedCount: String(selected.length),
                          })
                        : $t('New group')
                "
                :hint="
                    selected.length
                        ? null
                        : $t('Put machines together, by client or project')
                "
            >
                <button
                    type="button"
                    class="ds-raised-button cursor-pointer text-[13px]!"
                    :class="[
                        focusRing,
                        selected.length
                            ? 'px-2.5!'
                            : 'w-8 justify-center px-0!',
                    ]"
                    @click="newGroup"
                >
                    <FolderPlus
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    <span
                        v-if="selected.length"
                        class="font-medium tabular-nums"
                        aria-hidden="true"
                        >{{ selected.length }}</span
                    >
                    <span class="sr-only">{{
                        selected.length
                            ? $t('Group :selectedCount selected', {
                                  selectedCount: String(selected.length),
                              })
                            : $t('New group')
                    }}</span>
                </button>
            </IconTip>
            <IconTip
                v-if="canManage && clearTargets.length"
                :label="
                    $t('Edit details of :selectedCount selected', {
                        selectedCount: String(clearTargets.length),
                    })
                "
                :hint="$t('Provider, region, tier and labels')"
            >
                <button
                    type="button"
                    class="ds-raised-button w-8 cursor-pointer justify-center px-0! text-[13px]!"
                    :class="focusRing"
                    @click="editDetails"
                >
                    <Tag
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    <span class="sr-only">{{
                        $t('Edit details of :selectedCount selected', {
                            selectedCount: String(clearTargets.length),
                        })
                    }}</span>
                </button>
            </IconTip>
            <IconTip
                v-if="canManage && clearTargets.length"
                :label="
                    $t('Clear collected data from :selectedCount selected…', {
                        selectedCount: String(clearTargets.length),
                    })
                "
                :hint="$t('Logs, usage history or discovered services')"
            >
                <button
                    type="button"
                    class="ds-raised-button w-8 cursor-pointer justify-center px-0! text-[13px]!"
                    :class="focusRing"
                    @click="clearOpen = true"
                >
                    <Eraser
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    <span class="sr-only">{{
                        $t(
                            'Clear collected data from :selectedCount selected…',
                            {
                                selectedCount: String(clearTargets.length),
                            },
                        )
                    }}</span>
                </button>
            </IconTip>
            <IconTip :label="$t('Status page for every machine')" align="end">
                <template #hint>
                    <span
                        class="size-1.5 rounded-full"
                        :class="
                            fleetStatus.live
                                ? 'bg-(--ds-green)'
                                : 'bg-(--ds-text-faint)'
                        "
                    />
                    {{ fleetStatus.live ? $t('Live') : $t('Not set up yet') }}
                </template>
                <Link
                    href="/settings/status-page"
                    class="ds-raised-button w-8 cursor-pointer justify-center px-0! text-[13px]!"
                    :class="focusRing"
                >
                    <span class="relative">
                        <Activity
                            class="size-3.5 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                        <span
                            v-if="fleetStatus.live"
                            class="absolute -top-0.5 -right-0.5 size-1.5 rounded-full bg-(--ds-green)"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="sr-only">{{ $t('Status page') }}</span>
                </Link>
            </IconTip>
            <ConnectMachineButton ref="connectButton" />
        </template>

        <div
            v-if="machines.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <Server class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ $t('No machines yet') }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Connect a VPS from any provider. Machines that already have things running are fine.',
                    ) +
                    ' '
                }}
            </p>
            <ConnectMachineButton ref="firstConnectButton" class="mt-6" />
        </div>

        <MachinesTable
            v-else
            v-model:selected="selected"
            :machines="visibleMachines"
            :columns="tableColumns"
            interactive
            dashboard
            :deletable="canManage"
            :groups="machineGroups"
            :group-by="groupByFn"
            :can-manage="canManage"
            :filtering="filtering"
            @delete="askDelete"
            @edit-group="editGroup"
            @delete-group="deleteGroup"
            @view="
                (machine) => {
                    if (machine.id) router.visit(`/machines/${machine.id}`);
                }
            "
            @details="
                (machine) => {
                    detailId = machine.id ?? null;
                    detailOpen = true;
                }
            "
        >
            <template #empty>{{
                $t('No machines match your filters.')
            }}</template>
        </MachinesTable>

        <GroupDialog
            v-model:open="groupDialogOpen"
            noun="machine"
            :items="groupItems"
            :editing="!!editingGroup"
            :initial-name="groupInitialName"
            :initial-selected="groupInitialSelected"
            :processing="groupSaving"
            :errors="groupErrors"
            @save="saveGroup"
            @delete="editingGroup && deleteGroup(editingGroup)"
        >
            <template #extra>
                <div class="flex flex-col gap-1.5">
                    <label
                        for="group-policy"
                        class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('Policy') }}
                    </label>
                    <select
                        id="group-policy"
                        :value="groupPolicy ?? ''"
                        class="ds-select ds-raised-button h-9 w-full cursor-pointer appearance-none pr-8! text-[13px]"
                        @change="
                            groupPolicy =
                                ($event.target as HTMLSelectElement).value ===
                                ''
                                    ? null
                                    : Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      )
                        "
                    >
                        <option value="">
                            {{
                                $t('The default, :name', {
                                    name:
                                        machinePolicies.find((p) => p.isDefault)
                                            ?.name ?? $t('Default'),
                                })
                            }}
                        </option>
                        <option
                            v-for="option in machinePolicies.filter(
                                (p) => !p.isDefault,
                            )"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.name }}
                        </option>
                    </select>
                    <span class="text-[12.5px]/4.5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'What its machines collect and send, unless one picked its own.',
                            )
                        }}
                    </span>
                    <InputError :message="groupErrors.policy" />
                </div>
            </template>
        </GroupDialog>

        <Dialog v-model:open="detailsOpen">
            <DialogContent
                class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-lg"
            >
                <form
                    class="flex min-w-0 flex-col"
                    @submit.prevent="saveDetails"
                >
                    <div class="flex flex-col gap-1 px-5 pt-5">
                        <DialogTitle class="text-base/6 font-semibold">
                            {{
                                $t('Details of :count machines', {
                                    count: String(clearTargets.length),
                                })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'What you fill in is set on every one of them. What you leave empty stays as each has it.',
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <div class="flex flex-col gap-4 p-5">
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Provider') }}</span
                                >
                                <Input
                                    v-model="detailsForm.provider"
                                    maxlength="60"
                                    :placeholder="$t('OVH')"
                                />
                            </label>
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Region') }}</span
                                >
                                <Input
                                    v-model="detailsForm.region"
                                    maxlength="60"
                                    :placeholder="$t('lon1')"
                                />
                            </label>
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Tier') }}</span
                                >
                                <Input
                                    v-model="detailsForm.tier"
                                    maxlength="60"
                                    :placeholder="$t('performance')"
                                />
                            </label>
                        </div>
                        <div class="flex flex-col gap-2">
                            <span
                                class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                                >{{ $t('Add labels') }}</span
                            >
                            <div
                                v-for="(label, index) in detailsForm.labels"
                                :key="index"
                                class="flex items-center gap-2"
                            >
                                <Input
                                    v-model="label.key"
                                    class="w-36 shrink-0"
                                    maxlength="40"
                                    :placeholder="$t('customer')"
                                    :aria-label="$t('Label name')"
                                />
                                <Input
                                    v-model="label.value"
                                    class="grow"
                                    maxlength="100"
                                    :placeholder="$t('acme')"
                                    :aria-label="$t('Label value')"
                                />
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    :aria-label="$t('Remove')"
                                    @click="detailsForm.labels.splice(index, 1)"
                                >
                                    <X class="size-3.5" />
                                </AppButton>
                            </div>
                            <div>
                                <AppButton
                                    size="sm"
                                    @click="
                                        detailsForm.labels.push({
                                            key: '',
                                            value: '',
                                        })
                                    "
                                >
                                    <Plus class="size-3.5" :stroke-width="2" />
                                    {{ $t('Another label') }}
                                </AppButton>
                            </div>
                        </div>
                        <label class="flex flex-col gap-1">
                            <span
                                class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                                >{{ $t('Take labels off') }}</span
                            >
                            <Input
                                v-model="detailsForm.removeLabels"
                                :placeholder="$t('customer, rack')"
                            />
                        </label>
                        <InputError
                            :message="Object.values(detailsErrors)[0]"
                        />
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                    >
                        <AppButton
                            size="sm"
                            variant="quiet"
                            @click="detailsOpen = false"
                        >
                            {{ $t('Cancel') }}
                        </AppButton>
                        <SubmitButton
                            :label="$t('Save')"
                            :busy-label="$t('Saving…')"
                            :processing="detailsSaving"
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <ClearDataDialog
            v-model:open="clearOpen"
            :machines="clearTargets"
            :kinds="[]"
            @done="selected = []"
        />

        <Dialog v-model:open="confirmingDelete">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Delete :name?', {
                            name: String(deleting?.name ?? ''),
                        })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'It disappears from ServerOS along with its deploy history. Nothing on the machine itself is touched, so you can connect it again later.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex justify-end gap-2"
                    @submit.prevent="deleteMachine"
                >
                    <AppButton size="sm" @click="confirmingDelete = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Delete machine')"
                        :processing="deleteProcessing"
                    />
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="detailOpen">
            <DialogContent
                v-if="detail"
                class="workspace-panel ds-form ds-modal max-h-[85vh] overflow-y-auto font-ui text-foreground sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle>{{ detail.name }}</DialogTitle>
                    <DialogDescription
                        >{{
                            $t('Daemon :connection', {
                                connection: String(detail.connection ?? ''),
                            })
                        }}<span v-if="detail.daemonVersion">
                            · {{ detail.daemonVersion }}</span
                        ><span v-if="detail.lastSeen">
                            {{
                                ' ' +
                                $t('· last seen :lastSeen', {
                                    lastSeen: detail.lastSeen,
                                })
                            }}</span
                        ></DialogDescription
                    >
                </DialogHeader>
                <section v-if="detail.facts" class="space-y-3 text-sm">
                    <h3 class="font-medium text-(--ds-text)">
                        {{ $t('VPS details') }}
                    </h3>
                    <dl
                        class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-(--ds-border-strong) p-3 sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Provider') }}
                            </dt>
                            <dd>
                                {{
                                    detail.facts.hosting?.provider ??
                                    $t('Unknown')
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Region') }}
                            </dt>
                            <dd>
                                {{
                                    detail.facts.hosting?.region ??
                                    $t('Unknown')
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ ' ' + $t('Zone / datacenter') + ' ' }}
                            </dt>
                            <dd>
                                {{
                                    detail.facts.hosting?.zone ?? $t('Unknown')
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('VPS plan') }}
                            </dt>
                            <dd>
                                {{
                                    detail.facts.hosting?.instance_type ??
                                    $t('Unknown')
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ ' ' + $t('Operating system') + ' ' }}
                            </dt>
                            <dd>
                                {{ detail.facts.os || $t('Unknown') }}
                                {{ detail.facts.os_version }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Kernel') }}
                            </dt>
                            <dd>{{ detail.facts.kernel || $t('Unknown') }}</dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Architecture') }}
                            </dt>
                            <dd>{{ detail.facts.arch || $t('Unknown') }}</dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('CPU') }}
                            </dt>
                            <dd>
                                {{
                                    $t(':valueValue vCPU · :value2', {
                                        valueValue: String(
                                            detail.facts.cpu_cores ??
                                                $t('Unknown'),
                                        ),
                                        value2:
                                            detail.facts.cpu_model ||
                                            $t('Unknown model'),
                                    })
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Memory') }}
                            </dt>
                            <dd>
                                {{ formatBytes(detail.facts.memory_bytes) }}
                            </dd>
                        </div>
                    </dl>
                    <div v-if="detail.facts.disks?.length">
                        <h4 class="mb-2 text-(--ds-text-muted)">
                            {{ $t('Disks') }}
                        </h4>
                        <div
                            v-for="disk in detail.facts.disks"
                            :key="`${disk.device}-${disk.mount}`"
                            class="flex justify-between gap-3 border-b border-(--ds-border-strong) py-1"
                        >
                            <span class="truncate"
                                >{{ disk.mount }} · {{ disk.device }} ·
                                {{ disk.fs_type }}</span
                            >
                            <span class="shrink-0">{{
                                formatBytes(disk.total_bytes)
                            }}</span>
                        </div>
                    </div>
                    <div v-if="detail.facts.interfaces?.length">
                        <h4 class="mb-2 text-(--ds-text-muted)">
                            {{ ' ' + $t('Network interfaces') + ' ' }}
                        </h4>
                        <div
                            v-for="network in detail.facts.interfaces"
                            :key="network.name"
                            class="border-b border-(--ds-border-strong) py-1"
                        >
                            {{ network.name }} ·
                            {{
                                network.addresses.join(', ') || $t('No address')
                            }}
                        </div>
                    </div>
                    <p
                        v-if="!detail.facts.hosting?.provider"
                        class="text-xs text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'This VPS did not expose provider metadata to the daemon. Region and datacenter cannot be verified from its timezone.',
                            ) +
                            ' '
                        }}
                    </p>
                </section>
                <div v-if="detail.inventory" class="space-y-5 text-sm">
                    <p
                        v-if="!detail.inventory.complete"
                        class="text-(--ds-amber)"
                    >
                        {{
                            ' ' +
                            $t('The last discovery scan was incomplete.') +
                            ' '
                        }}
                    </p>
                    <section>
                        <h3 class="mb-2 font-medium text-(--ds-text)">
                            {{
                                ' ' +
                                $t('Discovered services (:length)', {
                                    length: String(
                                        detail.inventory.services.length ?? '',
                                    ),
                                }) +
                                ' '
                            }}
                        </h3>
                        <div
                            v-if="detail.inventory.services.length"
                            class="divide-y divide-(--ds-border-strong) rounded-lg border border-(--ds-border-strong)"
                        >
                            <div
                                v-for="service in detail.inventory.services"
                                :key="service.key"
                                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                            >
                                <div class="flex items-center gap-2.5">
                                    <ServiceLogo
                                        :name="service.name"
                                        :kind="service.kind"
                                        :service-key="service.key"
                                        :icon-url="service.icon"
                                    />
                                    <div>
                                        <span class="text-(--ds-text)">{{
                                            service.label ??
                                            service.app ??
                                            service.name
                                        }}</span
                                        ><span
                                            class="ml-2 text-xs text-(--ds-text-muted)"
                                            >{{ service.kind }} ·
                                            {{ service.manager }} ·
                                            {{ service.status }}</span
                                        >
                                    </div>
                                </div>
                                <span
                                    class="font-ui-mono text-xs text-(--ds-text-secondary)"
                                    >{{
                                        service.ports.length
                                            ? service.ports
                                                  .map((port) => `:${port}`)
                                                  .join(', ')
                                            : $t('No port')
                                    }}</span
                                >
                            </div>
                        </div>
                        <p v-else class="text-(--ds-text-muted)">
                            {{ ' ' + $t('No named services found.') + ' ' }}
                        </p>
                    </section>
                    <section>
                        <h3 class="mb-2 font-medium text-(--ds-text)">
                            {{
                                ' ' +
                                $t('Listening ports (:length)', {
                                    length: String(
                                        detail.inventory.listeners.length ?? '',
                                    ),
                                }) +
                                ' '
                            }}
                        </h3>
                        <div
                            v-if="detail.inventory.listeners.length"
                            class="divide-y divide-(--ds-border-strong) rounded-lg border border-(--ds-border-strong)"
                        >
                            <div
                                v-for="(listener, index) in detail.inventory
                                    .listeners"
                                :key="`${listener.proto}-${listener.address}-${listener.port}-${index}`"
                                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                            >
                                <span
                                    class="font-ui-mono text-xs text-(--ds-text)"
                                    >{{ listener.address }}:{{
                                        listener.port
                                    }}/{{ listener.proto }}</span
                                >
                                <span
                                    class="max-w-full truncate text-xs text-(--ds-text-muted)"
                                    >{{ listener.exe ?? $t('Unknown process')
                                    }}<span v-if="listener.pid">
                                        {{
                                            ' ' +
                                            $t('· PID :pid', {
                                                pid: String(listener.pid ?? ''),
                                            })
                                        }}</span
                                    ></span
                                >
                            </div>
                        </div>
                        <p v-else class="text-(--ds-text-muted)">
                            {{ ' ' + $t('No listening ports found.') + ' ' }}
                        </p>
                    </section>
                    <p
                        v-for="warning in detail.inventory.warnings ?? []"
                        :key="warning"
                        class="text-xs text-(--ds-amber)"
                    >
                        {{ warning }}
                    </p>
                </div>
                <p v-else class="text-sm text-(--ds-text-muted)">
                    {{
                        $t(
                            "Waiting for the daemon's first read-only discovery scan.",
                        )
                    }}
                </p>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
