<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Ellipsis } from '@lucide/vue';
import {
    SolarTriangleAlert as TriangleAlert,
    SolarPlay as Play,
    SolarRotateCw as RotateCw,
    SolarSquare as Square,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from '@/lib/toast';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EVENTS } from '@/components/workflows/kinds';
import type { Graph, Options, Run } from '@/components/workflows/kinds';
import {
    createWorkflowEditor,
    provideWorkflowEditor,
} from '@/components/workflows/useWorkflowEditor';
import WorkflowCanvas from '@/components/workflows/WorkflowCanvas.vue';
import WorkflowSimple from '@/components/workflows/WorkflowSimple.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

type RunSummary = {
    id: number;
    state: Run['state'];
    trigger: string;
    kind: string;
    user: string | null;
    startedAt: string;
    seconds: number;
};

const props = defineProps<{
    workspace: Workspace;
    workflow: {
        id: number;
        name: string;
        description: string | null;
        enabled: boolean;
        trigger: string;
        graph: Graph;
        webhookUrl: string | null;
        nextRunAt: string | null;
        runAs: string | null;
        updatedAt: string | null;
    };
    problems: Record<string, string>;
    options: Options;
    runs: RunSummary[];
    run: Run | null;
    canManage: boolean;
    planAllows: boolean;
    twoFactor: boolean;
}>();

const page = usePage<{ errors: Record<string, string> }>();

const mode = ref<'edit' | 'runs'>(props.run ? 'runs' : 'edit');

/**
 * Easy mode lists the steps in plain words; Power mode is the canvas.
 * Both edit the same workflow. Remembered per browser.
 */
const VIEW_KEY = 'serveros:workflow-view';
function storedView(): 'easy' | 'power' {
    try {
        return localStorage.getItem(VIEW_KEY) === 'power' ? 'power' : 'easy';
    } catch {
        return 'easy';
    }
}
const view = ref<'easy' | 'power'>(storedView());
watch(view, (v) => {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        /* Private mode: fine to forget. */
    }
    if (v === 'power') requestAnimationFrame(() => canvas.value?.fitView());
});
const readOnly = computed(() => mode.value === 'runs' || !props.canManage);
const editor = createWorkflowEditor(
    props.run?.graph ?? props.workflow.graph,
    readOnly,
);
provideWorkflowEditor(editor);
editor.run.value = props.run;
if (props.run) editor.markSaved();

const name = ref(props.workflow.name);
const enabled = ref(props.workflow.enabled);
const saving = ref(false);
const canvas = ref<InstanceType<typeof WorkflowCanvas> | null>(null);

/** What was being edited, kept while looking at runs. */
let draft: Graph | null = null;

const dirty = computed(
    () =>
        mode.value === 'edit' &&
        (editor.dirty.value || name.value.trim() !== props.workflow.name),
);
const problemCount = computed(() => Object.keys(props.problems).length);
const event = computed(
    () => EVENTS.find((e) => e.id === props.workflow.trigger) ?? EVENTS[0],
);

function save(options: { enabled?: boolean; after?: () => void } = {}): void {
    if (!props.canManage) return;
    saving.value = true;
    router.put(
        `/workflows/${props.workflow.id}`,
        {
            name: name.value.trim() || trans('Untitled workflow'),
            description: props.workflow.description,
            enabled: options.enabled ?? enabled.value,
            graph: editor.serialise(),
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['workflow', 'problems', 'errors', 'flash'],
            onSuccess: () => {
                editor.markSaved();
                enabled.value = options.enabled ?? enabled.value;
                options.after?.();
            },
            onError: (errors) => {
                enabled.value = props.workflow.enabled;
                toast.error(
                    Object.values(errors)[0] ??
                        trans('Couldn’t save the workflow.'),
                );
            },
            onFinish: () => (saving.value = false),
        },
    );
}

function toggle(on: boolean): void {
    enabled.value = on;
    save({
        enabled: on,
        after: () =>
            toast.success(
                on
                    ? trans(':name is on', {
                          name: String(props.workflow.name),
                      })
                    : trans(':name is off', {
                          name: String(props.workflow.name),
                      }),
            ),
    });
}

