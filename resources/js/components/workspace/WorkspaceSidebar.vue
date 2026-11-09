<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { PanelLeft, PinOff, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import type { Component } from 'vue';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import WorkspaceMenu from '@/components/workspace/WorkspaceMenu.vue';
import { useWorkspaceNav } from '@/components/workspace/workspaceNav';
import type { WorkspaceNavItem } from '@/components/workspace/workspaceNav';
import { useAssistant } from '@/composables/useAssistant';
import { usePinnedWorkspaces } from '@/composables/usePinnedWorkspaces';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { SolarAskAi } from '@/lib/solarIcons';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import type { Workspace, WorkspaceSummary } from '@/types';

/*
 * The workspace's sidebar: the workspace menu with search and collapse
 * beside it; Home, Activity and Ask AI; the sections under Servers,
 * Operations and Workspace; then pinned workspaces and the account.
 */
type Props = {
    /** Icons only. */
    collapsed?: boolean;
    /** Show the collapse button (desktop only). */
    collapsible?: boolean;
};

const { collapsed = false, collapsible = false } = defineProps<Props>();

const emit = defineEmits<{
    toggle: [];
    search: [];
    assistant: [];
    whatsNew: [];
}>();

const page = usePage<{ workspace?: Workspace }>();
const items = useWorkspaceNav();
const assistant = useAssistant();

/** Opens the chat; on phones the menu sheet closes so it can be seen. */
function openAssistant(): void {
    assistant.toggle();
    emit('assistant');
}

/* Pinned workspaces: one click away, below the sections. */
const { pinned, toggle: unpin } = usePinnedWorkspaces();
const { switchingTo, switchTo } = useWorkspaceSwitch();

function openPinned(workspace: WorkspaceSummary): void {
    if (workspace.id !== page.props.workspace?.id) {
        switchTo(workspace);
    }
}

/* The rows, in groups under a heading; Ask AI sits with Home and Activity. */
type Row = {
    key: string;
    label: string;
    icon: Component;
    item?: WorkspaceNavItem;
};

const askRow: Row = {
    key: 'ask',
    label: 'Ask AI',
    icon: SolarAskAi,
};

const toRow = (item: WorkspaceNavItem): Row => ({
    key: item.label,
    label: item.label,
    icon: item.icon,
    item,
});

const ORDER = [
    'Home',
    'Activity',
    'Machines',
    'Services',
    'Projects',
    'Updates',
    'Monitors',
    'Logs',
    'Backups',
    'Workflows',
    'Members',
    'Billing',
    'Partners',
    'Settings',
];

function rank(item: WorkspaceNavItem): number {
    const index = ORDER.findIndex((label) => trans(label) === item.label);

    return index === -1 ? ORDER.length : index;
}

type Group = { key: string; label: string | null; rows: Row[] };

const groups = computed<Group[]>(() => {
    const rowsIn = (group: WorkspaceNavItem['group']): Row[] =>
        items.value
            .filter((item) => item.group === group)
            .sort((a, b) => rank(a) - rank(b))
            .map(toRow);

    return [
        { key: 'start', label: null, rows: [...rowsIn('start'), askRow] },
        { key: 'servers', label: 'Servers', rows: rowsIn('servers') },
        { key: 'operations', label: 'Operations', rows: rowsIn('operations') },
        { key: 'workspace', label: 'Workspace', rows: rowsIn('workspace') },
    ].filter((group) => group.rows.length > 0);
});

const allRows = computed(() => groups.value.flatMap((group) => group.rows));

/*
 * iOS-style highlights: one pill for the current page that springs to the
 * next item as soon as it's clicked, and one soft pill that glides under the
 * pointer. Items are measured relative to the list wrapper.
 */
type Box = { top: number; left: number; width: number; height: number };

const track = useTemplateRef<HTMLElement>('track');
const itemEls = new Map<string, HTMLElement>();
const boxes = ref<Record<string, Box>>({});
const pending = ref<string | null>(null);
const hovered = ref<string | null>(null);
/** The first placement, and re-entry after leaving, jump instead of glide. */
const activeReady = ref(false);
const hoverGlides = ref(false);

function setItemEl(key: string, el: unknown): void {
    if (el instanceof HTMLElement) {
        itemEls.set(key, el);
    } else {
        itemEls.delete(key);
    }
}

function measure(): void {
    const next: Record<string, Box> = {};

    for (const [key, el] of itemEls) {
        next[key] = {
            top: el.offsetTop,
            left: el.offsetLeft,
            width: el.offsetWidth,
            height: el.offsetHeight,
        };
    }

    boxes.value = next;
}

const currentKey = computed(() => {
    if (pending.value) {
        return pending.value;
    }

    if (assistant.open.value) {
        return askRow.key;
    }

    return allRows.value.find((row) => row.item?.active)?.key;
});

function pillStyle(key: string | null | undefined): Record<string, string> {
    const box = key ? boxes.value[key] : undefined;

    if (!box) {
        return { opacity: '0' };
    }

    return {
        width: `${box.width}px`,
        height: `${box.height}px`,
        transform: `translate(${box.left}px, ${box.top}px)`,
    };
}

function enter(key: string): void {
    hoverGlides.value = hovered.value !== null;
    hovered.value = key;
}

function leave(): void {
    hovered.value = null;
}

let observer: ResizeObserver | undefined;
let stopFinish: (() => void) | undefined;

onMounted(async () => {
    await nextTick();
    measure();
    requestAnimationFrame(() => (activeReady.value = true));

    if (track.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(track.value);
    }

    // A cancelled or failed visit puts the pill back on the real page.
    stopFinish = router.on('finish', () => {
        pending.value = null;
    });
});

onBeforeUnmount(() => {
    observer?.disconnect();
    stopFinish?.();
});

watch(
    () => [collapsed, allRows.value.length],
    async () => {
        await nextTick();
        measure();
    },
);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const rowClass =
    'sidebar-item flex h-7.5 w-full items-center gap-2.5 rounded-[7px] text-[14px]/5 transition-colors duration-200';
</script>

<template>
    <div class="flex h-full flex-col py-3 font-ui text-(--ds-text)">
        <div
            v-if="!collapsed"
            class="flex h-8 shrink-0 items-center gap-1 pr-2 pl-2.5"
        >
            <WorkspaceMenu class="flex min-w-0 flex-1" />
            <button
                type="button"
                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) transition-colors duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :class="focusRing"
                :aria-label="$t('Search')"
                :title="$t('Search (⌘K)')"
                aria-haspopup="dialog"
                @click="emit('search')"
            >
                <Search class="mi-tilt size-4" :stroke-width="1.6" />
            </button>
            <button
                v-if="collapsible"
                type="button"
                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) transition-colors duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :class="focusRing"
                :aria-label="$t('Collapse sidebar')"
                aria-expanded="true"
                @click="emit('toggle')"
            >
                <PanelLeft class="mi-panel size-4" :stroke-width="1.6" />
            </button>
        </div>

        <!-- Icons only: the workspace's tile, which turns into expand on hover -->
        <div v-else class="flex h-8 shrink-0 justify-center">
            <button
                type="button"
                class="group flex size-8 cursor-pointer items-center justify-center rounded-[8px] hover:bg-(--ds-hover)"
                :class="focusRing"
                :aria-label="$t('Expand sidebar')"
                aria-expanded="false"
                @click="emit('toggle')"
            >
                <span
                    v-if="page.props.workspace"
                    class="flex size-4.5 items-center justify-center overflow-hidden rounded-[5px] text-[10px]/3 font-semibold group-hover:hidden group-focus-visible:hidden"
                    :class="
                        workspaceTileColor(
                            (switchingTo ?? page.props.workspace).id,
                        )
                    "
                    aria-hidden="true"
                >
                    <img
                        v-if="(switchingTo ?? page.props.workspace).logoUrl"
                        :src="(switchingTo ?? page.props.workspace).logoUrl!"
                        alt=""
                        class="size-full object-cover"
                    />
                    <template v-else>{{
                        workspaceInitial(
                            (switchingTo ?? page.props.workspace).name,
                        )
                    }}</template>
                </span>
                <PanelLeft
                    class="mi-panel mi-panel-open hidden size-4 text-(--ds-text-muted) group-hover:block group-focus-visible:block"
                    :stroke-width="1.6"
                />
            </button>
        </div>

        <nav
            class="min-h-0 grow overflow-y-auto"
            :class="collapsed ? 'mt-3' : 'mt-4'"
            :aria-label="$t('Workspace')"
        >
            <div
                v-if="collapsed"
                class="mx-4 mb-3 border-t border-(--ds-divider)"
                aria-hidden="true"
            />
            <div ref="track" class="relative px-2" @mouseleave="leave">
                <span
                    class="sidebar-pill sidebar-pill--hover"
                    :class="{
                        'sidebar-pill--glide': hoverGlides,
                        'opacity-0': !hovered || hovered === currentKey,
                    }"
                    :style="pillStyle(hovered)"
                    aria-hidden="true"
                />
                <span
                    class="sidebar-pill sidebar-pill--active"
                    :class="{ 'sidebar-pill--spring': activeReady }"
                    :style="pillStyle(currentKey)"
                    aria-hidden="true"
                />

                <template v-for="(group, index) in groups" :key="group.key">
                    <h2
                        v-if="group.label && !collapsed"
                        class="mt-5 mb-1.5 px-2.5 text-[13px]/4 text-(--ds-text-faint)"
                    >
                        {{ $t(group.label) }}
                    </h2>
                    <div
                        v-else-if="index > 0"
                        class="mx-2 my-3 border-t border-(--ds-divider)"
                        aria-hidden="true"
                    />
                    <ul class="flex flex-col gap-px">
                        <li
                            v-for="row in group.rows"
                            :key="row.key"
                            :ref="(el) => setItemEl(row.key, el)"
                        >
                            <button
                                v-if="row.key === 'ask'"
                                type="button"
                                class="cursor-pointer"
                                :class="[
                                    rowClass,
                                    collapsed
                                        ? 'justify-center px-0'
                                        : 'px-2.5',
                                    currentKey === row.key
                                        ? 'text-(--ds-text)'
                                        : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                    focusRing,
                                ]"
                                :aria-expanded="assistant.open.value"
                                aria-controls="assistant-panel"
                                :aria-label="
                                    collapsed ? $t('Ask AI') : undefined
                                "
                                :title="
                                    collapsed ? $t('Ask AI (⌘J)') : undefined
                                "
                                @mouseenter="enter(row.key)"
                                @click="openAssistant"
                            >
                                <component
                                    :is="row.icon"
                                    class="size-4 shrink-0 text-(--ds-text-muted)"
                                    :stroke-width="1.6"
                                />
                                <template v-if="!collapsed">
                                    <span class="truncate">{{
                                        $t(row.label)
                                    }}</span>
                                    <kbd
                                        class="ml-auto font-ui text-[11px]/3.5 text-(--ds-text-faint)"
                                        >⌘J</kbd
                                    >
                                </template>
                            </button>
                            <Link
                                v-else-if="row.item?.href"
                                :href="row.item.href"
                                :class="[
                                    rowClass,
                                    collapsed
                                        ? 'justify-center px-0'
                                        : 'px-2.5',
                                    currentKey === row.key
                                        ? 'text-(--ds-text)'
                                        : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                    focusRing,
                                ]"
                                :aria-current="
                                    row.item.active ? 'page' : undefined
                                "
                                :aria-label="
                                    collapsed ? $t(row.label) : undefined
                                "
                                :title="collapsed ? $t(row.label) : undefined"
                                @mouseenter="enter(row.key)"
                                @click="pending = row.key"
                            >
                                <component
                                    :is="row.icon"
                                    class="size-4 shrink-0 transition-colors duration-200"
                                    :class="
                                        currentKey === row.key
                                            ? 'text-(--ds-text)'
                                            : 'text-(--ds-text-muted)'
                                    "
                                    :stroke-width="1.6"
                                />
                                <span v-if="!collapsed" class="truncate">
                                    {{ $t(row.label) }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </template>
            </div>

            <section
                v-if="pinned.length"
                class="mt-5 px-2"
                aria-labelledby="sidebar-pinned"
            >
                <h2
                    id="sidebar-pinned"
                    class="mb-1.5 px-2.5 text-[13px]/4 text-(--ds-text-faint)"
                    :class="{ 'sr-only': collapsed }"
                >
                    {{ $t('Pinned') }}
                </h2>
                <div
                    v-if="collapsed"
                    class="mx-2 mb-3 border-t border-(--ds-divider)"
                    aria-hidden="true"
                />
                <ul class="flex flex-col gap-px">
                    <li
                        v-for="workspace in pinned"
                        :key="workspace.id"
                        class="group/pin relative"
                    >
                        <a
                            :href="workspace.url"
                            class="hover:bg-(--ds-hover)"
                            :class="[
                                rowClass,
                                collapsed
                                    ? 'justify-center px-0'
                                    : 'pr-8 pl-2.5',
                                workspace.id === page.props.workspace?.id
                                    ? 'text-(--ds-text)'
                                    : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                focusRing,
                            ]"
                            :aria-current="
                                workspace.id === page.props.workspace?.id
                                    ? 'true'
                                    : undefined
                            "
                            :aria-label="collapsed ? workspace.name : undefined"
                            :title="collapsed ? workspace.name : undefined"
                            @click.prevent="openPinned(workspace)"
                        >
                            <span
                                class="flex size-4 shrink-0 items-center justify-center overflow-hidden rounded-[4px] text-[9px]/3 font-semibold"
                                :class="workspaceTileColor(workspace.id)"
                                aria-hidden="true"
                            >
                                <img
                                    v-if="workspace.logoUrl"
                                    :src="workspace.logoUrl"
                                    alt=""
                                    class="size-full object-cover"
                                />
                                <template v-else>{{
                                    workspaceInitial(workspace.name)
                                }}</template>
                            </span>
                            <span v-if="!collapsed" class="truncate">
                                {{ workspace.name }}
                            </span>
                        </a>
                        <button
                            v-if="!collapsed"
                            type="button"
                            class="absolute top-1/2 right-1 flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) opacity-0 group-hover/pin:opacity-100 hover:bg-(--ds-active) hover:text-(--ds-text) focus-visible:opacity-100"
                            :class="focusRing"
                            :aria-label="
                                $t('Unpin :name', {
                                    name: String(workspace.name),
                                })
                            "
                            :title="$t('Unpin')"
                            @click="unpin(workspace)"
                        >
                            <PinOff class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </li>
                </ul>
            </section>
        </nav>

        <div
            class="shrink-0 px-2"
            :class="{ 'flex justify-center': collapsed }"
        >
            <AccountMenu
                :display="collapsed ? 'avatar' : 'full'"
                :side="collapsed ? 'right' : 'top'"
                :align="collapsed ? 'end' : 'start'"
            />
        </div>
    </div>
</template>
