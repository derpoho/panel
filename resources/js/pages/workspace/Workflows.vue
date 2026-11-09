<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { SolarWorkflow as WorkflowIcon } from '@/lib/solarIcons';
import { computed, onMounted, ref } from 'vue';
import { toast } from '@/lib/toast';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { EVENTS } from '@/components/workflows/kinds';
import type { Graph, Options } from '@/components/workflows/kinds';
import TemplatePicker from '@/components/workflows/TemplatePicker.vue';
import WorkflowPreview from '@/components/workflows/WorkflowPreview.vue';
import type { Template } from '@/components/workflows/TemplatePicker.vue';
import PlanLock from '@/components/workspace/PlanLock.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace, WorkspaceAccess } from '@/types';

type WorkflowRow = {
    id: number;
    name: string;
    description: string | null;
    enabled: boolean;
    trigger: string;
    rules: Record<string, string | number | null>;
    steps: number;
    lastRun: { state: string; at: string } | null;
    lastRunAt: string | null;
    runs7d: number;
    failed7d: number;
    nextRunAt: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    workflows: WorkflowRow[];
    templates: Template[];
    planAllows: boolean;
    /** On plans without workflows: one to show behind the upgrade card. */
    preview: { graph: Graph; options: Options } | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const picking = ref(false);
const creating = ref<string | null>(null);

// ⌘K › New workflow lands here with ?new=1.
onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value &&
        props.planAllows &&
        props.workflows.length
    ) {
        picking.value = true;
    }
});

function create(key: string): void {
    creating.value = key;
    router.post(
        '/workflows',
        { template: key },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (creating.value = null),
        },
    );
}

function triggerLabel(row: WorkflowRow): string {
    const event = EVENTS.find((e) => e.id === row.trigger);
    if (row.trigger === 'schedule') {
        const every = row.rules.every;

        return every
            ? every === 'hour'
                ? trans('Every hour')
                : trans(
                      every === 'week'
                          ? 'Every week at :time UTC'
                          : 'Every day at :time UTC',
                      { time: String(row.rules.at ?? '03:00') },
                  )
            : trans('On a schedule');
    }

    return event?.label ?? row.trigger;
}

function ago(iso: string | null): string {
    if (!iso) return trans('Never');
    const s = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (s < 60) return trans('just now');
    if (s < 3600)
        return trans(':floor min ago', { floor: String(Math.floor(s / 60)) });
    if (s < 86400)
        return trans(':count h ago', { count: String(Math.floor(s / 3600)) });

    return trans(':count d ago', { count: String(Math.floor(s / 86400)) });
}

const runTone: Record<string, string> = {
    running: 'bg-(--ds-blue)',
    succeeded: 'bg-(--ds-green)',
    failed: 'bg-(--ds-red)',
    cancelled: 'bg-(--ds-text-faint)',
};

