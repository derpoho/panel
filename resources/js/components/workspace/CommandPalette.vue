<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import { CornerDownLeft, Plus, Search } from '@lucide/vue';
import {
    SolarPulse as Activity,
    SolarHistory as History,
    SolarLayoutGrid as LayoutGrid,
    SolarServer as Server,
    SolarSettings as Settings,
    SolarWorkflow as Workflow,
    SolarArchive as Archive,
    SolarCreditCard as CreditCard,
    SolarHouse as House,
    SolarLayers as Layers,
    SolarScrollText as ScrollText,
    SolarUser as User,
    SolarUsers as Users,
} from '@/lib/solarIcons';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { Component } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import { onWorkspace } from '@/lib/workspaceRoute';
import { home } from '@/routes/workspace';
import machinesRoutes from '@/routes/workspace/machines';
import membersRoutes from '@/routes/workspace/members';
import settingsRoutes from '@/routes/workspace/settings';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { Workspace, WorkspaceSummary } from '@/types';

const GROUPS = [
    'Machines',
    'Services',
    'Monitors',
    'Workflows',
    'Actions',
    'Pages',
    'Workspaces',
] as const;

type Group = (typeof GROUPS)[number];

type Command = {
    id: string;
    group: Group;
    label: string;
    hint?: string;
    icon?: Component;
    workspace?: WorkspaceSummary;
    run: () => void;
};

const open = defineModel<boolean>('open', { default: false });

const page = usePage<{ workspace?: Workspace }>();
const { switchTo } = useWorkspaceSwitch();
const upgrade = useUpgradeLink();

const query = ref('');
const activeIndex = ref(0);
const listId = 'command-palette-list';

function visit(url: string): void {
    open.value = false;
    router.visit(url);
}

const pages = computed<Command[]>(() => {
    const slug = page.props.workspace?.slug;

    if (!slug) {
        return [];
    }

    const entries: [string, Component, string][] = [
        [trans('Home'), House, onWorkspace(home(slug)).url],
        [
            trans('Machines'),
            Server,
            onWorkspace(machinesRoutes.index(slug)).url,
        ],
        [trans('Services'), Layers, '/services'],
        [trans('Workflows'), Workflow, '/workflows'],
        [trans('Backups'), Archive, '/backups'],
        [trans('Monitors'), Activity, '/monitors'],
        [trans('Logs'), ScrollText, '/logs'],
        [trans('Activity'), History, '/activity'],
        // Where to get more (serveros.com: Billing), when a package says.
        ...(upgrade.value
            ? [
                  [upgrade.value.label, CreditCard, upgrade.value.href] as [
                      string,
                      Component,
                      string,
                  ],
              ]
            : []),
        [
            trans('General settings'),
            Settings,
            onWorkspace(settingsRoutes.general(slug)).url,
        ],
        [trans('Members'), Users, onWorkspace(membersRoutes.index(slug)).url],
        [trans('Profile'), User, onWorkspace(settingsRoutes.profile(slug)).url],
    ];

    return entries.map(([label, icon, url]) => ({
        id: `page:${label}`,
        group: 'Pages' as const,
        label,
        icon,
        run: () => visit(url),
    }));
});

const workspaces = computed<Command[]>(() => {
    const current = page.props.workspace;

    const items: Command[] = page.props.workspaces.map((workspace) => ({
        id: `workspace:${workspace.id}`,
        group: 'Workspaces' as const,
        label: workspace.name,
        hint: workspace.host,
        workspace,
        run: () => {
            open.value = false;

            if (current && workspace.id === current.id) {
                router.visit(onWorkspace(home(current.slug)).url);
            } else if (current) {
                switchTo(workspace);
            } else {
                window.location.assign(workspace.switchUrl);
            }
        },
    }));

    items.push({
        id: 'workspace:all',
        group: 'Workspaces',
        label: trans('All workspaces'),
        icon: LayoutGrid,
        run: () => {
            open.value = false;
            window.location.assign(
                new URL(workspacesIndex.url(), page.props.appUrl).href,
            );
        },
    });

    return items;
});

/* Things in the workspace, found as you type. */

type Found = { label: string; hint: string | null; url: string };
const found = ref<Record<string, Found[]>>({});
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchSeq = 0;

