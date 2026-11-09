<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { SolarSquarePlus as SquarePlus } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';
import CreateWorkspaceDialog from '@/components/workspace/CreateWorkspaceDialog.vue';
import PlanBadge from '@/components/workspace/PlanBadge.vue';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import type { Workspace } from '@/types';

type WorkspaceCard = Workspace & {
    url: string;
    switchUrl: string;
    role: string | null;
    machineCount: number;
    memberCount: number;
};

defineProps<{
    workspaces: WorkspaceCard[];
}>();

const page = usePage();

/** The root domain workspaces live under, e.g. serveros.com. */
const domain = computed(() => new URL(page.props.appUrl).host);

const creating = ref(false);

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}
</script>

<template>
    <Head :title="$t('Workspaces')" />

    <div class="flex min-h-full flex-col pb-10 font-ui text-(--ds-text)">
        <header class="flex items-center gap-2.5 px-4 pt-4.5 pb-4 sm:px-6">
            <h1 class="text-lg/6 font-semibold">{{ $t('Your workspaces') }}</h1>
            <span
                v-if="workspaces.length"
                class="rounded-md bg-(--ds-hover) px-1.75 py-px text-xs/4.5 font-medium text-(--ds-text-secondary)"
            >
                {{ workspaces.length }}
            </span>
        </header>

        <div class="px-4 sm:px-6">
            <div
                v-if="workspaces.length === 0"
                class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-(--ds-border-strong) px-6 py-16 text-center"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-[10px] border border-(--ds-border-strong) bg-(--ds-frame)"
                    aria-hidden="true"
                >
                    <SquarePlus
                        class="size-4.5 text-(--ds-text-secondary)"
                        :stroke-width="1.6"
                    />
                </span>
                <div class="flex flex-col items-center gap-1">
                    <h2 class="text-[15px]/5 font-semibold">
                        {{ ' ' + $t('Create a workspace') + ' ' }}
                    </h2>
                    <p
                        class="max-w-160 text-[13px]/5 text-balance text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Keep machines, people and billing in one place.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>
                <AppButton variant="primary" size="sm" @click="creating = true">
                    <Plus class="size-3.5" :stroke-width="2" />
                    {{ $t('New workspace') + ' ' }}
                </AppButton>
            </div>

            <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="workspace in workspaces" :key="workspace.id">
                    <a
                        :href="workspace.switchUrl"
                        class="flex h-full min-h-36 flex-col justify-between gap-6 rounded-[10px] border border-(--ds-border) bg-(--ds-frame) p-4 transition-colors hover:border-(--ds-border-hover) hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                    >
                        <span class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg text-sm/5 font-semibold"
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
                                <span
                                    class="truncate text-[15px]/5 font-medium"
                                >
                                    {{ workspace.name }}
                                </span>
                                <span
                                    class="truncate text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{ workspace.host }}
                                </span>
                            </span>
                            <PlanBadge
                                :billing="workspace.billing"
                                class="mt-1"
                            />
                        </span>

                        <span
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs/4 text-(--ds-text-secondary)"
                        >
                            <template v-if="workspace.role">
                                <span>{{ workspace.role }}</span>
                                <span
                                    class="text-(--ds-text-muted)"
                                    aria-hidden="true"
                                >
                                    ·
                                </span>
                            </template>
                            <span>{{
                                plural(workspace.machineCount, 'machine')
                            }}</span>
                            <span
                                class="text-(--ds-text-muted)"
                                aria-hidden="true"
                            >
                                ·
                            </span>
                            <span>{{
                                plural(workspace.memberCount, 'member')
                            }}</span>
                        </span>
                    </a>
                </li>

                <li>
                    <div
                        class="flex h-full min-h-36 flex-col items-center justify-center gap-3 rounded-[10px] border border-dashed border-(--ds-border-strong) p-4 text-center"
                    >
                        <div class="flex flex-col items-center gap-1">
                            <h2 class="text-[15px]/5 font-semibold">
                                {{ ' ' + $t('Create a workspace') + ' ' }}
                            </h2>
                            <p class="text-[13px]/5 text-(--ds-text-muted)">
                                {{
                                    ' ' +
                                    $t('One per client, team or company.') +
                                    ' '
                                }}
                            </p>
                        </div>
                        <AppButton size="sm" @click="creating = true">
                            <Plus class="size-3.5" :stroke-width="2" />
                            {{ $t('New workspace') + ' ' }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </div>

        <CreateWorkspaceDialog v-model:open="creating" :domain="domain" />
    </div>
</template>
