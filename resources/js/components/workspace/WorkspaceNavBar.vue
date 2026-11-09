<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, Search } from '@lucide/vue';
import {
    SolarMessagesSquare as MessagesSquare,
    SolarSparkles,
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
import EnvironmentBadge from '@/components/machines/EnvironmentBadge.vue';
import type { Machine } from '@/components/machines/machines';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import SetupGuide from '@/components/workspace/SetupGuide.vue';
import WorkspaceMenu from '@/components/workspace/WorkspaceMenu.vue';
import { useWorkspaceNav } from '@/components/workspace/workspaceNav';
import { useAssistant } from '@/composables/useAssistant';
import type { Workspace } from '@/types';

/**
 * The dashboard's top: the workspace and where you are inside it on the
 * first row, with Ask AI, search and the account on the right; the
 * workspace's sections as tabs on the second, underlined where you are.
 *
 * With the sidebar layout, from lg up the sidebar has the workspace, the
 * sections, Ask AI, search and the account, so this keeps the section
 * you're in and where you are inside it.
 */
const { sidebar = false } = defineProps<{ sidebar?: boolean }>();

const emit = defineEmits<{
    search: [];
    menu: [];
}>();

const page = usePage<{
    workspace: Workspace;
    machine?: Machine;
    service?: { name: string };
    monitor?: { name: string };
    workflow?: { name: string };
    ticket?: { reference: string };
}>();
const items = useWorkspaceNav();
/* Fixes isn't a tab, to keep the tabs uncrowded: it sits beside Ask AI. */
const fixes = computed(() =>
    items.value.find((item) => item.label === trans('Fixes')),
);
const assistant = useAssistant();

/* Sections as tabs: Members counts as Settings, the rest are in menus. */
const tabOrder = [
    'Home',
    'Machines',
    'Updates',
    'Services',
    'Projects',
    // Only there once Cloud is switched on (see workspaceNav).
    'Cloud',
    'Workflows',
    'Backups',
    'Monitors',
    'Logs',
    'Activity',
    'Settings',
];

const tabs = computed(() =>
    tabOrder
        .map((label) => items.value.find((item) => item.label === trans(label)))
        .filter((item) => item !== undefined)
        .map((item) => ({
            key: item.label,
            label:
                item.label === trans('Home') ? trans('Overview') : item.label,
            href: item.href,
            active:
                item.active ||
                (item.label === trans('Settings') &&
                    page.component === 'workspace/settings/Members'),
        })),
);

/** The section you're in, named on the left when there are no tabs. */
const section = computed(
    () => tabs.value.find((tab) => tab.active)?.label ?? null,
);

/** Hidden from lg up with the sidebar layout, which has these itself. */
const inSidebar = computed(() => (sidebar ? 'lg:hidden' : ''));

/** Past the section the tab already names: the machine, the service. */
type Crumb = { label: string; href?: string };

const crumbs = computed<Crumb[]>(() => {
    const { component, props } = page;

    if (
        [
            'workspace/MachineShow',
            'workspace/MachineSettings',
            'workspace/ServiceShow',
        ].includes(component) &&
        props.machine
    ) {
        const machine = { label: props.machine.name };

        if (component === 'workspace/MachineShow') {
            return [machine];
        }

        return [
            { ...machine, href: `/machines/${props.machine.id}` },
            {
                label:
                    component === 'workspace/MachineSettings'
                        ? trans('Settings')
                        : (props.service?.name ?? trans('Service')),
            },
        ];
    }

    if (component === 'workspace/WorkflowEditor' && props.workflow) {
        return [{ label: props.workflow.name }];
    }

    if (component === 'workspace/MonitorShow' && props.monitor) {
        return [{ label: props.monitor.name }];
    }

    if (component === 'workspace/SupportShow' && props.ticket) {
        return [
            { label: trans('Support'), href: '/support' },
            { label: props.ticket.reference },
        ];
    }

    if (component === 'workspace/SupportNew') {
        return [
            { label: trans('Support'), href: '/support' },
            { label: trans('New ticket') },
        ];
    }

    if (component === 'workspace/Billing') {
        return [{ label: trans('Billing') }];
    }

    if (component === 'workspace/Partners') {
        return [{ label: trans('Partners') }];
    }

    if (component.startsWith('workspace/Support')) {
        return [{ label: trans('Support') }];
    }

    return [];
});

/*
 * The underline: measured from the tab it sits under, so it slides to the
 * next one as soon as it's clicked rather than when the page arrives.
 */
const strip = useTemplateRef<HTMLElement>('strip');
const tabEls = new Map<string, HTMLElement>();
const pending = ref<string | null>(null);
const line = ref<{ left: number; width: number } | null>(null);
const lineReady = ref(false);

function setTabEl(key: string, ref: unknown): void {
    // Link hands back its component; the anchor is its root element.
    const el = ref && typeof ref === 'object' && '$el' in ref ? ref.$el : ref;

    if (el instanceof HTMLElement) {
        tabEls.set(key, el);
    } else {
        tabEls.delete(key);
    }
}

const currentTab = computed(
    () => pending.value ?? tabs.value.find((tab) => tab.active)?.key ?? null,
);

function measure(): void {
    const el = currentTab.value ? tabEls.get(currentTab.value) : undefined;
    line.value = el ? { left: el.offsetLeft, width: el.offsetWidth } : null;
}

watch(currentTab, async () => {
    await nextTick();
    measure();
    tabEls.get(currentTab.value ?? '')?.scrollIntoView({
        block: 'nearest',
        inline: 'nearest',
    });
});

let observer: ResizeObserver | undefined;
let stopFinish: (() => void) | undefined;

onMounted(async () => {
    await nextTick();
    measure();
    requestAnimationFrame(() => (lineReady.value = true));

    if (strip.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(strip.value);
    }

    // A cancelled or failed visit puts the line back under the real page.
    stopFinish = router.on('finish', () => (pending.value = null));
});

onBeforeUnmount(() => {
    observer?.disconnect();
    stopFinish?.();
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <header class="shrink-0 font-ui text-(--ds-text)">
        <!-- Where you are, and the things you reach for from anywhere -->
        <div class="flex h-14 items-center gap-2 px-3 sm:px-5">
            <a
                :href="page.props.appUrl"
                class="mr-1 flex size-8 shrink-0 items-center justify-center rounded-[8px]"
                :class="[focusRing, inSidebar]"
                aria-label="ServerOS"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-4.5 object-contain invert dark:invert-0"
                />
            </a>
            <span
                class="shrink-0 text-[15px] text-(--ds-text-faint)/70 select-none"
                :class="inSidebar"
                aria-hidden="true"
                >/</span
            >
            <WorkspaceMenu class="min-w-0 shrink" :class="inSidebar" />
            <span
                v-if="sidebar && section"
                class="hidden truncate text-[14px]/5 font-medium lg:block"
                :aria-current="crumbs.length ? undefined : 'page'"
                >{{ section }}</span
            >

            <nav
                v-if="crumbs.length"
                :aria-label="$t('Breadcrumb')"
                class="hidden min-w-0 sm:block"
            >
                <ol class="flex min-w-0 items-center gap-2 text-[14px]/5">
                    <li
                        v-for="(crumb, index) in crumbs"
                        :key="crumb.label"
                        class="flex min-w-0 items-center gap-2"
                    >
                        <span
                            class="text-[15px] text-(--ds-text-faint)/70 select-none"
                            :class="{
                                'lg:hidden': sidebar && !section && index === 0,
                            }"
                            aria-hidden="true"
                            >/</span
                        >
                        <Link
                            v-if="crumb.href"
                            :href="crumb.href"
                            class="truncate rounded-sm text-(--ds-text-muted) hover:text-(--ds-text)"
                            :class="focusRing"
                        >
                            {{ crumb.label }}
                        </Link>
                        <span
                            v-else
                            class="truncate font-medium"
                            :aria-current="
                                index === crumbs.length - 1 ? 'page' : undefined
                            "
                        >
                            {{ crumb.label }}
                        </span>
                    </li>
                </ol>
            </nav>
            <EnvironmentBadge
                v-if="
                    page.component === 'workspace/MachineShow' &&
                    page.props.machine
                "
                class="hidden shrink-0 sm:inline-flex"
                :environment="page.props.machine.environment"
                themed
            />

            <div class="ml-auto flex shrink-0 items-center gap-1.5">
                <SetupGuide />
                <button
                    type="button"
                    class="hidden h-8 cursor-pointer items-center gap-1.5 rounded-[8px] px-2.5 text-[13.5px]/5 text-(--ds-text-secondary) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) md:inline-flex"
                    :class="[
                        focusRing,
                        inSidebar,
                        assistant.open.value
                            ? 'bg-(--ds-hover) text-(--ds-text)'
                            : '',
                    ]"
                    :aria-expanded="assistant.open.value"
                    aria-controls="assistant-panel"
                    @click="assistant.toggle()"
                >
                    <MessagesSquare
                        class="size-4 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    {{ $t('Ask AI') }}
                </button>
                <Link
                    v-if="fixes?.href"
                    :href="fixes.href"
                    class="hidden h-8 items-center gap-1.5 rounded-[8px] px-2.5 text-[13.5px]/5 text-(--ds-text-secondary) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) md:inline-flex"
                    :class="[
                        focusRing,
                        inSidebar,
                        fixes.active ? 'bg-(--ds-hover) text-(--ds-text)' : '',
                    ]"
                    :aria-current="fixes.active ? 'page' : undefined"
                >
                    <SolarSparkles
                        class="size-4 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    {{ $t('Fixes') }}
                </Link>
                <button
                    type="button"
                    class="hidden h-8 w-52 cursor-pointer items-center gap-2 rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-2.5 text-left text-[13.5px]/5 text-(--ds-text-faint) transition-colors hover:border-(--ds-border-strong,var(--ds-border)) hover:text-(--ds-text-muted) md:flex"
                    :class="[focusRing, inSidebar]"
                    aria-haspopup="dialog"
                    @click="emit('search')"
                >
                    <Search class="mi-tilt size-3.5" :stroke-width="1.75" />
                    {{ $t('Search') }}
                    <kbd class="ml-auto font-ui text-[11px]/3.5">⌘K</kbd>
                </button>
                <button
                    type="button"
                    class="flex size-9 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) md:hidden"
                    :class="focusRing"
                    :aria-label="$t('Search')"
                    aria-haspopup="dialog"
                    @click="emit('search')"
                >
                    <Search class="mi-tilt size-4.5" :stroke-width="1.6" />
                </button>
                <span class="contents" :class="sidebar ? 'lg:*:hidden' : ''">
                    <AccountMenu
                        class="ml-1"
                        display="avatar"
                        side="bottom"
                        align="end"
                    />
                </span>
                <button
                    type="button"
                    class="-mr-1 flex size-9 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) lg:hidden"
                    :class="focusRing"
                    :aria-label="$t('Open navigation')"
                    @click="emit('menu')"
                >
                    <Menu class="size-5" :stroke-width="1.6" />
                </button>
            </div>
        </div>

        <!-- The sections, underlined where you are -->
        <nav
            class="[scrollbar-width:none] overflow-x-auto [&::-webkit-scrollbar]:hidden"
            :class="inSidebar"
            :aria-label="$t('Workspace')"
        >
            <div
                ref="strip"
                class="relative flex w-max items-stretch gap-1 px-3 sm:px-5"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.key"
                    :ref="(el) => setTabEl(tab.key, el)"
                    :href="tab.href!"
                    class="group relative flex h-10 items-center px-2.5 text-[14px]/5 whitespace-nowrap transition-colors duration-150"
                    :class="[
                        tab.key === currentTab
                            ? 'text-(--ds-text)'
                            : 'text-(--ds-text-muted) hover:text-(--ds-text)',
                        focusRing,
                    ]"
                    :aria-current="tab.active ? 'page' : undefined"
                    @click="pending = tab.key"
                >
                    <span
                        class="absolute inset-x-0 inset-y-1.5 -z-0 rounded-[7px] opacity-0 transition-opacity duration-150 group-hover:bg-(--ds-hover) group-hover:opacity-100"
                        aria-hidden="true"
                    />
                    <span class="relative">{{ tab.label }}</span>
                </Link>
                <span
                    v-if="line"
                    class="pointer-events-none absolute bottom-0 left-0 h-0.5 rounded-full bg-(--ds-text)"
                    :class="{
                        'transition-[translate,width] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none':
                            lineReady,
                    }"
                    :style="{
                        width: `${line.width}px`,
                        translate: `${line.left}px 0`,
                    }"
                    aria-hidden="true"
                />
            </div>
        </nav>
    </header>
</template>