watch(query, (value) => {
    clearTimeout(searchTimer);
    const needle = value.trim();
    if (needle.length < 2 || !page.props.workspace) {
        found.value = {};

        return;
    }
    searchTimer = setTimeout(async () => {
        const seq = ++searchSeq;
        try {
            const response = await fetch(
                `/search?q=${encodeURIComponent(needle)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            if (response.ok && seq === searchSeq)
                found.value = await response.json();
        } catch {
            // Offline or slow: pages still match.
        }
    }, 150);
});

const foundCommands = computed<Command[]>(() => {
    const icons: Record<string, Component> = {
        machines: Server,
        services: Layers,
        monitors: Activity,
        workflows: Workflow,
    };
    const groups: Record<string, Group> = {
        machines: 'Machines',
        services: 'Services',
        monitors: 'Monitors',
        workflows: 'Workflows',
    };

    return Object.entries(found.value).flatMap(([kind, items]) =>
        (items ?? []).map((item, i) => ({
            id: `${kind}:${i}:${item.url}`,
            group: groups[kind] ?? 'Pages',
            label: item.label,
            hint: item.hint ?? undefined,
            icon: icons[kind],
            run: () => visit(item.url),
        })),
    );
});

/** Things to do with what was typed. */
const actions = computed<Command[]>(() => {
    if (!page.props.workspace) return [];
    const needle = query.value.trim();
    const list: Command[] = [];
    if (needle.length >= 2) {
        list.push({
            id: 'action:logs',
            group: 'Actions',
            label: trans('Search logs for “:needle”', {
                needle: String(needle),
            }),
            icon: ScrollText,
            run: () => visit(`/logs?q=${encodeURIComponent(needle)}`),
        });
    }
    list.push(
        {
            id: 'action:monitor',
            group: 'Actions',
            label: trans('New monitor'),
            icon: Plus,
            run: () => visit('/monitors?new=1'),
        },
        {
            id: 'action:workflow',
            group: 'Actions',
            label: trans('New workflow'),
            icon: Plus,
            run: () => visit('/workflows?new=1'),
        },
    );

    return list;
});

const results = computed(() => {
    const needle = query.value.trim().toLowerCase();
    const matches = (command: Command) =>
        needle === '' ||
        command.label.toLowerCase().includes(needle) ||
        (command.hint?.toLowerCase().includes(needle) ?? false);

    const all = [
        ...foundCommands.value,
        ...actions.value.filter((c) => c.id === 'action:logs' || matches(c)),
        ...pages.value.filter(matches),
        ...workspaces.value.filter(matches),
    ];

    // In the order they're shown, so the arrow keys follow the list.
    return GROUPS.flatMap((group) => all.filter((c) => c.group === group));
});

const grouped = computed(() =>
    GROUPS.map((group) => ({
        group,
        items: results.value
            .map((command, index) => ({ command, index }))
            .filter(({ command }) => command.group === group),
    })).filter(({ items }) => items.length > 0),
);

watch(query, () => (activeIndex.value = 0));

watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        activeIndex.value = 0;
    }
});

function scrollActiveIntoView(): void {
    void nextTick(() =>
        document
            .getElementById(`command-${activeIndex.value}`)
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onInputKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.key === 'ArrowDown' && count > 0) {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % count;
        scrollActiveIntoView();
    } else if (event.key === 'ArrowUp' && count > 0) {
        event.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + count) % count;
        scrollActiveIntoView();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        results.value[activeIndex.value]?.run();
    }
}

function onWindowKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
}

onMounted(() => window.addEventListener('keydown', onWindowKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onWindowKeydown));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="ds-modal top-[20%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text) sm:max-w-lg"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">{{ $t('Search') }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ ' ' + $t('Jump to a page or switch workspace.') + ' ' }}
            </DialogDescription>

            <label
                class="flex h-12 items-center gap-2.5 border-b border-(--ds-border) px-4"
            >
                <Search
                    class="size-4 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.5"
                />
                <span class="sr-only">{{
                    $t('Search pages and workspaces')
                }}</span>
                <input
                    v-model="query"
                    v-focus
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    :aria-controls="listId"
                    :aria-activedescendant="
                        results.length ? `command-${activeIndex}` : undefined
                    "
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="$t('Search pages and workspaces…')"
                    class="h-full min-w-0 grow bg-transparent text-sm text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                    @keydown="onInputKeydown"
                />
                <kbd
                    class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px text-[11px]/3.5 text-(--ds-text-muted)"
                    aria-hidden="true"
                >
                    Esc
                </kbd>
            </label>

            <div
                :id="listId"
                role="listbox"
                :aria-label="$t('Results')"
                class="max-h-80 overflow-y-auto p-1.5"
            >
                <p
                    v-if="results.length === 0"
                    class="px-3 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('Nothing matches “:query”.', { query }) + ' ' }}
                </p>

                <div v-for="section in grouped" :key="section.group">
                    <p
                        class="px-2.5 pt-2 pb-1.5 text-xs/4 font-medium text-(--ds-text-muted)"
                        aria-hidden="true"
                    >
                        {{ $t(section.group) }}
                    </p>
                    <div
                        v-for="{ command, index } in section.items"
                        :id="`command-${index}`"
                        :key="command.id"
                        role="option"
                        :aria-selected="index === activeIndex"
                        class="flex h-9 cursor-pointer items-center gap-2.5 rounded-[7px] px-2.5 text-[13px]/4"
                        :class="
                            index === activeIndex
                                ? 'bg-(--ds-hover) text-(--ds-text)'
                                : 'text-(--ds-text-secondary)'
                        "
                        @mousemove="activeIndex = index"
                        @click="command.run()"
                    >
                        <span
                            v-if="command.workspace"
                            class="flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-[4px] text-[10px]/none font-semibold"
                            :class="workspaceTileColor(command.workspace.id)"
                            aria-hidden="true"
                        >
                            <img
                                v-if="command.workspace.logoUrl"
                                :src="command.workspace.logoUrl"
                                alt=""
                                class="size-full object-cover"
                            />
                            <template v-else>{{
                                workspaceInitial(command.workspace.name)
                            }}</template>
                        </span>
                        <component
                            :is="command.icon"
                            v-else-if="command.icon"
                            class="size-4 shrink-0 text-(--ds-text-muted)"
                            :stroke-width="1.5"
                        />
                        <span class="truncate">{{ command.label }}</span>
                        <span
                            v-if="command.hint"
                            class="truncate text-xs text-(--ds-text-muted)"
                        >
                            {{ command.hint }}
                        </span>
                        <CornerDownLeft
                            v-if="index === activeIndex"
                            class="ml-auto size-3.5 shrink-0 text-(--ds-text-muted)"
                            :stroke-width="1.5"
                        />
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
