<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronRight, Ellipsis } from '@lucide/vue';
import {
    SolarFolder as Folder,
    SolarPencil as Pencil,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import {
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import CpuBar from '@/components/machines/CpuBar.vue';
import {
    bytes,
    serviceColumns,
    status,
    statusGroup,
    uptime,
} from '@/components/machines/serviceFormat';
import type { Service, ServiceStat } from '@/components/machines/serviceFormat';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * A group of services as one row: how many are running, their combined CPU
 * and memory, how long they've all been up, and every port they listen on.
 * Clicking it opens the group to show its services underneath.
 */
const props = defineProps<{
    name: string;
    /** A group someone made, or a Compose stack grouped on its own. */
    kind: 'group' | 'stack';
    services: Service[];
    stats: Record<string, ServiceStat>;
    expanded: boolean;
    canManage: boolean;
    /** An icon someone uploaded for the group, instead of its first service's. */
    iconUrl?: string | null;
}>();

const emit = defineEmits<{
    toggle: [];
    edit: [];
    delete: [];
    keep: [];
}>();

const panelId = computed(
    () => `group-${props.kind}-${props.name.replace(/\W+/g, '-')}`,
);

const counts = computed(() => {
    const result = { running: 0, stopped: 0, problems: 0 };

    for (const service of props.services) {
        result[statusGroup(service)]++;
    }

    return result;
});

const summary = computed(() => {
    const total = props.services.length;
    const { running, problems } = counts.value;

    if (total === 0)
        return { dot: 'bg-(--ds-text-faint)', label: trans('Empty') };
    if (problems > 0)
        return { dot: 'bg-(--ds-red)', label: `${problems} failing` };
    if (running === total)
        return { dot: 'bg-(--ds-green)', label: trans('All running') };
    if (running === 0)
        return { dot: 'bg-(--ds-text-faint)', label: trans('Stopped') };

    return { dot: 'bg-(--ds-amber)', label: `${running} of ${total} up` };
});

const live = computed(() =>
    props.services
        .map((service) => props.stats[service.key])
        .filter((stat): stat is ServiceStat => !!stat),
);

const cpu = computed(() => {
    const values = live.value
        .map((stat) => stat.cpu)
        .filter((value): value is number => value !== null);

    return values.length
        ? Math.round(values.reduce((sum, v) => sum + v, 0))
        : null;
});

const memory = computed(() => {
    const values = live.value
        .map((stat) => stat.memUsed)
        .filter((value): value is number => value !== null);

    return values.length ? values.reduce((sum, v) => sum + v, 0) : null;
});

/** Up for as long as its most recently started service. */
const startedAt = computed(() => {
    const values = live.value
        .map((stat) => stat.startedAt)
        .filter((value): value is number => !!value);

    return values.length ? Math.max(...values) : null;
});

/**
 * The service the group is about, for its logo: the app rather than its
 * database or cache, preferring one named like the group (a "wordpress"
 * stack shows WordPress, not MariaDB).
 */
const lead = computed<Service | null>(() => {
    const name = props.name.toLowerCase();
    const isApp = (s: Service) => !['database', 'cache'].includes(s.kind);

    return (
        props.services.find(
            (s) => isApp(s) && s.name.toLowerCase().includes(name),
        ) ??
        props.services.find(isApp) ??
        props.services[0] ??
        null
    );
});

const ports = computed(() =>
    [...new Set(props.services.flatMap((service) => service.ports))].sort(
        (a, b) => a - b,
    ),
);
</script>

<template>
    <div
        class="cursor-pointer py-2.5 pr-4 pl-4 text-left text-[13px]/4 transition-colors select-none hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="[serviceColumns, expanded ? 'bg-(--ds-subtle)' : '']"
        role="row"
        tabindex="0"
        :aria-expanded="expanded"
        :aria-controls="panelId"
        @click="emit('toggle')"
        @keydown.enter.self.prevent="emit('toggle')"
        @keydown.space.self.prevent="emit('toggle')"
    >
        <span class="flex min-w-0 items-center gap-2" role="cell">
            <ChevronRight
                class="-ml-1 size-3.5 shrink-0 text-(--ds-text-muted) transition-transform duration-200"
                :class="{ 'rotate-90': expanded }"
                aria-hidden="true"
            />
            <TooltipProvider :delay-duration="250" disable-hoverable-content>
                <TooltipRoot>
                    <TooltipTrigger as-child>
                        <span class="flex min-w-0 items-center gap-3">
                            <span class="relative shrink-0" aria-hidden="true">
                                <ServiceLogo
                                    v-if="lead || iconUrl"
                                    :name="lead?.name ?? name"
                                    :kind="lead?.kind"
                                    :service-key="lead?.key"
                                    :image="lead?.details?.image"
                                    :icon-url="iconUrl ?? lead?.icon"
                                />
                                <span
                                    v-else
                                    class="flex size-9 items-center justify-center rounded-lg border border-(--ds-border-strong) bg-(--ds-surface) text-(--ds-text-muted)"
                                >
                                    <Folder
                                        class="size-4"
                                        :stroke-width="1.75"
                                    />
                                </span>
                                <span
                                    v-if="services.length > 1"
                                    class="absolute -right-1.5 -bottom-1 flex h-4 min-w-4 items-center justify-center rounded-full border border-(--ds-border-strong) bg-(--ds-card) px-1 text-[10px]/3 font-semibold text-(--ds-text-secondary) tabular-nums"
                                >
                                    {{ services.length }}
                                </span>
                            </span>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate font-medium">{{
                                    name
                                }}</span>
                                <span
                                    class="truncate text-xs text-(--ds-text-muted)"
                                >
                                    {{ services.length }}
                                    {{
                                        services.length === 1
                                            ? $t('service')
                                            : $t('services')
                                    }}
                                    ·
                                    {{
                                        kind === 'stack'
                                            ? $t('Compose stack')
                                            : 'group'
                                    }}
                                </span>
                            </span>
                        </span>
                    </TooltipTrigger>
                    <TooltipPortal>
                        <TooltipContent
                            v-if="services.length"
                            side="bottom"
                            align="start"
                            :side-offset="6"
                            class="group-peek ds-modal z-50 w-72 rounded-[10px] p-1 font-ui text-(--ds-text)"
                        >
                            <ul class="flex flex-col">
                                <li
                                    v-for="(service, index) in services"
                                    :key="service.key"
                                    class="group-peek-item flex items-center gap-2.5 rounded-md px-2 py-1.5"
                                    :style="{ '--i': index }"
                                >
                                    <ServiceLogo
                                        :name="service.name"
                                        :kind="service.kind"
                                        :service-key="service.key"
                                        :image="service.details?.image"
                                        :icon-url="service.icon"
                                        class="size-6! rounded-md! [&_svg]:size-3.5!"
                                    />
                                    <span class="flex min-w-0 grow flex-col">
                                        <span
                                            class="truncate text-xs/4 font-medium"
                                        >
                                            {{
                                                service.label ??
                                                service.app ??
                                                service.name
                                            }}
                                        </span>
                                        <span
                                            class="truncate text-[11px]/4 text-(--ds-text-muted)"
                                        >
                                            {{ service.kind
                                            }}<template
                                                v-if="service.ports.length"
                                            >
                                                ·
                                                <span class="font-ui-mono">{{
                                                    service.ports
                                                        .map((p) => `:${p}`)
                                                        .join(' ')
                                                }}</span></template
                                            >
                                        </span>
                                    </span>
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :class="status(service).dot"
                                        :title="status(service).label"
                                    />
                                </li>
                            </ul>
                        </TooltipContent>
                    </TooltipPortal>
                </TooltipRoot>
            </TooltipProvider>
        </span>

        <span class="hidden items-center gap-1.75 md:flex" role="cell">
            <span class="size-1.5 shrink-0 rounded-full" :class="summary.dot" />
            {{ summary.label }}
        </span>

        <span class="hidden min-w-0 items-center gap-2 md:flex" role="cell">
            <CpuBar
                v-if="cpu !== null"
                :cpu="Math.min(100, cpu)"
                themed
                class="min-w-0 grow"
            />
            <span v-else class="text-(--ds-text-faint)">—</span>
        </span>

        <span class="hidden tabular-nums md:block" role="cell">
            {{ bytes(memory) }}
        </span>

        <span class="hidden tabular-nums md:block" role="cell">
            {{ uptime(startedAt) }}
        </span>

        <span
            class="hidden truncate font-ui-mono text-xs text-(--ds-text-secondary) md:block"
            role="cell"
            :title="ports.map((p) => `:${p}`).join(' ')"
        >
            {{ ports.length ? ports.map((p) => `:${p}`).join(' ') : '—' }}
        </span>

        <span class="flex items-center justify-end" role="cell">
            <DropdownMenu v-if="canManage">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="-mr-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
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
                    <template v-if="kind === 'group'">
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
                    </template>
                    <DropdownMenuItem v-else @select="emit('keep')">
                        <Folder class="size-4" :stroke-width="1.75" />
                        {{ $t('Save as a group') + ' ' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </span>
    </div>
</template>
