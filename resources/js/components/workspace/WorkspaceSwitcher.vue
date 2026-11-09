<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, ChevronsUpDown, PinOff, Plus } from '@lucide/vue';
import {
    SolarLayoutGrid as LayoutGrid,
    SolarPin as Pin,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import CreateWorkspaceDialog from '@/components/workspace/CreateWorkspaceDialog.vue';
import { usePinnedWorkspaces } from '@/composables/usePinnedWorkspaces';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { planSummary } from '@/lib/workspacePlan';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import { index as workspacesIndex } from '@/routes/workspaces';
import type { Workspace, WorkspaceSummary } from '@/types';

type Props = {
    /** Show only the workspace tile, for the icon-only sidebar. */
    compact?: boolean;
    /** One line, logo and name, for the dashboard top bar. */
    inline?: boolean;
};

const { compact = false, inline = false } = defineProps<Props>();

const page = usePage<{ workspace: Workspace }>();

const current = computed(() => page.props.workspace);
const workspaces = computed(() => page.props.workspaces);

const summary = computed(() => planSummary(current.value.billing));

/** The root domain, e.g. serveros.com, taken from the current workspace's host. */
const domain = computed(() =>
    current.value.host.slice(current.value.slug.length + 1),
);

/** The workspaces list lives on the root domain, not this subdomain. */
const allWorkspacesUrl = computed(
    () => new URL(workspacesIndex.url(), page.props.appUrl).href,
);

const creating = ref(false);

const { switchingTo, switchTo } = useWorkspaceSwitch();
const { isPinned, toggle: togglePin } = usePinnedWorkspaces();

/** Show where we're heading straight away while the next page loads. */
const shown = computed(() => switchingTo.value ?? current.value);

function onSelect(workspace: WorkspaceSummary): void {
    if (workspace.id !== current.value.id) {
        switchTo(workspace);
    }
}
</script>

<template>
    <div class="min-w-0">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex cursor-pointer items-center text-left hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                    :class="
                        compact
                            ? 'size-10 justify-center rounded-[10px]'
                            : inline
                              ? 'group h-8 max-w-full gap-2 rounded-lg px-2'
                              : 'w-full gap-2.5 rounded-[10px] p-1.5'
                    "
                    :aria-label="
                        $t('Switch workspace (current: :name)', {
                            name: String(shown.name),
                        })
                    "
                >
                    <span
                        class="flex shrink-0 items-center justify-center overflow-hidden font-semibold"
                        :class="[
                            workspaceTileColor(shown.id),
                            inline
                                ? 'size-5 rounded-[5px] text-[10px]/3'
                                : 'size-6.5 rounded-md text-xs/4',
                        ]"
                        aria-hidden="true"
                    >
                        <img
                            v-if="shown.logoUrl"
                            :src="shown.logoUrl"
                            alt=""
                            class="size-full object-cover"
                        />
                        <template v-else>{{
                            workspaceInitial(shown.name)
                        }}</template>
                    </span>
                    <template v-if="inline">
                        <span
                            class="truncate text-[15px]/5 font-medium text-(--ds-text)"
                        >
                            {{ switchingTo ? $t('Switching…') : shown.name }}
                        </span>
                        <ChevronDown
                            class="mi-drop size-3.5 shrink-0 text-(--ds-text-faint) group-data-[state=open]:rotate-180"
                            :stroke-width="1.75"
                        />
                    </template>
                    <template v-else-if="!compact">
                        <span class="flex min-w-0 grow flex-col">
                            <span
                                class="truncate text-[13px]/4 font-medium text-(--ds-text)"
                            >
                                {{ shown.name }}
                            </span>
                            <span
                                v-if="!switchingTo"
                                class="truncate text-xs/4"
                                :class="{
                                    'text-(--ds-text-muted)':
                                        summary.tone === 'neutral',
                                    'text-(--ds-blue-strong)/80':
                                        summary.tone === 'trial',
                                    'text-(--ds-amber)/80':
                                        summary.tone === 'none',
                                }"
                            >
                                {{ summary.label }}
                            </span>
                            <span
                                v-else
                                class="truncate text-xs/4 text-(--ds-text-muted)"
                            >
                                {{ ' ' + $t('Switching…') + ' ' }}
                            </span>
                        </span>
                        <ChevronsUpDown
                            class="size-3.5 shrink-0 text-(--ds-text-subtle)"
                            :stroke-width="1.6"
                        />
                    </template>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                class="ds-modal w-64 font-ui"
                :side="compact ? 'right' : 'bottom'"
                :align="inline ? 'center' : 'start'"
                :side-offset="6"
            >
                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('Workspaces') + ' ' }}
                </DropdownMenuLabel>
                <div
                    v-for="workspace in workspaces"
                    :key="workspace.id"
                    class="group/row relative"
                >
                    <DropdownMenuItem as-child>
                        <a
                            :href="workspace.url"
                            @click.prevent="onSelect(workspace)"
                            class="flex cursor-pointer items-center gap-2.5 pr-14"
                            :aria-current="
                                workspace.id === current.id ? 'page' : undefined
                            "
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-md text-xs/4 font-semibold"
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
                            <span class="flex min-w-0 grow flex-col">
                                <span class="truncate text-sm">
                                    {{ workspace.name }}
                                </span>
                                <span
                                    class="truncate text-xs text-(--ds-text-muted)"
                                >
                                    {{ workspace.host }}
                                </span>
                            </span>
                        </a>
                    </DropdownMenuItem>
                    <div
                        class="pointer-events-none absolute inset-y-0 right-2 flex items-center gap-1"
                    >
                        <button
                            type="button"
                            class="pointer-events-auto flex size-6 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :class="
                                isPinned(workspace)
                                    ? 'text-(--ds-text-muted)'
                                    : 'opacity-0 group-hover/row:opacity-100'
                            "
                            :aria-label="
                                isPinned(workspace)
                                    ? $t('Unpin :name from the sidebar', {
                                          name: String(workspace.name),
                                      })
                                    : $t('Pin :name to the sidebar', {
                                          name: String(workspace.name),
                                      })
                            "
                            :aria-pressed="isPinned(workspace)"
                            :title="
                                isPinned(workspace)
                                    ? $t('Unpin from sidebar')
                                    : $t('Pin to sidebar')
                            "
                            @click.stop.prevent="togglePin(workspace)"
                        >
                            <PinOff
                                v-if="isPinned(workspace)"
                                class="hidden size-3.5 group-hover/row:block"
                                :stroke-width="1.75"
                            />
                            <Pin
                                class="size-3.5"
                                :class="{
                                    'fill-current group-hover/row:hidden':
                                        isPinned(workspace),
                                }"
                                :stroke-width="1.75"
                            />
                        </button>
                        <Check
                            v-if="workspace.id === current.id"
                            class="size-3.5 shrink-0"
                        />
                        <span v-else class="size-3.5 shrink-0" />
                    </div>
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <a :href="allWorkspacesUrl" class="cursor-pointer">
                        <LayoutGrid class="size-4" :stroke-width="1.75" />
                        {{ $t('All workspaces') + ' ' }}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem
                    class="cursor-pointer"
                    @select="creating = true"
                >
                    <Plus class="size-4" :stroke-width="1.75" />
                    {{ $t('Create workspace') + ' ' }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <CreateWorkspaceDialog v-model:open="creating" :domain="domain" />
    </div>
</template>
