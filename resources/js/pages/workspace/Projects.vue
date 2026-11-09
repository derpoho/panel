<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { appButtonClasses } from '@/lib/appButton';
import { docsUrl } from '@/lib/docs';
import { SolarLayoutGrid as ProjectIcon } from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import type { Workspace, WorkspaceAccess } from '@/types';

type ProjectRow = {
    uid: string;
    name: string;
    apps: number;
    resources: number;
    machines: number;
    links: number;
    network: 'none' | 'pending' | 'up' | 'failed';
    createdAt: string | null;
};

defineProps<{
    workspace: Workspace;
    projects: ProjectRow[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const creating = ref(false);
const name = ref('');
const saving = ref(false);

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value
    ) {
        creating.value = true;
    }
});

function create(): void {
    if (!name.value.trim()) return;
    saving.value = true;
    router.post(
        '/projects',
        { name: name.value },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (saving.value = false),
        },
    );
}

const networkLabel: Record<ProjectRow['network'], string> = {
    none: 'Nothing on it yet',
    pending: 'Joining',
    up: 'Connected',
    failed: 'Needs a look',
};
const networkTone: Record<ProjectRow['network'], string> = {
    none: 'bg-(--ds-text-faint)',
    pending: 'bg-(--ds-blue)',
    up: 'bg-(--ds-green)',
    failed: 'bg-(--ds-red)',
};

const columns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 md:grid-cols-[minmax(0,1.6fr)_7rem_7rem_7rem_9rem]';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Projects')" />

    <WorkspacePage :title="$t('Projects')">
        <template #description>
            {{
                ' ' +
                $t(
                    'An app and what it uses, like its database, cache and storage, on one machine or several. ServerOS joins them on a private network and hands the app its connection settings.',
                ) +
                ' '
            }}
            <a
                :href="docsUrl($page.props.docsUrl, '/guides/projects')"
                target="_blank"
                rel="noopener"
                class="font-medium text-(--ds-blue) hover:underline hover:underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                >{{ ' ' + $t('Learn more') + ' ' }}</a
            >
        </template>

        <template v-if="canManage && projects.length" #actions>
            <button
                type="button"
                :class="appButtonClasses('primary')"
                @click="creating = true"
            >
                <Plus class="size-3.5" /> {{ $t('New project') + ' ' }}
            </button>
        </template>

        <div
            v-if="!projects.length"
            class="ds-tile flex flex-col items-center px-6 pt-12 pb-10 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <ProjectIcon class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ ' ' + $t('Put an app and its services together') + ' ' }}
            </h2>
            <p
                class="mt-1.5 max-w-md text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Add an app you’ve deployed, then a database, a cache or storage. They can sit on different machines and still reach each other privately.',
                    ) +
                    ' '
                }}
            </p>
            <button
                v-if="canManage"
                type="button"
                class="mt-6"
                :class="appButtonClasses('primary')"
                @click="creating = true"
            >
                <Plus class="size-3.5" /> {{ $t('New project') + ' ' }}
            </button>
        </div>

        <div
            v-else
            class="ds-tile overflow-x-auto"
            role="table"
            :aria-label="$t('Projects')"
        >
            <div
                class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-5 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="columns"
                role="row"
            >
                <span role="columnheader">{{ $t('Project') }}</span>
                <span role="columnheader">{{ $t('Apps') }}</span>
                <span role="columnheader">{{ $t('Services') }}</span>
                <span role="columnheader">{{ $t('Machines') }}</span>
                <span role="columnheader" class="text-right">{{
                    $t('Network')
                }}</span>
            </div>
            <Link
                v-for="p in projects"
                :key="p.uid"
                :href="`/projects/${p.uid}`"
                class="ds-tile-row px-5 py-3 text-[13px]/4 transition-colors hover:bg-(--ds-row-hover)"
                :class="[columns, focusRing]"
                role="row"
            >
                <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                    <span class="truncate font-medium">{{ p.name }}</span>
                    <span class="truncate text-xs text-(--ds-text-muted)">
                        {{
                            p.links === 1
                                ? $t('1 connection')
                                : $t(':count connections', {
                                      count: String(p.links),
                                  })
                        }}
                    </span>
                </span>
                <span class="hidden tabular-nums md:block" role="cell">{{
                    p.apps
                }}</span>
                <span class="hidden tabular-nums md:block" role="cell">{{
                    p.resources
                }}</span>
                <span class="hidden tabular-nums md:block" role="cell">{{
                    p.machines
                }}</span>
                <span
                    class="flex items-center justify-end gap-2 text-(--ds-text-secondary)"
                    role="cell"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="networkTone[p.network]"
                    />
                    {{ $t(networkLabel[p.network]) }}
                </span>
            </Link>
        </div>

        <Dialog v-model:open="creating">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form class="flex flex-col gap-4 p-5" @submit.prevent="create">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('New project')
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'Name it after the app, like Shop or Shop staging. You add what’s in it next.',
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <Input
                        v-model="name"
                        maxlength="80"
                        required
                        autofocus
                        :placeholder="$t('Shop')"
                    />
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="creating = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="saving || !name.trim()"
                        >
                            {{ $t('Create project') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
