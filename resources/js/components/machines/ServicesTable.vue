<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { Check, ChevronDown, LoaderCircle, Search } from '@lucide/vue';
import {
    SolarEye as Eye,
    SolarEyeOff as EyeOff,
    SolarFolderPlus as FolderPlus,
} from '@/lib/solarIcons';
import { computed, ref, watch } from 'vue';
import {
    passes,
    serviceColumns,
    statusGroup,
} from '@/components/machines/serviceFormat';
import type {
    Service,
    ServiceFilter,
    ServiceStat,
} from '@/components/machines/serviceFormat';
import ServiceGroupRow from '@/components/machines/ServiceGroupRow.vue';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import ServiceRow from '@/components/machines/ServiceRow.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import GroupDialog from '@/components/workspace/GroupDialog.vue';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import type { GroupDialogItem } from '@/components/workspace/GroupDialog.vue';

export type { Service, ServiceStat } from '@/components/machines/serviceFormat';

export type BuildingService = {
    jobId: string;
    name: string;
    state: 'queued' | 'building' | 'starting' | 'failed';
    phase: string | null;
    progress: number | null;
};

export type ServiceGroup = {
    id: number;
    name: string;
    services: string[];
    iconUrl?: string | null;
};

const props = withDefaults(
    defineProps<{
        services: Service[];
        stats: Record<string, ServiceStat>;
        machineId: number;
        groups?: ServiceGroup[];
        canManage?: boolean;
        building?: BuildingService[];
    }>(),
    { groups: () => [], canManage: false, building: () => [] },
);

const emit = defineEmits<{ 'open-log': [jobId: string] }>();

const buildingLabels = computed(() => ({
    queued: trans('Queued'),
    building: trans('Building'),
    starting: trans('Starting'),
    failed: trans('Failed'),
}));

function phaseLabel(entry: BuildingService): string {
    const phase = entry.phase
        ? entry.phase.charAt(0).toUpperCase() + entry.phase.slice(1)
        : null;
    const progress =
        entry.progress !== null && entry.state === 'building'
            ? `${Math.round(entry.progress)}%`
            : null;

    return [trans('Deploy'), phase, progress].filter(Boolean).join(' · ');
}

type StatusFilter = ServiceFilter;
type SortKey = 'cpu' | 'memory' | 'uptime' | 'name';

const filter = ref<StatusFilter>('all');
const search = ref('');
const sort = ref<SortKey>('cpu');

const showHidden = ref(false);
const hiddenCount = computed(
    () => props.services.filter((service) => service.hidden).length,
);
const services = computed(() =>
    showHidden.value
        ? props.services
        : props.services.filter((service) => !service.hidden),
);

watch(hiddenCount, (count) => {
    if (count === 0) showHidden.value = false;
});

const selected = ref<string[]>([]);
const selectedServices = computed(() =>
    props.services.filter((service) => selected.value.includes(service.key)),
);
const hiding = ref(false);

function toggleSelected(service: Service): void {
    selected.value = selected.value.includes(service.key)
        ? selected.value.filter((key) => key !== service.key)
        : [...selected.value, service.key];
}

function setHidden(hidden: boolean): void {
    const keys = selectedServices.value
        .filter((service) => !!service.hidden !== hidden)
        .map((service) => service.key);
    if (keys.length === 0) return;

    router.put(
        `/machines/${props.machineId}/hidden-services`,
        { services: keys, hidden },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (hiding.value = true),
            onFinish: () => (hiding.value = false),
            onSuccess: () => (selected.value = []),
        },
    );
}

function statFor(service: Service): ServiceStat | null {
    return props.stats[service.key] ?? null;
}

const tabs = computed(() =>
    (
        [
            ['all', trans('All')],
            ['managed', trans('Managed')],
            ['running', trans('Running')],
            ['stopped', trans('Stopped')],
            ['problems', trans('Problems')],
        ] as const
    ).map(([value, label]) => ({
        value,
        label,
        count: services.value.filter((s) => passes(s, value)).length,
    })),
);

