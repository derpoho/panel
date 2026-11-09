import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
import {
    SolarActivity,
    SolarBackups,
    SolarCircleArrowUp,
    SolarHome,
    SolarLayoutGrid,
    SolarLogs,
    SolarMachines,
    SolarMembers,
    SolarMonitors,
    SolarServices,
    SolarSettings,
    SolarWorkflows,
    SolarSparkles,
} from '@/lib/solarIcons';
import { extensionIcon } from '@/lib/extensionIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import { home } from '@/routes/workspace';
import backups from '@/routes/workspace/backups';
import activity from '@/routes/workspace/activity';
import logs from '@/routes/workspace/logs';
import machines from '@/routes/workspace/machines';
import monitors from '@/routes/workspace/monitors';
import projects from '@/routes/workspace/projects';
import services from '@/routes/workspace/services';
import updates from '@/routes/workspace/updates';
import fixes from '@/routes/workspace/fixes';
import workflows from '@/routes/workspace/workflows';
import members from '@/routes/workspace/members';
import settings from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

/* The sidebar's icons: Solar duotone, like the rest of the dashboard. */
const icons = {
    House: SolarHome,
    Server: SolarMachines,
    PackageCheck: SolarCircleArrowUp,
    Layers: SolarServices,
    Projects: SolarLayoutGrid,
    Workflow: SolarWorkflows,
    Archive: SolarBackups,
    Activity: SolarMonitors,
    ScrollText: SolarLogs,
    Users: SolarMembers,
    History: SolarActivity,
    Settings: SolarSettings,
};

export type WorkspaceNavItem = {
    /** Stable, so a package's section can go after one (App\Support\Extensions\NavItem). */
    key: string;
    label: string;
    icon: Component;
    /** Null for sections that aren't built yet. */
    href: NonNullable<InertiaLinkProps['href']> | null;
    active: boolean;
    /** The sidebar group it's listed under. */
    group: WorkspaceNavGroup;
};

/**
 * The sidebar's groups: start (Home, Activity), servers, operations and
 * workspace, each under its heading. menu: in the workspace menu instead.
 */
export type WorkspaceNavGroup =
    | 'start'
    | 'servers'
    | 'operations'
    | 'workspace'
    | 'menu';

/**
 * The workspace's sections, for the sidebar and the mobile menu. Hrefs are
 * paths (via onWorkspace) so SSR and the browser render the same links.
 */
export function useWorkspaceNav(): ComputedRef<WorkspaceNavItem[]> {
    const page = usePage<{
        workspace?: Workspace;
        isPartner?: boolean;
        extensions?: { nav: ExtensionNavItem[] } | null;
    }>();

    return computed(() => {
        const slug = page.props.workspace?.slug;

        if (!slug) {
            return [];
        }

        const items: WorkspaceNavItem[] = [
            {
                key: 'home',
                label: trans('Home'),
                icon: icons.House,
                href: onWorkspace(home(slug)),
                active: page.component === 'workspace/Home',
                group: 'start',
            },
            {
                key: 'machines',
                label: trans('Machines'),
                icon: icons.Server,
                href: onWorkspace(machines.index(slug)),
                active: [
                    'workspace/Machines',
                    'workspace/MachineShow',
                ].includes(page.component),
                group: 'servers',
            },
            {
                key: 'updates',
                label: trans('Updates'),
                icon: icons.PackageCheck,
                href: onWorkspace(updates.index(slug)),
                active: page.component === 'workspace/Updates',
                group: 'servers',
            },
            {
                key: 'services',
                label: trans('Services'),
                icon: icons.Layers,
                href: onWorkspace(services.index(slug)),
                active: [
                    'workspace/Services',
                    'workspace/ServiceShow',
                ].includes(page.component),
                group: 'servers',
            },
            {
                key: 'projects',
                label: trans('Projects'),
                icon: icons.Projects,
                href: onWorkspace(projects.index(slug)),
                active: [
                    'workspace/Projects',
                    'workspace/ProjectShow',
                ].includes(page.component),
                group: 'servers',
            },
            {
                key: 'workflows',
                label: trans('Workflows'),
                icon: icons.Workflow,
                href: onWorkspace(workflows.index(slug)),
                active: [
                    'workspace/Workflows',
                    'workspace/WorkflowEditor',
                ].includes(page.component),
                group: 'operations',
            },
            {
                key: 'fixes',
                label: trans('Fixes'),
                icon: SolarSparkles,
                href: onWorkspace(fixes.index(slug)),
                active: page.component === 'workspace/Fixes',
                group: 'operations',
            },
            {
                key: 'backups',
                label: trans('Backups'),
                icon: icons.Archive,
                href: onWorkspace(backups.index(slug)),
                active: page.component === 'workspace/Backups',
                group: 'operations',
            },
            {
                key: 'monitors',
                label: trans('Monitors'),
                icon: icons.Activity,
                href: onWorkspace(monitors.index(slug)),
                active: [
                    'workspace/Monitors',
                    'workspace/MonitorShow',
                ].includes(page.component),
                group: 'operations',
            },
            {
                key: 'logs',
                label: trans('Logs'),
                icon: icons.ScrollText,
                href: onWorkspace(logs.index(slug)),
                active: page.component === 'workspace/Logs',
                group: 'operations',
            },
            {
                key: 'members',
                label: trans('Members'),
                icon: icons.Users,
                href: onWorkspace(members.index(slug)),
                active: page.component === 'workspace/settings/Members',
                group: 'workspace',
            },
            {
                key: 'activity',
                label: trans('Activity'),
                icon: icons.History,
                href: onWorkspace(activity.index(slug)),
                active: page.component === 'workspace/Activity',
                group: 'start',
            },
            {
                key: 'settings',
                label: trans('Settings'),
                icon: icons.Settings,
                href: onWorkspace(settings.general(slug)),
                active:
                    page.component.startsWith('workspace/settings/') &&
                    page.component !== 'workspace/settings/Members',
                group: 'workspace',
            },
        ];

        return withExtensions(
            items,
            page.props.extensions?.nav ?? [],
            page.component,
        );
    });
}

/** A section a private package adds (App\Support\Extensions\NavItem). */
type ExtensionNavItem = {
    key: string;
    label: string;
    icon: string;
    path: string;
    group: WorkspaceNavGroup;
    components: string[];
    /** The key of the section it goes after; null for the end of its group. */
    after: string | null;
    /** The key of the section it goes before; wins over after when it's there. */
    before?: string | null;
};

/**
 * The sections with the packages' added in: before or after the one they
 * name, or last in their group. Their labels arrive translated.
 */
function withExtensions(
    items: WorkspaceNavItem[],
    extensions: ExtensionNavItem[],
    component: string,
): WorkspaceNavItem[] {
    const result = [...items];

    for (const extension of extensions) {
        const item: WorkspaceNavItem = {
            key: extension.key,
            label: extension.label,
            icon: extensionIcon(extension.icon),
            href: extension.path,
            active: extension.components.includes(component),
            group: extension.group,
        };
        const before = extension.before
            ? result.findIndex((existing) => existing.key === extension.before)
            : -1;
        if (before !== -1) {
            result.splice(before, 0, item);
            continue;
        }

        const after = extension.after
            ? result.findIndex((existing) => existing.key === extension.after)
            : result.findLastIndex((existing) => existing.group === item.group);

        result.splice(after === -1 ? result.length : after + 1, 0, item);
    }

    return result;
}
