import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import pins from '@/routes/workspace/pins';
import type { Workspace, WorkspaceSummary } from '@/types';

/** Ids flipped locally while the request is in flight. */
const flipped = ref(new Set<number>());

/**
 * Workspaces pinned to the sidebar, oldest pin first. Pins are kept per
 * user on the server: each workspace has its own subdomain, so browser
 * storage wouldn't follow you from one to the next.
 */
export function usePinnedWorkspaces() {
    const page = usePage<{ workspace?: Workspace }>();

    function isPinned(workspace: WorkspaceSummary): boolean {
        return (
            (workspace.pinnedAt !== null) !== flipped.value.has(workspace.id)
        );
    }

    const pinned = computed(() =>
        page.props.workspaces
            .filter(isPinned)
            .sort((a, b) =>
                (a.pinnedAt ?? '￿').localeCompare(b.pinnedAt ?? '￿'),
            ),
    );

    function toggle(workspace: WorkspaceSummary): void {
        const current = page.props.workspace?.slug;

        if (!current) {
            return;
        }

        const route = isPinned(workspace)
            ? pins.destroy({ workspace: current, pinned: workspace.slug })
            : pins.store({ workspace: current, pinned: workspace.slug });

        flipped.value = new Set(flipped.value).add(workspace.id);

        router.visit(onWorkspace(route).url, {
            method: route.method,
            preserveScroll: true,
            preserveState: true,
            only: ['workspaces'],
            onFinish: () => {
                const next = new Set(flipped.value);
                next.delete(workspace.id);
                flipped.value = next;
            },
        });
    }

    return { pinned, isPinned, toggle };
}