const sortOptions: { value: SortKey; label: string }[] = [
    { value: 'cpu', label: trans('CPU usage') },
    { value: 'memory', label: trans('Memory') },
    { value: 'uptime', label: trans('Uptime') },
    { value: 'name', label: trans('Name') },
];

const sortLabel = computed(
    () => sortOptions.find((o) => o.value === sort.value)?.label ?? '',
);

function sortValue(services: Service[]): number {
    const now = Date.now() / 1000;
    const stats = services.map(statFor);

    switch (sort.value) {
        case 'cpu':
            return stats.reduce((sum, stat) => sum + (stat?.cpu ?? 0), 0) || -1;
        case 'memory':
            return (
                stats.reduce((sum, stat) => sum + (stat?.memUsed ?? 0), 0) || -1
            );
        case 'uptime': {
            const started = stats
                .map((stat) => stat?.startedAt)
                .filter((value): value is number => !!value);

            return started.length ? now - Math.max(...started) : -1;
        }
        default:
            return 0;
    }
}

function compare(
    a: { name: string; services: Service[] },
    b: { name: string; services: Service[] },
): number {
    return sort.value === 'name'
        ? a.name.localeCompare(b.name)
        : sortValue(b.services) - sortValue(a.services);
}

const needle = computed(() => search.value.trim().toLowerCase());

function matches(service: Service): boolean {
    return (
        passes(service, filter.value) &&
        (!needle.value ||
            `${service.label ?? ''} ${service.app ?? ''} ${service.found ?? ''} ${service.name} ${service.manager} ${service.kind} ${service.ports.join(' ')}`
                .toLowerCase()
                .includes(needle.value))
    );
}

type Entry = {
    id: string;
    kind: 'group' | 'stack';
    name: string;
    group: ServiceGroup | null;
    services: Service[];
};

function memberKey(service: Service): string {
    return service.app ? `app:${service.app}` : service.key;
}

const byKey = computed(() => {
    const map = new Map<string, Service>();
    for (const service of services.value) {
        map.set(service.key, service);
        map.set(memberKey(service), service);
    }
    return map;
});

const grouping = computed(() => {
    const entries: Omit<Entry, 'services'>[] = [];
    const members = new Map<string, Service[]>();
    const taken = new Set<string>();

    for (const group of props.groups) {
        const id = `g:${group.id}`;
        const inside = group.services
            .map((key) => byKey.value.get(key))
            .filter((service): service is Service => !!service);

        inside.forEach((service) => taken.add(service.key));
        entries.push({ id, kind: 'group', name: group.name, group });
        members.set(id, inside);
    }

    const stacks = new Map<string, Service[]>();
    for (const service of services.value) {
        const project = service.details?.compose_project;
        if (project && !taken.has(service.key)) {
            stacks.set(project, [...(stacks.get(project) ?? []), service]);
        }
    }
    for (const [project, inside] of stacks) {
        if (inside.length < 2) continue;

        const id = `s:${project}`;
        inside.forEach((service) => taken.add(service.key));
        entries.push({
            id,
            kind: 'stack',
            name: project.replace(/^serveros-/, ''),
            group: null,
        });
        members.set(id, inside);
    }

    return { entries, members, taken };
});

const groupRows = computed<Entry[]>(() => {
    const { entries, members } = grouping.value;

    return entries
        .map((entry) => {
            const all = members.get(entry.id) ?? [];
            const nameHit =
                !!needle.value &&
                entry.name.toLowerCase().includes(needle.value);

            return {
                ...entry,
                services: (nameHit
                    ? all.filter((s) => passes(s, filter.value))
                    : all.filter(matches)
                ).sort((a, b) =>
                    compare(
                        { name: a.name, services: [a] },
                        { name: b.name, services: [b] },
                    ),
                ),
            };
        })
        .filter(
            (entry) =>
                entry.services.length > 0 ||
                (entry.kind === 'group' &&
                    !needle.value &&
                    filter.value === 'all'),
        )
        .sort(compare);
});