const columns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 md:grid-cols-[minmax(0,1.6fr)_minmax(10rem,1fr)_8rem_6rem_4.5rem]';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Workflows')" />

    <WorkspacePage :title="$t('Workflows')">
        <template #description>
            {{
                ' ' +
                $t(
                    'Deploys, restarts, checks and alerts, drawn as steps and run for you: when CI pushes, when something breaks, or on a schedule.',
                ) +
                ' '
            }}
        </template>

        <template v-if="canManage && planAllows && workflows.length" #actions>
            <button
                type="button"
                :class="appButtonClasses('primary')"
                :disabled="!planAllows"
                @click="picking = true"
            >
                <Plus class="size-3.5" /> {{ $t('New workflow') + ' ' }}
            </button>
        </template>

        <PlanLock
            :locked="!planAllows"
            feature="Workflows"
            plan="pro"
            :description="
                $t(
                    'Deploy when CI passes, restart what falls over and tell the team, drawn as steps and run for you.',
                )
            "
        >
            <!-- Locked: a real workflow, for a look at what Pro does -->
            <WorkflowPreview
                v-if="preview"
                :graph="preview.graph"
                :options="preview.options"
            />
            <!-- Empty: templates up front -->
            <div v-else-if="!workflows.length" class="flex flex-col gap-5">
                <div
                    class="ds-tile flex flex-col items-center px-6 pt-12 pb-8 text-center"
                >
                    <div class="ds-icon-chip" aria-hidden="true">
                        <WorkflowIcon class="size-4.5" :stroke-width="2" />
                    </div>
                    <h2 class="mt-5 text-base/6 font-semibold">
                        {{ ' ' + $t('Automate what you do by hand') + ' ' }}
                    </h2>
                    <p
                        class="mt-1.5 max-w-md text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Pick a starting point for how you work. Each one opens ready to use with your machines filled in; change anything you like, and nothing runs until you turn it on.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>
                <TemplatePicker
                    :templates="templates"
                    :disabled="!canManage || !planAllows"
                    :creating="creating"
                    @pick="create"
                />
            </div>

            <div
                v-else
                class="ds-tile overflow-x-auto"
                role="table"
                :aria-label="$t('Workflows')"
            >
                <div
                    class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-5 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                    :class="columns"
                    role="row"
                >
                    <span role="columnheader">{{ $t('Workflow') }}</span>
                    <span role="columnheader">{{ $t('Starts when') }}</span>
                    <span role="columnheader">{{ $t('Last run') }}</span>
                    <span role="columnheader" class="text-right">{{
                        $t('7 days')
                    }}</span>
                    <span role="columnheader" class="text-right">{{
                        $t('Status')
                    }}</span>
                </div>
                <Link
                    v-for="w in workflows"
                    :key="w.id"
                    :href="`/workflows/${w.id}`"
                    class="ds-tile-row px-5 py-3 text-[13px]/4 transition-colors hover:bg-(--ds-row-hover)"
                    :class="[columns, focusRing]"
                    role="row"
                >
                    <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                        <span class="truncate font-medium">{{ w.name }}</span>
                        <span class="truncate text-xs text-(--ds-text-muted)">
                            {{ w.steps }}
                            {{ w.steps === 1 ? $t('step') : $t('steps')
                            }}<template v-if="w.description">
                                · {{ w.description }}</template
                            >
                        </span>
                    </span>
                    <span
                        class="hidden min-w-0 flex-col gap-0.5 md:flex"
                        role="cell"
                    >
                        <span class="truncate">{{ triggerLabel(w) }}</span>
                        <span
                            v-if="w.nextRunAt"
                            class="text-xs text-(--ds-text-muted)"
                            >{{
                                $t('next :value', {
                                    value: new Date(w.nextRunAt).toLocaleString(
                                        undefined,
                                        {
                                            weekday: 'short',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        },
                                    ),
                                })
                            }}</span
                        >
                    </span>
                    <span class="hidden items-center gap-2 md:flex" role="cell">
                        <span
                            v-if="w.lastRun"
                            class="size-1.5 rounded-full"
                            :class="runTone[w.lastRun.state]"
                        />
                        <span class="text-(--ds-text-secondary) tabular-nums">{{
                            ago(w.lastRunAt)
                        }}</span>
                    </span>
                    <span
                        class="hidden text-right tabular-nums md:block"
                        role="cell"
                    >
                        {{ w.runs7d }}
                        <span v-if="w.failed7d" class="text-(--ds-red)">{{
                            $t('· :failed7d failed', {
                                failed7d: String(w.failed7d ?? ''),
                            })
                        }}</span>
                    </span>
                    <span class="text-right" role="cell">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs/4 font-medium"
                            :class="
                                w.enabled
                                    ? 'bg-(--ds-green)/12 text-(--ds-green)'
                                    : 'bg-(--ds-hover) text-(--ds-text-muted)'
                            "
                        >
                            {{ w.enabled ? $t('On') : $t('Off') }}
                        </span>
                    </span>
                </Link>
            </div>
        </PlanLock>

        <Dialog v-model:open="picking">
            <DialogContent
                class="workspace-panel ds-modal flex max-h-[min(86vh,760px)] flex-col gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-2xl"
            >
                <div class="flex shrink-0 flex-col gap-1 px-5 pt-5 pb-3">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('New workflow')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Start from one of these and change anything you like. It stays off until you turn it on.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex min-h-0 flex-1 flex-col px-5 pb-5">
                    <TemplatePicker
                        :templates="templates"
                        :creating="creating"
                        compact
                        @pick="create"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
