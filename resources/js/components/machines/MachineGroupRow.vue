<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronRight, Ellipsis } from '@lucide/vue';
import {
    SolarFolder as Folder,
    SolarFolderOpen as FolderOpen,
    SolarPencil as Pencil,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import { computed } from 'vue';
import CpuBar from '@/components/machines/CpuBar.vue';
import type { Machine, MachineColumns } from '@/components/machines/machines';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * A group of machines as one row: how many are online, their providers,
 * services, combined memory and average CPU. Clicking it opens the group
 * to show its machines underneath.
 */
const props = defineProps<{
    name: string;
    machines: Machine[];
    columns: MachineColumns;
    expanded: boolean;
    canManage: boolean;
    panelId: string;
}>();

const emit = defineEmits<{ toggle: []; edit: []; delete: [] }>();

const online = computed(
    () =>
        props.machines.filter((m) =>
            ['online', 'degraded', 'demo'].includes(m.connection ?? 'demo'),
        ).length,
);

const status = computed(() => {
    const total = props.machines.length;

    if (total === 0)
        return { dot: 'bg-(--ds-text-faint)', label: trans('Empty') };
    if (online.value === total)
        return { dot: 'bg-(--ds-green)', label: trans('All online') };
    if (online.value === 0)
        return { dot: 'bg-(--ds-red)', label: trans('All offline') };

    return {
        dot: 'bg-(--ds-amber)',
        label: `${online.value} of ${total} online`,
    };
});

const providers = computed(() =>
    [...new Set(props.machines.map((m) => m.provider))].join(', '),
);

const services = computed(() =>
    props.machines.reduce((sum, m) => sum + (m.services ?? 0), 0),
);

const memory = computed(() => {
    const mb = props.machines.reduce((sum, m) => sum + (m.memoryMb ?? 0), 0);

    return mb ? `${(mb / 1024).toFixed(1)} GB` : '—';
});

const backups = computed(() => {
    const never = props.machines.filter(
        (m) => m.lastBackup === trans('Never'),
    ).length;

    if (!props.machines.length) return '—';

    return never
        ? trans(':never never backed up', { never: String(never) })
        : trans('All backed up');
});

const cpu = computed(() =>
    props.machines.length
        ? Math.round(
              props.machines.reduce((sum, m) => sum + m.cpu, 0) /
                  props.machines.length,
          )
        : 0,
);
</script>

<template>
    <div
        class="flex h-13 cursor-pointer items-center border-b border-(--ds-divider) px-4 font-ui text-[13px]/4 text-(--ds-text-strong) transition-colors select-none hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="{ 'bg-(--ds-subtle)': expanded }"
        role="row"
        tabindex="0"
        :aria-expanded="expanded"
        :aria-controls="panelId"
        @click="emit('toggle')"
        @keydown.enter.self.prevent="emit('toggle')"
        @keydown.space.self.prevent="emit('toggle')"
    >
        <div
            :class="columns.machine"
            class="flex items-center gap-2.5"
            role="cell"
        >
            <ChevronRight
                class="size-4 shrink-0 text-(--ds-text-muted) transition-transform duration-200"
                :class="{ 'rotate-90': expanded }"
                aria-hidden="true"
            />
            <span
                class="flex size-7 shrink-0 items-center justify-center rounded-md border border-(--ds-border) bg-(--ds-card) text-(--ds-text-secondary)"
                aria-hidden="true"
            >
                <FolderOpen
                    v-if="expanded"
                    class="size-3.5"
                    :stroke-width="1.75"
                />
                <Folder v-else class="size-3.5" :stroke-width="1.75" />
            </span>
            <div class="flex min-w-0 flex-col gap-0.5">
                <span
                    class="truncate text-[13.5px]/4 font-medium text-(--ds-text)"
                    >{{ name }}</span
                >
                <span class="text-[11px]/4 text-(--ds-text-faint)">
                    {{ machines.length }}
                    {{ machines.length === 1 ? $t('machine') : $t('machines') }}
                </span>
            </div>
        </div>

        <div
            :class="columns.environment"
            class="flex items-center gap-1.75"
            role="cell"
        >
            <span class="size-1.5 shrink-0 rounded-full" :class="status.dot" />
            {{ status.label }}
        </div>

        <div :class="columns.provider" class="truncate pr-2" role="cell">
            {{ providers || '—' }}
        </div>
        <div :class="columns.services" role="cell">{{ services }}</div>
        <div :class="columns.memory" role="cell">{{ memory }}</div>

        <div :class="columns.cpu" class="flex items-center" role="cell">
            <CpuBar v-if="machines.length" :cpu="cpu" themed />
            <span v-else class="text-(--ds-text-faint)">—</span>
        </div>

        <div
            :class="columns.lastBackup"
            class="text-(--ds-text-faint)"
            role="cell"
        >
            {{ backups }}
        </div>

        <div
            class="flex justify-end text-(--ds-text-muted)"
            :class="columns.actions"
            role="cell"
        >
            <DropdownMenu v-if="canManage">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover) data-[state=open]:text-(--ds-text)"
                        :aria-label="
                            $t('Actions for :name', { name: String(name) })
                        "
                        @click.stop
                        @keydown.enter.stop
                    >
                        <Ellipsis class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="end"
                    @click.stop
                >
                    <DropdownMenuItem @select="emit('edit')">
                        <Pencil class="size-4" :stroke-width="1.75" />
                        {{ $t('Edit group') + ' ' }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                        @select="emit('delete')"
                    >
                        <Trash2
                            class="size-4 text-current"
                            :stroke-width="1.75"
                        />
                        {{ $t('Delete group') + ' ' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