const loose = computed(() =>
    services.value
        .filter((service) => !grouping.value.taken.has(service.key))
        .filter(matches)
        .sort((a, b) =>
            compare(
                { name: a.name, services: [a] },
                { name: b.name, services: [b] },
            ),
        ),
);

const empty = computed(
    () =>
        groupRows.value.length === 0 &&
        loose.value.length === 0 &&
        props.building.length === 0,
);

const storageKey = computed(() => `serveros:open-groups:${props.machineId}`);

function readOpen(): Set<string> {
    try {
        return new Set(
            JSON.parse(localStorage.getItem(storageKey.value) ?? '[]'),
        );
    } catch {
        return new Set();
    }
}

const open = ref<Set<string>>(
    typeof window === 'undefined' ? new Set() : readOpen(),
);

watch(open, (value) => {
    try {
        localStorage.setItem(storageKey.value, JSON.stringify([...value]));
    } catch {}
});

function isOpen(entry: Entry): boolean {
    return (
        open.value.has(entry.id) ||
        (!!needle.value && entry.services.length > 0)
    );
}

function toggle(entry: Entry): void {
    const next = new Set(open.value);

    if (next.has(entry.id)) {
        next.delete(entry.id);
    } else {
        next.add(entry.id);
    }

    open.value = next;
}

const dialogOpen = ref(false);
const editing = ref<ServiceGroup | null>(null);
const editingNow = computed(
    () =>
        props.groups.find((group) => group.id === editing.value?.id) ??
        editing.value,
);
const initialName = ref('');
const initialSelected = ref<string[]>([]);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const dialogItems = computed<GroupDialogItem<string>[]>(() => {
    const inGroup = new Map<string, string>();
    for (const group of props.groups) {
        if (group.id === editing.value?.id) continue;
        group.services.forEach((key) => {
            const service = byKey.value.get(key);
            inGroup.set(service ? memberKey(service) : key, group.name);
        });
    }

    return [...props.services]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((service) => ({
            id: memberKey(service),
            label:
                service.label ?? service.app ?? service.found ?? service.name,
            detail: `${service.manager} · ${service.kind}${service.ports.length ? ` · ${service.ports.map((p) => `:${p}`).join(' ')}` : ''}`,
            groupName: inGroup.get(memberKey(service)) ?? null,
        }));
});

function startNew(name = '', keys: string[] = []): void {
    editing.value = null;
    initialName.value = name;
    initialSelected.value = keys;
    errors.value = {};
    dialogOpen.value = true;
}

function startEdit(group: ServiceGroup): void {
    editing.value = group;
    initialName.value = group.name;
    initialSelected.value = group.services.map((key) => {
        const service = byKey.value.get(key);
        return service ? memberKey(service) : key;
    });
    errors.value = {};
    dialogOpen.value = true;
}

const base = computed(() => `/machines/${props.machineId}/service-groups`);

function save({ name, ids }: { name: string; ids: string[] }): void {
    const group = editing.value;
    const payload = { name, services: ids };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (bag: Record<string, string>) => (errors.value = bag),
        onSuccess: () => {
            dialogOpen.value = false;
            if (!group) {
                const created = props.groups.find(
                    (g) => g.name === name && !open.value.has(`g:${g.id}`),
                );
                if (created) {
                    open.value = new Set(open.value).add(`g:${created.id}`);
                }
            }
        },
    };

    if (group) {
        router.put(`${base.value}/${group.id}`, payload, options);
    } else {
        router.post(base.value, payload, options);
    }
}

