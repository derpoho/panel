import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { WorkspaceSummary } from '@/types';

/**
 * How long the skeleton shows before navigating. Deliberately slower than
 * the page load so the switch reads as a switch rather than a flicker.
 */
const SKELETON_MS = 900;

/** The workspace being switched to, while the next page loads. */
const switchingTo = ref<WorkspaceSummary | null>(null);

/**
 * Workspaces live on their own subdomains, so switching is a full page
 * load. Show a skeleton, then go through the workspace's switch URL, which
 * flashes the "Switched to" toast and redirects to its home.
 */
export function useWorkspaceSwitch() {
    function switchTo(workspace: WorkspaceSummary): void {
        switchingTo.value = workspace;
        window.setTimeout(
            () => window.location.assign(workspace.switchUrl),
            SKELETON_MS,
        );
    }

    return { switchingTo, switchTo };
}

// Coming back via the back/forward cache restores the page mid-switch.
if (typeof window !== 'undefined') {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            switchingTo.value = null;
        }
    });

    router.on('navigate', () => (switchingTo.value = null));
}
