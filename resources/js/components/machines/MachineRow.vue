<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import ProviderName from '@/components/machines/ProviderName.vue';
import { router } from '@inertiajs/vue3';
import { Ellipsis, ExternalLink } from '@lucide/vue';
import {
    SolarWrench as Wrench,
    SolarCopy as Copy,
    SolarScanSearch as ScanSearch,
    SolarSquareTerminal as SquareTerminal,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import { computed, ref, watch } from 'vue';
import { toast } from '@/lib/toast';
import CpuBar from '@/components/machines/CpuBar.vue';
import EnvironmentBadge from '@/components/machines/EnvironmentBadge.vue';
import InstallingBadge from '@/components/machines/InstallingBadge.vue';
import SetupBadge from '@/components/machines/SetupBadge.vue';
import type { Machine, MachineColumns } from '@/components/machines/machines';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Props = {
    machine: Machine;
    columns: MachineColumns;
    interactive?: boolean;
    selected?: boolean;
    /** On the workspace dashboard: highlights on hover, badges hold still. */
    dashboard?: boolean;
    /** Offer Delete in the row menu. */
    deletable?: boolean;
    /** Inside an open machine group: indented under it. */
    nested?: boolean;
};

const emit = defineEmits<{
    toggle: [];
    view: [];
    details: [];
    delete: [];
}>();

const props = withDefaults(defineProps<Props>(), {
    interactive: false,
    selected: false,
    dashboard: false,
    deletable: false,
    nested: false,
});

async function copyIp(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.machine.ip);
        toast.success(trans('IP address copied'));
    } catch {
        toast.error(trans('Couldn’t copy the IP address'));
    }
}

const focusRing = computed(
    () =>
        `focus-visible:outline-2 focus-visible:outline-offset-2 ${props.dashboard ? 'focus-visible:outline-(--ds-ring)' : 'focus-visible:outline-[#60A5FA]'}`,
);

/**
 * Colours that differ between the dashboard (themed, light or dark) and the
 * always-dark landing page preview.
 */
const tone = computed(() =>
    props.dashboard
        ? {
              row: 'text-(--ds-text-strong) border-(--ds-divider)',
              selected: 'bg-(--ds-subtle)',
              checked: 'bg-(--ds-inverse)',
              checkmark: 'stroke-(--ds-inverse-text)',
              unchecked:
                  'border-(--ds-border-hover) hover:border-(--ds-border-focus)',
              box: 'border-(--ds-border-hover)',
              name: 'text-(--ds-text)',
              meta: 'text-(--ds-text-faint)',
              backup: 'bg-(--ds-green)',
              actions: 'text-(--ds-text-muted)',
              actionHover: 'hover:bg-(--ds-hover) hover:text-(--ds-text)',
          }
        : {
              row: 'text-[#D6D5D0] border-[#222221]',
              selected: 'bg-[#1A1A19]',
              checked: 'bg-[#EEEEEC]',
              checkmark: 'stroke-[#111110]',
              unchecked: 'border-[#3B3A37] hover:border-[#5A5955]',
              box: 'border-[#3B3A37]',
              name: 'text-[#EEEEEC]',
              meta: 'text-[#7C7B76]',
              backup: 'bg-[#4ADE80]',
              actions: 'text-[#8F8E89]',
              actionHover: 'hover:bg-[#222221] hover:text-[#EEEEEC]',
          },
);

/* Show the setup badge while a new server sets up, until it says Done. */
const settingUp = ref(!!props.machine.setup);
watch(
    () => props.machine.setup,
    (stage) => {
        if (stage) settingUp.value = true;
    },
);
</script>

