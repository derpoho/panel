<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ArrowDown, Plus, Undo2, X } from '@lucide/vue';
import { SolarTrash2 as Trash2 } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { STEP_GROUPS, STEPS, stepTheme } from './kinds';
import type { Options, StepKind } from './kinds';
import StepSettings from './StepSettings.vue';
import { useWorkflowEditor } from './useWorkflowEditor';
import type { InspectorTab } from './useWorkflowEditor';

const props = defineProps<{
    options: Options;
    webhookUrl: string | null;
    twoFactor: boolean;
    problems: Record<string, string>;
}>();

const emit = defineEmits<{
    focus: [id: string];
    append: [sourceId: string, kind: StepKind];
    rotateWebhook: [];
}>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;

const page = usePage<{
    workflow: { id: number };
    canManage: boolean;
    errors: Record<string, string>;
}>();
const rollingBack = ref(false);

/** Switch the app a release step released back to the release it replaced. */
function rollBack(): void {
    const run = editor.run.value;
    if (!run || !node.value) return;
    rollingBack.value = true;
    router.post(
        `/workflows/${page.props.workflow.id}/runs/${run.id}/rollback/${node.value.id}`,
        {},
        { onFinish: () => (rollingBack.value = false) },
    );
}

const node = computed(() =>
    editor.nodes.value.find((n) => n.id === editor.inspector.value?.nodeId),
);
const next = computed(() =>
    editor.edges.value.find((e) => e.source === editor.inspector.value?.nodeId),
);
const tab = computed(() => editor.inspector.value?.tab ?? 'build');
const step = computed(() => (node.value ? STEPS[node.value.kind] : null));

const tabs: { value: InspectorTab; label: string }[] = [
    { value: 'build', label: trans('Build') },
    { value: 'status', label: trans('Status') },
    { value: 'logs', label: trans('Logs') },
];

function setTab(value: InspectorTab): void {
    if (editor.inspector.value) editor.inspector.value.tab = value;
}

function close(): void {
    editor.inspector.value = null;
}

function goNext(): void {
    // Capture the target before openInspector() changes the inspected node,
    // which recomputes `next` for the new node (undefined on the last step).
    const target = next.value?.target;
    if (!target) return;
    editor.openInspector(target);
    emit('focus', target);
}

const runStep = computed(() =>
    node.value ? editor.run.value?.steps[node.value.id] : undefined,
);

const statusLabel = computed(() => {
    if (!editor.run.value)
        return trans('Not run yet in what you’re looking at');
    const labels: Record<string, string> = {
        running: trans('Running now'),
        waiting: trans('Waiting'),
        succeeded: trans('Done'),
        failed: trans('Failed'),
        cancelled: trans('Cancelled'),
        pending: trans('Queued'),
    };

    return labels[runStep.value?.state ?? ''] ?? trans('Skipped in this run');
});