function remove(group: ServiceGroup): void {
    router.delete(`${base.value}/${group.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (dialogOpen.value = false),
    });
}

function keepStack(entry: Entry): void {
    startNew(
        entry.name,
        grouping.value.members.get(entry.id)?.map(memberKey) ?? [],
    );
}

defineExpose({ startNew });
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Filter by status')"
                >
                    <button
                        v-for="option in tabs"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :aria-pressed="filter === option.value"
                        @click="filter = option.value"
                    >
                        {{ option.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                filter === option.value
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
                    class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-60"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Search services') }}</span>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="$t('Search services')"
                        class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                    />
                </label>

                <template v-if="canManage && selected.length">
                    <button
                        v-if="selectedServices.some((s) => !s.hidden)"
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :disabled="hiding"
                        @click="setHidden(true)"
                    >
                        <EyeOff
                            class="size-3.5 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                        {{ $t('Hide') }}
                        <span class="font-medium tabular-nums">{{
                            selectedServices.filter((s) => !s.hidden).length
                        }}</span>
                    </button>
                    <button
                        v-if="selectedServices.some((s) => s.hidden)"
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :disabled="hiding"
                        @click="setHidden(false)"
                    >
                        <Eye
                            class="size-3.5 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                        {{ $t('Unhide') }}
                        <span class="font-medium tabular-nums">{{
                            selectedServices.filter((s) => s.hidden).length
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        @click="selected = []"
                    >
                        {{ ' ' + $t('Clear') + ' ' }}
                    </button>
                </template>

                <button
                    v-if="hiddenCount > 0"
                    type="button"
                    class="ds-raised-button cursor-pointer text-[13px]!"
                    :aria-pressed="showHidden"
                    @click="showHidden = !showHidden"
                >
                    <component
                        :is="showHidden ? EyeOff : Eye"
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    {{
                        showHidden ? $t('Leave out hidden') : $t('Show hidden')
                    }}
                    <span class="text-(--ds-text-muted) tabular-nums">{{
                        hiddenCount
                    }}</span>
                </button>

                <button
                    v-if="canManage"
                    type="button"
                    class="ds-raised-button cursor-pointer text-[13px]!"
                    @click="startNew()"
                >
                    <FolderPlus
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    {{ $t('New group') + ' ' }}
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="ds-raised-button cursor-pointer text-[13px]!"
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
            </div>
        </div>

        <div
            class="ds-tile overflow-x-auto"
            role="table"
            :aria-label="$t('Services')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="serviceColumns"
                role="row"
            >
                <span role="columnheader">{{ $t('Service') }}</span>
                <span role="columnheader">{{ $t('Status') }}</span>
                <span role="columnheader">{{ $t('CPU') }}</span>
                <span role="columnheader">{{ $t('Memory') }}</span>
                <span role="columnheader">{{ $t('Uptime') }}</span>
                <span role="columnheader">{{ $t('Ports') }}</span>
                <span role="columnheader"
                    ><span class="sr-only">{{ $t('Details') }}</span></span
                >
            </div>

            <div
                v-for="entry in building"
                :key="`building-${entry.jobId}`"
                class="border-b border-(--ds-divider) last:border-b-0"
                role="rowgroup"
            >
                <button
                    type="button"
                    class="w-full cursor-pointer px-4 py-2.5 text-left text-[13px]/4 transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                    :class="serviceColumns"
                    role="row"
                    :aria-label="
                        $t(':service: :state. Open the deploy log', {
                            service: entry.name,
                            state: buildingLabels[entry.state],
                        })
                    "
                    @click="emit('open-log', entry.jobId)"
                >
                    <span class="flex min-w-0 items-center gap-3" role="cell">
                        <span
                            v-if="canManage"
                            class="size-4 shrink-0 rounded-sm border-[1.5px] border-(--ds-border-hover) opacity-50"
                            aria-hidden="true"
                        />
                        <ServiceLogo :name="entry.name" kind="" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-medium">{{
                                entry.name
                            }}</span>
                            <span
                                class="truncate text-xs text-(--ds-text-muted)"
                                >{{ phaseLabel(entry) }}</span
                            >
                        </span>
                    </span>
                    <span
                        class="flex items-center gap-1.75 md:col-start-2"
                        role="cell"
                    >
                        <LoaderCircle
                            v-if="entry.state !== 'failed'"
                            class="size-3 shrink-0 animate-spin text-(--ds-amber)"
                            :stroke-width="2.4"
                        />
                        <span
                            v-else
                            class="size-1.5 shrink-0 rounded-full bg-(--ds-red)"
                        />
                        {{ buildingLabels[entry.state] }}
                    </span>
                    <span
                        class="hidden text-(--ds-text-faint) md:block"
                        role="cell"
                        >—</span
                    >
                    <span
                        class="hidden text-(--ds-text-faint) md:block"
                        role="cell"
                        >—</span
                    >
                    <span
                        class="hidden text-(--ds-text-faint) md:block"
                        role="cell"
                        >—</span
                    >
                    <span
                        class="hidden text-(--ds-text-faint) md:block"
                        role="cell"
                        >—</span
                    >
                    <span role="cell" class="hidden md:block" />
                </button>
            </div>

            <div
                v-for="entry in groupRows"
                :key="entry.id"
                class="border-b border-(--ds-divider) last:border-b-0"
                role="rowgroup"
            >
                <ServiceGroupRow
                    :name="entry.name"
                    :kind="entry.kind"
                    :services="entry.services"
                    :stats="stats"
                    :expanded="isOpen(entry)"
                    :can-manage="canManage"
                    :icon-url="entry.group?.iconUrl"
                    @toggle="toggle(entry)"
                    @edit="entry.group && startEdit(entry.group)"
                    @delete="entry.group && remove(entry.group)"
                    @keep="keepStack(entry)"
                />
                <div
                    v-if="isOpen(entry)"
                    :id="`group-${entry.kind}-${entry.name.replace(/\W+/g, '-')}`"
                    class="border-t border-(--ds-divider) bg-(--ds-subtle)/40"
                >
                    <ServiceRow
                        v-for="service in entry.services"
                        :key="service.key"
                        :service="service"
                        :stat="statFor(service)"
                        :machine-id="machineId"
                        :selectable="canManage"
                        :selected="selected.includes(service.key)"
                        nested
                        @toggle="toggleSelected(service)"
                        class="border-b border-(--ds-divider) last:border-b-0"
                    />
                    <p
                        v-if="entry.services.length === 0"
                        class="py-3 pr-4 pl-11 text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Nothing in this group is running on the machine right now.',
                            )
                        }}
                        <button
                            v-if="canManage && entry.group"
                            type="button"
                            class="cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                            @click="startEdit(entry.group)"
                        >
                            {{ ' ' + $t('Edit group') + ' ' }}
                        </button>
                    </p>
                </div>
            </div>

            <div
                v-for="service in loose"
                :key="service.key"
                class="border-b border-(--ds-divider) last:border-b-0"
                role="rowgroup"
            >
                <ServiceRow
                    :service="service"
                    :stat="statFor(service)"
                    :machine-id="machineId"
                    :selectable="canManage"
                    :selected="selected.includes(service.key)"
                    @toggle="toggleSelected(service)"
                />
            </div>

            <p
                v-if="empty"
                class="px-4 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ ' ' + $t('No services match these filters.') + ' ' }}
            </p>
        </div>

        <GroupDialog
            v-model:open="dialogOpen"
            noun="service"
            :items="dialogItems"
            :editing="!!editing"
            :initial-name="initialName"
            :initial-selected="initialSelected"
            :processing="saving"
            :errors="errors"
            @save="save"
            @delete="editing && remove(editing)"
        >
            <template v-if="editingNow" #extra>
                <ImageUploadField
                    field="icon"
                    max-size="2 MB"
                    :label="$t('Icon')"
                    :image-url="editingNow.iconUrl ?? null"
                    :fallback="editingNow.name.charAt(0).toUpperCase()"
                    :upload-url="`${base}/${editingNow.id}/icon`"
                    :remove-url="`${base}/${editingNow.id}/icon`"
                />
            </template>
        </GroupDialog>
    </div>
</template>