<template>
    <div
        class="flex h-13 items-center border-b pr-4 font-ui text-[13px]/4 last:border-b-0"
        :class="[
            nested ? 'pl-10' : 'pl-4',
            tone.row,
            selected
                ? tone.selected
                : {
                      'cursor-pointer transition-colors':
                          interactive && !!machine.id,
                      'hover:bg-(--ds-row-hover)':
                          dashboard && interactive && !!machine.id,
                      'hover:bg-[#161615]':
                          !dashboard && interactive && !!machine.id,
                  },
        ]"
        role="row"
        :tabindex="interactive && machine.id ? 0 : undefined"
        :aria-selected="interactive ? selected : undefined"
        @click="interactive && machine.id && emit('view')"
        @keydown.enter.self="interactive && machine.id && emit('view')"
    >
        <div
            :class="columns.machine"
            class="flex items-center gap-2.5"
            role="cell"
        >
            <button
                v-if="interactive"
                type="button"
                role="checkbox"
                :aria-checked="selected"
                :aria-label="$t('Select :name', { name: String(machine.name) })"
                class="flex size-4 shrink-0 cursor-pointer items-center justify-center rounded-sm"
                :class="[
                    selected
                        ? tone.checked
                        : ['border-[1.5px]', tone.unchecked],
                    focusRing,
                ]"
                @click.stop="emit('toggle')"
            >
                <svg
                    v-if="selected"
                    width="10"
                    height="10"
                    viewBox="0 0 10 10"
                    aria-hidden="true"
                >
                    <path
                        pathLength="1"
                        d="M2 5.2L4.2 7.2L8 3"
                        fill="none"
                        :class="[tone.checkmark, { 'mi-draw': dashboard }]"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </button>
            <span
                v-else
                class="size-4 shrink-0 rounded-sm border-[1.5px]"
                :class="tone.box"
            />
            <img
                v-if="machine.iconUrl"
                :src="machine.iconUrl"
                alt=""
                class="size-7 shrink-0 rounded-md border border-(--ds-border) object-cover"
            />
            <div class="flex min-w-0 flex-col gap-0.5">
                <button
                    v-if="machine.uid"
                    type="button"
                    class="text-left text-[13.5px]/4 font-medium hover:underline"
                    :class="tone.name"
                    @click.stop="emit('view')"
                >
                    {{ machine.name }}
                    <Wrench
                        v-if="machine.maintenance"
                        class="ml-1 inline size-3 text-(--ds-amber)"
                        :stroke-width="2"
                        :aria-label="$t('In maintenance')"
                    />
                </button>
                <span
                    v-else
                    class="text-[13.5px]/4 font-medium"
                    :class="tone.name"
                    >{{ machine.name }}</span
                >
                <span class="font-ui-mono text-[11px]/4" :class="tone.meta">
                    {{
                        machine.connection === 'connecting'
                            ? $t('Connecting daemon…')
                            : machine.connection === 'offline'
                              ? $t('Daemon offline')
                              : machine.connection === 'degraded'
                                ? $t('Daemon delayed')
                                : machine.ip
                    }}
                </span>
            </div>
        </div>

        <div :class="columns.environment" class="flex items-center" role="cell">
            <SetupBadge
                v-if="settingUp"
                :stage="machine.setup ?? null"
                :findings="machine.setupFindings"
                @finished="settingUp = false"
            />
            <InstallingBadge
                v-else-if="machine.installing"
                :cycle="!dashboard"
                :themed="dashboard"
            />
            <EnvironmentBadge
                v-else
                :environment="machine.environment"
                :themed="dashboard"
            />
        </div>

        <div :class="columns.provider" role="cell">
            <ProviderName :name="machine.provider" />
        </div>
        <div :class="columns.services" role="cell">{{ machine.services }}</div>
        <div :class="columns.memory" role="cell">{{ machine.memory }}</div>

        <div :class="columns.cpu" class="flex items-center" role="cell">
            <CpuBar :cpu="machine.cpu" :themed="dashboard" />
        </div>

        <div
            :class="columns.lastBackup"
            class="flex items-center gap-1.75"
            role="cell"
        >
            <span class="size-1.5 shrink-0 rounded-full" :class="tone.backup" />
            {{ machine.lastBackup }}
        </div>

        <div
            class="flex justify-end"
            :class="[columns.actions, tone.actions]"
            role="cell"
        >
            <DropdownMenu v-if="interactive && dashboard">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md data-[state=open]:bg-(--ds-hover) data-[state=open]:text-(--ds-text)"
                        :class="[tone.actionHover, focusRing]"
                        :aria-label="
                            $t('Actions for :name', {
                                name: String(machine.name),
                            })
                        "
                        @click.stop
                        @keydown.enter.stop
                    >
                        <Ellipsis class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-48 font-ui"
                    align="end"
                    @click.stop
                >
                    <DropdownMenuItem
                        :disabled="!machine.id"
                        @select="emit('view')"
                    >
                        <ExternalLink class="size-4" :stroke-width="1.75" />
                        {{ $t('Open machine') + ' ' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="!machine.id"
                        @select="emit('details')"
                    >
                        <ScanSearch class="size-4" :stroke-width="1.75" />
                        {{ $t('Discovery details') + ' ' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="!machine.id"
                        @select="
                            router.visit(`/machines/${machine.id}?console`)
                        "
                    >
                        <SquareTerminal class="size-4" :stroke-width="1.75" />
                        {{ $t('Console') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem @select="copyIp">
                        <Copy class="mi-copy size-4" :stroke-width="1.75" />
                        {{ $t('Copy IP address') + ' ' }}
                    </DropdownMenuItem>
                    <template v-if="deletable && machine.id">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                            @select="emit('delete')"
                        >
                            <Trash2
                                class="size-4 text-current"
                                :stroke-width="1.75"
                            />
                            {{ $t('Delete machine') + ' ' }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
            <button
                v-else-if="interactive"
                type="button"
                class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md"
                :class="[tone.actionHover, focusRing]"
                :aria-label="
                    $t('Actions for :name', { name: String(machine.name) })
                "
                @click.stop="emit('view')"
            >
                <Ellipsis class="size-4" />
            </button>
            <Ellipsis v-else class="size-4" />
        </div>
    </div>
</template>
