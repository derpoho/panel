<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import EnvironmentBadge from '@/components/machines/EnvironmentBadge.vue';
import type { Machine } from '@/components/machines/machines';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import PlanBadge from '@/components/workspace/PlanBadge.vue';
import SetupGuide from '@/components/workspace/SetupGuide.vue';
import WorkspaceSwitcher from '@/components/workspace/WorkspaceSwitcher.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

/**
 * The bar across the top of the dashboard panel: breadcrumbs on the left,
 * the workspace in the middle until the sidebar shows it, and the setup
 * guide and account menu on the right. Search and collapse live in the
 * sidebar's header.
 */
const page = usePage<{
    workspace: Workspace;
    machine?: Machine;
    service?: { name: string };
    monitor?: { name: string };
    workflow?: { name: string };
    project?: { name: string };
    ticket?: { reference: string };
}>();
const workspace = computed(() => page.props.workspace);

type Crumb = { label: string; href?: string };

const settingsLabels: Record<string, string> = {
    General: trans('General'),
    Members: trans('Members'),
    Profile: trans('Profile'),
    Security: trans('Security'),
    MachinePolicies: trans('Machine policies'),
    StatusPage: trans('Status page'),
    Domains: trans('Domains'),
    LogStorage: trans('Log storage'),
    Mcp: 'AI and MCP',
    Api: 'API',
};

const crumbs = computed<Crumb[]>(() => {
    const component = page.component;
    const slug = workspace.value.slug;

    if (component === 'workspace/MachineShow' && page.props.machine) {
        return [
            {
                label: trans('Machines'),
                href: onWorkspace(machinesRoutes.index(slug)).url,
            },
            { label: page.props.machine.name },
        ];
    }

    if (component === 'workspace/MachineSettings' && page.props.machine) {
        return [
            {
                label: trans('Machines'),
                href: onWorkspace(machinesRoutes.index(slug)).url,
            },
            {
                label: page.props.machine.name,
                href: `/machines/${page.props.machine.id}`,
            },
            { label: trans('Settings') },
        ];
    }

    if (component === 'workspace/ServiceShow' && page.props.machine) {
        return [
            {
                label: trans('Machines'),
                href: onWorkspace(machinesRoutes.index(slug)).url,
            },
            {
                label: page.props.machine.name,
                href: `/machines/${page.props.machine.id}`,
            },
            { label: page.props.service?.name ?? trans('Service') },
        ];
    }

    if (component === 'workspace/ProjectShow' && page.props.project) {
        return [
            { label: trans('Projects'), href: '/projects' },
            { label: page.props.project.name },
        ];
    }

    if (component === 'workspace/WorkflowEditor' && page.props.workflow) {
        return [
            { label: trans('Workflows'), href: '/workflows' },
            { label: page.props.workflow.name },
        ];
    }

    if (component === 'workspace/MonitorShow' && page.props.monitor) {
        return [
            { label: trans('Monitors'), href: '/monitors' },
            { label: page.props.monitor.name },
        ];
    }

    if (component === 'workspace/SupportShow' && page.props.ticket) {
        return [
            { label: trans('Support'), href: '/support' },
            { label: page.props.ticket.reference },
        ];
    }

    if (component === 'workspace/SupportNew') {
        return [
            { label: trans('Support'), href: '/support' },
            { label: trans('New ticket') },
        ];
    }

    if (component.startsWith('workspace/settings/')) {
        const section = component.split('/').pop() ?? '';

        return [
            {
                label: trans('Settings'),
                href: onWorkspace(settingsRoutes.general(slug)).url,
            },
            { label: settingsLabels[section] ?? section },
        ];
    }

    const name = component.split('/').pop() ?? '';

    return [{ label: trans(name) }];
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="relative flex h-13 shrink-0 items-center gap-3 border-b border-(--ds-divider) px-3 sm:px-4"
    >
        <WorkspaceSwitcher compact class="-ml-1 md:hidden" />

        <!-- Stops short of the centred workspace switcher, truncating long names. -->
        <nav
            :aria-label="$t('Breadcrumb')"
            class="min-w-0 md:max-w-[calc(50%-13.5rem)] lg:max-w-none"
        >
            <ol class="flex min-w-0 items-center gap-2 text-[15px]/5">
                <li
                    v-for="(crumb, index) in crumbs"
                    :key="crumb.label"
                    class="flex items-center gap-2"
                    :class="crumb.href ? 'shrink-0' : 'min-w-0'"
                >
                    <ChevronRight
                        v-if="index > 0"
                        class="size-3.5 shrink-0 text-(--ds-text-faint)"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    <Link
                        v-if="crumb.href"
                        :href="crumb.href"
                        class="shrink-0 rounded-sm text-(--ds-text-faint) hover:text-(--ds-text)"
                        :class="focusRing"
                    >
                        {{ crumb.label }}
                    </Link>
                    <span
                        v-else
                        class="truncate font-medium text-(--ds-text)"
                        aria-current="page"
                    >
                        {{ crumb.label }}
                    </span>
                </li>
            </ol>
        </nav>

        <span class="hidden shrink-0 sm:flex">
            <EnvironmentBadge
                v-if="
                    page.component === 'workspace/MachineShow' &&
                    page.props.machine
                "
                :environment="page.props.machine.environment"
                themed
            />
            <PlanBadge
                v-else-if="page.component === 'workspace/Billing'"
                :billing="workspace.billing"
            />
        </span>

        <div
            class="pointer-events-none absolute inset-x-0 hidden justify-center md:flex lg:hidden"
        >
            <WorkspaceSwitcher inline class="pointer-events-auto max-w-72" />
        </div>

        <div class="relative ml-auto flex shrink-0 items-center gap-2">
            <SetupGuide />
            <AccountMenu display="avatar" side="bottom" align="end" />
        </div>
    </div>
</template>