function runNow(): void {
    const go = () =>
        router.post(
            `/workflows/${props.workflow.id}/run`,
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? trans('Couldn’t start it.'),
                    ),
            },
        );
    if (dirty.value) save({ after: go });
    else go();
}

function cancelRun(): void {
    if (!props.run) return;
    router.post(
        `/workflows/${props.workflow.id}/runs/${props.run.id}/cancel`,
        {},
        { preserveScroll: true },
    );
}

function rotateWebhook(): void {
    router.post(
        `/workflows/${props.workflow.id}/webhook`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['workflow'],
            onSuccess: () =>
                toast.success(
                    trans('New webhook URL; the old one no longer works.'),
                ),
        },
    );
}

const confirmingDelete = ref(false);
function destroy(): void {
    router.delete(`/workflows/${props.workflow.id}`);
}

function showRuns(): void {
    if (mode.value === 'runs') return;
    draft = editor.snapshot();
    mode.value = 'runs';
    const latest = props.runs[0];
    if (latest && !props.run) openRun(latest.id);
}

function showEditor(): void {
    if (mode.value === 'edit') return;
    mode.value = 'edit';
    editor.run.value = null;
    editor.replace(draft ?? props.workflow.graph);
    draft = null;
    if (props.run)
        router.get(
            `/workflows/${props.workflow.id}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['run'],
            },
        );
}

function openRun(id: number): void {
    router.get(
        `/workflows/${props.workflow.id}`,
        { run: id },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['run', 'runs'],
        },
    );
}

watch(
    () => props.run,
    (run) => {
        if (!run) return;
        if (mode.value === 'edit') {
            draft = editor.snapshot();
            mode.value = 'runs';
        }
        const sameRun = editor.run.value?.id === run.id;
        editor.run.value = run;
        if (!sameRun) {
            editor.replace(run.graph);
            requestAnimationFrame(() => canvas.value?.fitView());
        }
    },
);

let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    poll = setInterval(() => {
        if (
            mode.value === 'runs' &&
            props.run?.state === 'running' &&
            document.visibilityState === 'visible'
        ) {
            router.reload({ only: ['run', 'runs'] });
        }
    }, 2000);
    window.addEventListener('beforeunload', guard);
});
onBeforeUnmount(() => {
    clearInterval(poll);
    window.removeEventListener('beforeunload', guard);
});

function guard(event: BeforeUnloadEvent): void {
    if (dirty.value) event.preventDefault();
}

/* Save with ⌘S. */
function onKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        if (dirty.value) save();
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const stateLook: Record<
    Run['state'],
    { label: string; dot: string; text: string }
> = {
    running: {
        label: trans('Running'),
        dot: 'bg-(--ds-blue) animate-pulse',
        text: 'text-(--ds-blue)',
    },
    succeeded: {
        label: trans('Succeeded'),
        dot: 'bg-(--ds-green)',
        text: 'text-(--ds-green)',
    },
    failed: {
        label: trans('Failed'),
        dot: 'bg-(--ds-red)',
        text: 'text-(--ds-red)',
    },
    cancelled: {
        label: trans('Cancelled'),
        dot: 'bg-(--ds-text-faint)',
        text: 'text-(--ds-text-muted)',
    },
};

function when(iso: string): string {
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();

    return d.toLocaleString(
        undefined,
        today
            ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
            : {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              },
    );
}

function took(seconds: number): string {
    return seconds < 60
        ? `${seconds} s`
        : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}

const errors = computed(() => page.props.errors ?? {});
watch(errors, (e) => {
    const message = e.run ?? e.graph;
    if (message) toast.error(message);
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="workflow.name" />

    <div
        class="workspace-panel flex h-full min-h-[560px] flex-col font-ui text-(--ds-text)"
    >
        <header
            class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-(--ds-divider) px-4 py-2.5 sm:px-5"
        >
            <Link
                href="/workflows"
                class="flex size-8 shrink-0 items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :class="focusRing"
                :aria-label="$t('All workflows')"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div class="flex min-w-0 flex-col">
                <input
                    v-model="name"
                    class="min-w-0 rounded-md bg-transparent px-1 text-[15px]/6 font-semibold outline-none hover:bg-(--ds-hover) focus:bg-(--ds-hover) disabled:hover:bg-transparent"
                    :disabled="!canManage"
                    maxlength="80"
                    :aria-label="$t('Workflow name')"
                    :size="Math.max(12, name.length + 1)"
                />
                <span class="truncate px-1 text-xs/4 text-(--ds-text-muted)">
                    {{ event.label }}
                    <template v-if="workflow.nextRunAt">
                        {{
                            ' ' +
                            $t('· next :value', {
                                value: when(workflow.nextRunAt),
                            })
                        }}</template
                    >
                    <template v-if="workflow.runAs">
                        {{
                            ' ' +
                            $t('· runs as :runAs', { runAs: workflow.runAs })
                        }}</template
                    >
                </span>
            </div>

            <span
                v-if="problemCount && mode === 'edit' && canManage"
                class="flex items-center gap-1.5 rounded-full bg-(--ds-amber)/10 px-2.5 py-1 text-xs/4 font-medium text-(--ds-amber)"
                :title="Object.values(problems).join('\n')"
            >
                <TriangleAlert class="size-3.5" />
                {{
                    $t(':problemCount :value attention', {
                        problemCount: String(problemCount ?? ''),
                        value:
                            problemCount === 1
                                ? $t('step needs')
                                : $t('steps need'),
                    }) + ' '
                }}
            </span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Mode')"
                    :title="
                        $t(
                            'Easy lists the steps in plain words; Power draws them on a canvas. Both edit the same workflow.',
                        )
                    "
                >
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="view === 'easy'"
                        @click="view = 'easy'"
                    >
                        {{ ' ' + $t('Easy') + ' ' }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="view === 'power'"
                        @click="view = 'power'"
                    >
                        {{ ' ' + $t('Power') + ' ' }}
                    </button>
                </div>
                <div class="ds-segments" role="group" :aria-label="$t('View')">
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="mode === 'edit'"
                        @click="showEditor"
                    >
                        {{ ' ' + $t('Editor') + ' ' }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :aria-pressed="mode === 'runs'"
                        @click="showRuns"
                    >
                        {{ ' ' + $t('Runs') }}
                        <span
                            class="text-xs/4 text-(--ds-text-faint) tabular-nums"
                            >{{ runs.length }}</span
                        >
                    </button>
                </div>

                <template v-if="canManage">
                    <AppButton
                        v-if="dirty"
                        size="sm"
                        :disabled="saving"
                        @click="save()"
                    >
                        {{ saving ? $t('Saving…') : $t('Save') }}
                    </AppButton>
                    <button
                        type="button"
                        :class="appButtonClasses('secondary', 'sm')"
                        :disabled="!planAllows || saving"
                        :title="
                            problemCount
                                ? $t(
                                      'Fix the steps marked with a warning first',
                                  )
                                : $t('Run it now, by hand')
                        "
                        @click="runNow"
                    >
                        <Play class="size-3.5" /> {{ $t('Run') + ' ' }}
                    </button>
                    <label
                        class="flex h-7 items-center gap-2 rounded-md px-2 text-xs/4 font-medium"
                        :title="
                            problemCount && !enabled
                                ? $t('Fix the marked steps to turn it on')
                                : undefined
                        "
                    >
                        <ToggleSwitch
                            :model-value="enabled"
                            :label="$t('Workflow on')"
                            :disabled="
                                !planAllows ||
                                saving ||
                                (!enabled && problemCount > 0)
                            "
                            @update:model-value="toggle"
                        />
                        {{ enabled ? $t('On') : $t('Off') }}
                    </label>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :class="
                                    appButtonClasses('quiet', 'sm', 'px-1.5')
                                "
                                :aria-label="$t('More')"
                            >
                                <Ellipsis class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-48 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem
                                v-if="workflow.trigger === 'webhook'"
                                @select="rotateWebhook"
                            >
                                <RotateCw class="size-4" />
                                {{ $t('New webhook URL') + ' ' }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="workflow.trigger === 'webhook'"
                            />
                            <DropdownMenuItem
                                class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                @select="confirmingDelete = true"
                            >
                                <Trash2 class="size-4 text-current" />
                                {{ $t('Delete workflow') + ' ' }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </div>
        </header>

        <div
            v-if="!planAllows"
            class="border-b border-(--ds-divider) bg-(--ds-amber)/8 px-5 py-2 text-[13px]/5 text-(--ds-text-secondary)"
        >
            {{
                ' ' +
                $t(
                    'Workflows come with Pro and Business. You can look around and edit; turning one on or running it needs',
                )
            }}
            <Link
                v-if="upgrade"
                :href="upgrade.href"
                class="font-medium text-(--ds-blue) hover:underline"
                >{{ $t('a plan that includes them') }}</Link
            ><template v-else>{{ $t('a plan that includes them') }}</template
            >.
        </div>

        <!-- Easy mode: runs as a picker over the list -->
        <div
            v-if="view === 'easy' && mode === 'runs'"
            class="flex flex-wrap items-center gap-3 border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/5 sm:px-5"
        >
            <template v-if="runs.length">
                <select
                    class="wf-field h-8 max-w-full rounded-md border border-(--ds-border) bg-(--ds-field) px-2 text-[13px]/5"
                    :value="run?.id ?? ''"
                    :aria-label="$t('Run')"
                    @change="
                        openRun(
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="r in runs" :key="r.id" :value="r.id">
                        #{{ r.id }} · {{ stateLook[r.state].label }} ·
                        {{ when(r.startedAt) }} · {{ r.trigger }}
                    </option>
                </select>
                <span v-if="run" class="flex items-center gap-2">
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="stateLook[run.state].dot"
                    />
                    <span :class="stateLook[run.state].text">{{
                        stateLook[run.state].label
                    }}</span>
                    <span class="text-(--ds-text-muted)">{{
                        $t('in :value', { value: took(run.seconds) })
                    }}</span>
                </span>
                <button
                    v-if="run?.state === 'running' && canManage"
                    type="button"
                    :class="appButtonClasses('secondary', 'sm')"
                    @click="cancelRun"
                >
                    <Square class="size-3" /> {{ $t('Cancel') + ' ' }}
                </button>
            </template>
            <span v-else class="text-(--ds-text-muted)"
                >{{ ' ' + $t('No runs yet. Press') }}
                <span class="font-medium text-(--ds-text)">{{
                    $t('Run')
                }}</span>
                {{
                    $t('to try it; runs from its trigger show up here too.') +
                    ' '
                }}</span
            >
            <button
                type="button"
                :class="appButtonClasses('quiet', 'sm', 'ml-auto')"
                @click="showEditor"
            >
                {{ ' ' + $t('Back to editing') + ' ' }}
            </button>
        </div>

        <WorkflowSimple
            v-if="view === 'easy'"
            :options="options"
            :webhook-url="workflow.webhookUrl"
            :two-factor="twoFactor"
            :problems="mode === 'edit' ? problems : {}"
            @rotate-webhook="rotateWebhook"
        />

        <WorkflowCanvas
            v-else
            ref="canvas"
            :options="options"
            :webhook-url="workflow.webhookUrl"
            :two-factor="twoFactor"
            :problems="mode === 'edit' ? problems : {}"
            @rotate-webhook="rotateWebhook"
        >
            <aside
                v-if="mode === 'runs'"
                :aria-label="$t('Runs')"
                data-canvas-overlay
                class="wf-panel absolute top-4 left-4 z-20 flex max-h-[calc(100%-32px)] w-[min(300px,calc(100%-32px))] flex-col overflow-clip rounded-[12px]"
                @pointerdown.stop
            >
                <div
                    class="flex h-[52px] shrink-0 items-center justify-between px-[18px]"
                >
                    <span class="text-[14px]/6 font-[550] text-(--wf-text)">{{
                        $t('Runs')
                    }}</span>
                    <span class="text-xs/4 text-(--wf-faint)">{{
                        $t('Last 30 days')
                    }}</span>
                </div>
                <div class="wf-divider" />
                <ol v-if="runs.length" class="min-h-0 overflow-y-auto p-2">
                    <li v-for="r in runs" :key="r.id">
                        <button
                            type="button"
                            class="flex w-full cursor-pointer flex-col gap-0.5 rounded-[8px] px-2.5 py-2 text-left hover:bg-(--wf-hover)"
                            :class="{
                                'bg-(--wf-hover) ring-1 ring-(--wf-line)':
                                    run?.id === r.id,
                            }"
                            @click="openRun(r.id)"
                        >
                            <span class="flex items-center gap-2 text-[13px]/5">
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="stateLook[r.state].dot"
                                />
                                <span class="font-medium text-(--wf-text)"
                                    >#{{ r.id }}</span
                                >
                                <span :class="stateLook[r.state].text">{{
                                    stateLook[r.state].label
                                }}</span>
                                <span
                                    class="ml-auto text-xs text-(--wf-faint) tabular-nums"
                                    >{{ when(r.startedAt) }}</span
                                >
                            </span>
                            <span
                                class="truncate pl-3.5 text-xs/4 text-(--wf-muted)"
                            >
                                {{ r.trigger }} · {{ took(r.seconds) }}
                            </span>
                        </button>
                    </li>
                </ol>
                <div
                    v-else
                    class="px-[18px] py-6 text-[13px]/5 text-(--wf-muted)"
                >
                    {{ $t('No runs yet. Press') }}
                    <span class="font-medium text-(--wf-text)">{{
                        $t('Run')
                    }}</span>
                    {{
                        $t('to try it; runs from its trigger show up here too.')
                    }}
                </div>
            </aside>

            <!-- The run being shown -->
            <div
                v-if="mode === 'runs' && run"
                data-canvas-overlay
                class="wf-panel absolute bottom-4 left-1/2 z-20 flex max-w-[calc(100%-32px)] -translate-x-1/2 items-center gap-3 rounded-[12px] px-4 py-2.5 text-[13px]/5"
                @pointerdown.stop
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :class="stateLook[run.state].dot"
                />
                <span class="min-w-0 truncate">
                    <span class="font-semibold">{{
                        $t('Run #:id', { id: String(run.id ?? '') })
                    }}</span>
                    <span :class="stateLook[run.state].text">{{
                        ` ${stateLook[run.state].label.toLowerCase()}`
                    }}</span>
                    <span class="text-(--wf-muted)">
                        · {{ took(run.seconds) }} ·
                        {{ run.trigger.summary }}</span
                    >
                </span>
                <button
                    v-if="run.state === 'running' && canManage"
                    type="button"
                    class="wf-button h-7! shrink-0"
                    @click="cancelRun"
                >
                    <Square class="size-3" /> {{ $t('Cancel') + ' ' }}
                </button>
                <button
                    type="button"
                    class="wf-button h-7! shrink-0"
                    @click="showEditor"
                >
                    {{ ' ' + $t('Back to editing') + ' ' }}
                </button>
            </div>
        </WorkflowCanvas>
    </div>

    <Dialog v-model:open="confirmingDelete">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Delete :name?', { name: workflow.name })
                }}</DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Its runs go with it. Turn it off instead to keep them.',
                        ) +
                        ' '
                    }}
                </DialogDescription>
            </div>
            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
            >
                <AppButton
                    size="sm"
                    variant="quiet"
                    @click="confirmingDelete = false"
                    >{{ $t('Cancel') }}</AppButton
                >
                <form @submit.prevent="destroy">
                    <DangerButton :label="$t('Delete workflow')" />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
