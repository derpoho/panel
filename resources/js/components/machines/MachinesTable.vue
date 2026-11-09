<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import MachineGroupRow from '@/components/machines/MachineGroupRow.vue';
import MachineRow from '@/components/machines/MachineRow.vue';
import { columnLabels } from '@/components/machines/machines';
import type { Machine, MachineColumns } from '@/components/machines/machines';

type Props = {
    machines: Machine[];
    columns: MachineColumns;
    interactive?: boolean;
    /** On the workspace dashboard: rows highlight on hover and badges hold still. */
    dashboard?: boolean;
    deletable?: boolean;
    /** Machine groups; each machine's groupId says which it's in. */
    groups?: MachineGroup[];
    canManage?: boolean;
    /** Filters are on: hide groups with nothing matching, open the rest. */
    filtering?: boolean;
    /**
     * Group by one of their details instead of by machine group: each
     * machine's value, or null to leave it ungrouped. These groups can't
     * be edited.
     */
    groupBy?: ((machine: Machine) => string | null) | null;
};

export type MachineGroup = { id: number; name: string };

const props = withDefaults(defineProps<Props>(), {
    interactive: false,
    dashboard: false,
    deletable: false,
    groups: () => [],
    canManage: false,
    filtering: false,
    groupBy: null,
});

const selected = defineModel<string[]>('selected', { default: () => [] });
const emit = defineEmits<{
    view: [machine: Machine];
    details: [machine: Machine];
    delete: [machine: Machine];
    editGroup: [group: MachineGroup];
    deleteGroup: [group: MachineGroup];
}>();

/**
 * Groups by a detail: one per value in use, named by it. Ids are negative,
 * so they never meet a real group's.
 */
const fieldGroups = computed(() => {
    const groupBy = props.groupBy;
    if (!groupBy) return null;
    const byValue = new Map<string, Machine[]>();
    for (const machine of props.machines) {
        const value = groupBy(machine);
        if (value) byValue.set(value, [...(byValue.get(value) ?? []), machine]);
    }

    return [...byValue.entries()]
        .sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }))
        .map(([name, machines]) => ({
            group: { id: -(hash(name) + 1), name },
            machines,
        }));
});

function hash(text: string): number {
    let h = 0;
    for (const char of text) h = (h * 31 + char.charCodeAt(0)) % 2_000_000_000;

    return h;
}

const groupRows = computed(
    () =>
        fieldGroups.value ??
        props.groups
            .map((group) => ({
                group,
                machines: props.machines.filter((m) => m.groupId === group.id),
            }))
            .filter((row) => row.machines.length > 0 || !props.filtering),
);

const loose = computed(() => {
    if (props.groupBy) {
        const groupBy = props.groupBy;

        return props.machines.filter((m) => !groupBy(m));
    }
    const ids = new Set(props.groups.map((group) => group.id));

    return props.machines.filter((m) => !m.groupId || !ids.has(m.groupId));
});

/* Which groups are open, remembered in this browser. */

const storageKey = 'serveros:open-machine-groups';

function readOpen(): Set<number> {
    try {
        return new Set(JSON.parse(localStorage.getItem(storageKey) ?? '[]'));
    } catch {
        return new Set();
    }
}

const open = ref<Set<number>>(
    typeof window === 'undefined' ? new Set() : readOpen(),
);

watch(open, (value) => {
    try {
        localStorage.setItem(storageKey, JSON.stringify([...value]));
    } catch {
        // Blocked storage: groups just start closed.
    }
});

function isOpen(row: { group: MachineGroup; machines: Machine[] }): boolean {
    return (
        open.value.has(row.group.id) ||
        (props.filtering && row.machines.length > 0)
    );
}

function toggleGroup(id: number): void {
    const next = new Set(open.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    open.value = next;
}

function toggle(name: string): void {
    selected.value = selected.value.includes(name)
        ? selected.value.filter((selectedName) => selectedName !== name)
        : [...selected.value, name];
}
</script>

<template>
    <div
        class="shrink-0 overflow-x-auto"
        :class="
            dashboard
                ? 'ds-tile'
                : 'rounded-xl border border-[#2A2A28] bg-[#131312]'
        "
        role="table"
        :aria-label="$t('Machines')"
    >
        <div class="flex w-full min-w-max flex-col">
            <div
                class="flex h-9.5 items-center border-b px-4 font-ui text-xs/4 font-medium"
                :class="
                    dashboard
                        ? 'ds-tile-head border-(--ds-divider) text-(--ds-text-muted)'
                        : 'border-[#2A2A28] bg-[#151514] text-[#8F8E89]'
                "
                role="row"
            >
                <span
                    v-for="(label, key) in columnLabels"
                    :key="key"
                    :class="columns[key]"
                    role="columnheader"
                >
                    {{ label }}
                </span>
                <span :class="columns.actions" role="columnheader">
                    <span class="sr-only">{{ $t('Actions') }}</span>
                </span>
            </div>

            <template v-for="row in groupRows" :key="`group-${row.group.id}`">
                <MachineGroupRow
                    :name="row.group.name"
                    :machines="row.machines"
                    :columns="columns"
                    :expanded="isOpen(row)"
                    :can-manage="canManage && row.group.id > 0"
                    :panel-id="`machine-group-${row.group.id}`"
                    @toggle="toggleGroup(row.group.id)"
                    @edit="emit('editGroup', row.group)"
                    @delete="emit('deleteGroup', row.group)"
                />
                <div
                    v-if="isOpen(row)"
                    :id="`machine-group-${row.group.id}`"
                    class="border-b border-(--ds-divider) bg-(--ds-subtle)/40"
                    role="rowgroup"
                >
                    <MachineRow
                        v-for="machine in row.machines"
                        :key="machine.name"
                        :machine="machine"
                        :columns="columns"
                        :interactive="interactive"
                        :dashboard="dashboard"
                        :deletable="deletable"
                        :selected="selected.includes(machine.name)"
                        nested
                        @toggle="toggle(machine.name)"
                        @view="emit('view', machine)"
                        @details="emit('details', machine)"
                        @delete="emit('delete', machine)"
                    />
                    <p
                        v-if="row.machines.length === 0"
                        class="flex h-11 items-center pl-17 font-ui text-[13px]/4 text-(--ds-text-muted)"
                    >
                        {{ ' ' + $t('No machines in this group yet.') + ' ' }}
                    </p>
                </div>
            </template>

            <MachineRow
                v-for="machine in loose"
                :key="machine.name"
                :machine="machine"
                :columns="columns"
                :interactive="interactive"
                :dashboard="dashboard"
                :deletable="deletable"
                :selected="selected.includes(machine.name)"
                @toggle="toggle(machine.name)"
                @view="emit('view', machine)"
                @details="emit('details', machine)"
                @delete="emit('delete', machine)"
            />

            <div
                v-if="machines.length === 0 && groupRows.length === 0"
                class="flex h-26 items-center justify-center px-4 font-ui text-[13px]/4"
                :class="dashboard ? 'text-(--ds-text-muted)' : 'text-[#8F8E89]'"
                role="row"
            >
                <span role="cell">
                    <slot name="empty">{{ $t('No machines found.') }}</slot>
                </span>
            </div>
        </div>
    </div>
</template>
