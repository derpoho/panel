<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, PinOff, Plus } from '@lucide/vue';
import {
    SolarPin as Pin,
    SolarCreditCard as CreditCard,
    SolarUserPlus as UserPlus,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import CreateWorkspaceDialog from '@/components/workspace/CreateWorkspaceDialog.vue';
import { usePinnedWorkspaces } from '@/composables/usePinnedWorkspaces';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import { onWorkspace } from '@/lib/workspaceRoute';
import members from '@/routes/workspace/members';
import type { Workspace, WorkspaceSummary } from '@/types';

/**
 * The workspace's tile and name, opening a short menu: every workspace and
 * a new one, then inviting people and billing. Help, theme and logging out
 * are in the account menu.
 */
const page = usePage<{ workspace: Workspace }>();

const current = computed(() => page.props.workspace);
const { isPinned, toggle: togglePin } = usePinnedWorkspaces();
// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

/** Pinned workspaces first, in the order they were pinned, then the rest. */
const workspaces = computed(() => {
    const all = page.props.workspaces;
    const pinned = all
        .filter(isPinned)
        .sort((a, b) =>
            (a.pinnedAt ?? '\uffff').localeCompare(b.pinnedAt ?? '\uffff'),
        );

    return [...pinned, ...all.filter((w) => !isPinned(w))];
});
/** Where the pinned ones end, for the line under them. */
const lastPinned = computed(() => workspaces.value.filter(isPinned).length - 1);

/** The root domain, e.g. serveros.com, taken from the current workspace's host. */
const domain = computed(() =>
    current.value.host.slice(current.value.slug.length + 1),
);

const creating = ref(false);

const { switchingTo, switchTo } = useWorkspaceSwitch();
/** Show where we're heading straight away while the next page loads. */
const shown = computed(() => switchingTo.value ?? current.value);

function onSelect(workspace: WorkspaceSummary): void {
    if (workspace.id !== current.value.id) {
        switchTo(workspace);
    }
}

const row =
    'flex h-8 cursor-pointer items-center gap-2.5 rounded-[7px] px-2 text-[14px]/5 text-(--ds-text-secondary) data-highlighted:text-(--ds-text)';
const icon = 'size-4 shrink-0 text-(--ds-text-muted)';
</script>

<template>
    <div class="min-w-0">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="group flex h-8 max-w-full min-w-0 cursor-pointer items-center gap-2 rounded-[8px] pr-1.5 pl-1.5 text-left transition-colors duration-150 hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                    :aria-label="
                        $t('Switch workspace (current: :name)', {
                            name: String(shown.name),
                        })
                    "
                >
                    <span
                        class="flex size-4.5 shrink-0 items-center justify-center overflow-hidden rounded-[5px] text-[10px]/3 font-semibold"
                        :class="workspaceTileColor(shown.id)"
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
                    <span
                        class="truncate text-[14px]/5 font-medium tracking-[-0.01em] text-(--ds-text)"
                    >
                        {{ switchingTo ? $t('Switching…') : shown.name }}
                    </span>
                    <ChevronsUpDown
                        class="size-3.5 shrink-0 text-(--ds-text-faint) transition-colors group-hover:text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                class="ds-modal w-56 rounded-[12px] p-1.5 font-ui"
                side="bottom"
                align="start"
                :side-offset="6"
            >
                <div
                    v-for="(workspace, index) in workspaces"
                    :key="workspace.id"
                    class="group/row relative"
                    :class="
                        index === lastPinned &&
                        lastPinned < workspaces.length - 1
                            ? 'mb-1 border-b border-(--ds-divider) pb-1'
                            : ''
                    "
                >
                    <DropdownMenuItem as-child>
                        <a
                            :href="workspace.url"
                            :class="[
                                row,
                                'pr-14',
                                workspace.id === current.id
                                    ? 'text-(--ds-text)'
                                    : '',
                            ]"
                            :aria-current="
                                workspace.id === current.id ? 'page' : undefined
                            "
                            :title="workspace.host"
                            @click.prevent="onSelect(workspace)"
                        >
                            <span
                                class="flex size-4.5 shrink-0 items-center justify-center overflow-hidden rounded-[5px] text-[10px]/3 font-semibold"
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
                            <span class="truncate">{{ workspace.name }}</span>
                        </a>
                    </DropdownMenuItem>
                    <div
                        class="pointer-events-none absolute inset-y-0 right-2 flex items-center gap-1"
                    >
                        <button
                            type="button"
                            class="pointer-events-auto flex size-6 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) group-hover/row:opacity-100 hover:bg-(--ds-active) hover:text-(--ds-text) focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :class="
                                isPinned(workspace)
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                            :aria-label="
                                isPinned(workspace)
                                    ? $t('Unpin :name', {
                                          name: String(workspace.name),
                                      })
                                    : $t('Pin :name to the top', {
                                          name: String(workspace.name),
                                      })
                            "
                            :aria-pressed="isPinned(workspace)"
                            :title="
                                isPinned(workspace)
                                    ? $t('Unpin')
                                    : $t('Pin to the top')
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
                            class="size-3.5 shrink-0 text-(--ds-text)"
                            :stroke-width="2"
                        />
                        <span v-else class="size-3.5 shrink-0" />
                    </div>
                </div>

                <DropdownMenuItem
                    :class="[row, 'text-(--ds-text-muted)']"
                    @select="creating = true"
                >
                    <Plus :class="icon" :stroke-width="1.6" />
                    {{ $t('Create workspace') }}
                </DropdownMenuItem>

                <DropdownMenuSeparator class="-mx-1.5 my-1.5" />
                <DropdownMenuItem as-child>
                    <Link
                        :href="onWorkspace(members.index(current.slug))"
                        :class="row"
                    >
                        <UserPlus :class="icon" :stroke-width="1.6" />
                        {{ $t('Invite members') }}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem v-if="upgrade" as-child>
                    <Link :href="upgrade.href" :class="row">
                        <CreditCard :class="icon" :stroke-width="1.6" />
                        {{ upgrade.label }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <CreateWorkspaceDialog v-model:open="creating" :domain="domain" />
    </div>
</template>