function seconds(a?: number, b?: number): string | null {
    if (!a) return null;
    const s = Math.max(0, (b ?? Math.floor(Date.now() / 1000)) - a);

    return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`;
}

const scope = ref<'step' | 'all'>('step');
const logs = computed(() =>
    [...(editor.run.value?.logs ?? [])]
        .reverse()
        .filter((l) => scope.value === 'all' || l.node === node.value?.id),
);

const time = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
});

const dot: Record<string, string> = {
    info: 'bg-(--wf-faint)',
    success: 'bg-(--ds-green) shadow-[0_0_6px_var(--ds-green)]',
    warning: 'bg-(--ds-amber) shadow-[0_0_6px_var(--ds-amber)]',
    error: 'bg-(--ds-red) shadow-[0_0_6px_var(--ds-red)]',
};
</script>

<template>
    <aside
        v-if="editor.inspector.value && node && step"
        :aria-label="`${step.label} settings`"
        data-canvas-overlay
        class="wf-panel wf-inspector absolute top-4 right-4 z-30 flex max-h-[calc(100%-32px)] min-h-[260px] w-[min(440px,calc(100%-32px))] flex-col overflow-clip rounded-[16px]"
        :style="stepTheme(node.kind)"
        @pointerdown.stop
    >
        <div
            class="flex shrink-0 items-center justify-between gap-3 px-[18px] py-3.5"
        >
            <div
                class="wf-segments"
                role="group"
                :aria-label="$t('Step panels')"
            >
                <button
                    v-for="t in tabs"
                    :key="t.value"
                    type="button"
                    :aria-pressed="tab === t.value"
                    @click="setTab(t.value)"
                >
                    {{ t.label }}
                </button>
            </div>
            <button
                type="button"
                class="flex size-8 cursor-pointer items-center justify-center rounded-[8px] text-(--wf-muted) hover:bg-(--wf-hover) hover:text-(--wf-text)"
                :aria-label="$t('Close step panel')"
                @click="close"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="wf-divider" />
        <div
            :key="`${node.id}-${tab}`"
            class="min-h-0 flex-1 overflow-y-auto px-5 py-[18px]"
        >
            <div v-if="tab === 'build'" class="flex flex-col gap-4">
                <div class="flex items-center gap-2.5">
                    <component
                        :is="step.icon"
                        class="wf-tone size-[18px]"
                        :stroke-width="2"
                    />
                    <span class="text-[15px]/6 font-[550] text-(--wf-text)">{{
                        step.label
                    }}</span>
                </div>
                <p
                    v-if="problems[node.id]"
                    class="rounded-[10px] bg-(--ds-amber)/10 px-3 py-2 text-[12.5px]/5 text-(--ds-amber) ring-1 ring-(--ds-amber)/25"
                >
                    {{ problems[node.id] }}
                </p>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[12px]/5 font-[550] text-(--wf-muted)">{{
                        $t('Name')
                    }}</span>
                    <input
                        class="wf-field h-9 w-full px-3 text-[13px]/5"
                        :value="node.title"
                        :disabled="readOnly"
                        maxlength="80"
                        @focus="editor.checkpoint()"
                        @input="
                            editor.updateNode(node.id, {
                                title: ($event.target as HTMLInputElement)
                                    .value,
                            })
                        "
                    />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[12px]/5 font-[550] text-(--wf-muted)">{{
                        $t('Description')
                    }}</span>
                    <textarea
                        class="wf-field min-h-16 w-full px-3 py-2 text-[13px]/5"
                        :value="node.description"
                        :disabled="readOnly"
                        maxlength="280"
                        placeholder="What this step is for"
                        @focus="editor.checkpoint()"
                        @input="
                            editor.updateNode(node.id, {
                                description: (
                                    $event.target as HTMLTextAreaElement
                                ).value,
                            })
                        "
                    />
                </label>
                <div class="wf-divider -mx-5" />
                <StepSettings
                    :node="node"
                    :options="options"
                    :webhook-url="webhookUrl"
                    :two-factor="twoFactor"
                    @rotate-webhook="emit('rotateWebhook')"
                />
            </div>

            <div v-else-if="tab === 'status'" class="flex flex-col gap-4">
                <div class="wf-card-soft flex items-center gap-3 px-3.5 py-3">
                    <span class="relative flex size-2.5 shrink-0">
                        <span
                            v-if="
                                ['running', 'waiting'].includes(
                                    runStep?.state ?? '',
                                )
                            "
                            class="absolute inset-0 animate-ping rounded-full bg-(--wf-tone) opacity-60 motion-reduce:hidden"
                        />
                        <span
                            class="relative size-2.5 rounded-full"
                            :class="{
                                'bg-(--ds-red)': runStep?.state === 'failed',
                                'bg-(--wf-tone) shadow-[0_0_8px_var(--wf-tone)]':
                                    runStep && runStep.state !== 'failed',
                                'bg-(--wf-faint)': !runStep,
                            }"
                        />
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="text-[14px]/5 font-[550] text-(--wf-text)"
                            >{{ statusLabel }}</span
                        >
                        <span
                            v-if="runStep?.message"
                            class="text-[12.5px]/5 text-(--wf-muted)"
                            >{{ runStep.message }}</span
                        >
                    </div>
                </div>
                <div
                    v-if="
                        node?.kind === 'release' &&
                        runStep?.rollbackTo &&
                        page.props.canManage
                    "
                    class="flex flex-col gap-1.5"
                >
                    <button
                        type="button"
                        class="wf-button self-start"
                        :disabled="rollingBack || runStep.rolledBack"
                        @click="rollBack"
                    >
                        <Undo2 class="size-3.5" />
                        {{
                            runStep.rolledBack
                                ? $t('Rolled back')
                                : $t('Roll back to :commit', {
                                      commit: runStep.rollbackTo.slice(0, 7),
                                  })
                        }}
                    </button>
                    <span
                        v-if="page.props.errors.rollback"
                        class="text-[12px]/4 text-(--ds-red)"
                        >{{ page.props.errors.rollback }}</span
                    >
                    <span v-else class="text-[12px]/4 text-(--wf-faint)">{{
                        $t(
                            'Switches the app back to the release before this one, still kept beside it. Database changes stay as they are.',
                        )
                    }}</span>
                </div>
                <div v-if="runStep?.startedAt" class="grid grid-cols-2 gap-2">
                    <div class="wf-card-soft flex flex-col gap-1 px-3.5 py-3">
                        <span
                            class="text-[12px]/4 font-[550] text-(--wf-muted)"
                            >{{ $t('Started') }}</span
                        >
                        <span
                            class="text-[15px]/6 text-(--wf-text) tabular-nums"
                            >{{ time.format(runStep.startedAt * 1000) }}</span
                        >
                    </div>
                    <div class="wf-card-soft flex flex-col gap-1 px-3.5 py-3">
                        <span
                            class="text-[12px]/4 font-[550] text-(--wf-muted)"
                            >{{ $t('Took') }}</span
                        >
                        <span
                            class="text-[15px]/6 text-(--wf-text) tabular-nums"
                            >{{
                                seconds(runStep.startedAt, runStep.finishedAt)
                            }}</span
                        >
                    </div>
                </div>
                <p
                    v-if="!editor.run.value"
                    class="text-[12.5px]/5 text-(--wf-faint)"
                >
                    {{
                        ' ' +
                        $t(
                            'Press Run to try the workflow, or open a run from the Runs list to see what each step did.',
                        ) +
                        ' '
                    }}
                </p>
            </div>

            <div v-else class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <div
                        class="wf-segments"
                        role="group"
                        :aria-label="$t('Which logs')"
                    >
                        <button
                            type="button"
                            :aria-pressed="scope === 'step'"
                            @click="scope = 'step'"
                        >
                            {{ ' ' + $t('This step') + ' ' }}
                        </button>
                        <button
                            type="button"
                            :aria-pressed="scope === 'all'"
                            @click="scope = 'all'"
                        >
                            {{ ' ' + $t('All steps') + ' ' }}
                        </button>
                    </div>
                    <span class="text-[12px]/4 text-(--wf-faint) tabular-nums">
                        {{ logs.length }}
                        {{ logs.length === 1 ? $t('entry') : $t('entries') }}
                    </span>
                </div>
                <ol
                    v-if="logs.length"
                    class="wf-card-soft flex flex-col overflow-hidden"
                >
                    <li
                        v-for="(log, i) in logs"
                        :key="`${log.t}-${i}`"
                        class="flex items-start gap-3 border-b border-(--wf-line) px-3.5 py-2.5 last:border-b-0"
                    >
                        <span
                            class="mt-[7px] size-1.5 shrink-0 rounded-full"
                            :class="dot[log.level]"
                        />
                        <span
                            class="min-w-0 flex-1 text-[13px]/5 break-words text-(--wf-text)/85"
                            >{{ log.message }}</span
                        >
                        <span
                            class="shrink-0 text-[12px]/5 text-(--wf-faint) tabular-nums"
                            >{{ time.format(log.t) }}</span
                        >
                    </li>
                </ol>
                <div
                    v-else
                    class="wf-card-soft flex flex-col items-center px-6 py-9 text-center"
                >
                    <span class="text-[14px]/5 font-[550] text-(--wf-text)">{{
                        $t('No logs yet')
                    }}</span>
                    <span
                        class="mt-1.5 max-w-[24em] text-[13px]/5 text-balance text-(--wf-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Run the workflow, or open a past run, to see what each step did.',
                            ) +
                            ' '
                        }}
                    </span>
                </div>
            </div>
        </div>

        <template v-if="!readOnly">
            <div class="wf-divider" />
            <div class="flex shrink-0 items-center gap-2 px-[18px] py-4">
                <button
                    v-if="node.kind !== 'trigger'"
                    type="button"
                    class="wf-button h-9! shrink-0"
                    :aria-label="$t('Delete step')"
                    :title="$t('Delete step')"
                    @click="editor.removeNode(node.id)"
                >
                    <Trash2 class="size-4" />
                </button>
                <button
                    v-if="next"
                    type="button"
                    class="wf-accent-button grow"
                    @click="goNext"
                >
                    <ArrowDown class="size-4" /> {{ $t('Next step') + ' ' }}
                </button>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="wf-accent-button grow">
                            <Plus class="size-4" />
                            {{ $t('Add next step') + ' ' }}
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="top"
                        align="center"
                        class="ds-modal max-h-96 w-72 overflow-y-auto font-ui"
                    >
                        <template
                            v-for="group in STEP_GROUPS"
                            :key="group.label"
                        >
                            <DropdownMenuLabel
                                class="text-[11px] tracking-wide text-(--ds-text-muted) uppercase"
                                >{{ group.label }}</DropdownMenuLabel
                            >
                            <DropdownMenuItem
                                v-for="kind in group.kinds"
                                :key="kind"
                                :style="stepTheme(kind)"
                                @select="emit('append', node.id, kind)"
                            >
                                <component
                                    :is="STEPS[kind].icon"
                                    class="wf-tone size-4"
                                />
                                {{ STEPS[kind].label }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </template>
    </aside>
</template>
