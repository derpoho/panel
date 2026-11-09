<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { GripVertical } from '@lucide/vue';
import { SolarTriangleAlert as TriangleAlert } from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, useTemplateRef } from 'vue';
import { hasInput } from './geometry';
import { STEPS, stepTheme } from './kinds';
import type { WorkflowNode } from './kinds';

const props = defineProps<{
    node: WorkflowNode;
    selected: boolean;
    targeted: boolean;
    dragging: boolean;
    /** idle, or a run's state for this step. */
    runState: string;
    problem: string | null;
    editing: boolean;
    readOnly: boolean;
    /** A short summary of its settings, under the description. */
    summary: string | null;
}>();

const emit = defineEmits<{
    measure: [id: string, height: number];
    nodePointerdown: [id: string, event: PointerEvent];
    handlePointerdown: [id: string, event: PointerEvent];
    open: [id: string];
}>();

const step = computed(() => STEPS[props.node.kind]);
const root = useTemplateRef<HTMLDivElement>('root');
let observer: ResizeObserver | null = null;

onMounted(() => {
    if (!root.value) return;
    observer = new ResizeObserver(() =>
        emit('measure', props.node.id, root.value!.offsetHeight),
    );
    observer.observe(root.value);
});
onBeforeUnmount(() => observer?.disconnect());

const badgeLabels: Record<string, string> = {
    running: trans('Running'),
    waiting: trans('Waiting'),
    succeeded: trans('Done'),
    failed: trans('Failed'),
    cancelled: trans('Cancelled'),
    pending: trans('Queued'),
};

const badge = computed(() => badgeLabels[props.runState] ?? null);

const active = computed(() => ['running', 'waiting'].includes(props.runState));
</script>

<template>
    <div
        ref="root"
        :data-node="node.id"
        :data-selected="selected || undefined"
        :data-targeted="targeted || undefined"
        :data-dragging="dragging || undefined"
        :data-run="runState === 'idle' ? undefined : runState"
        class="wf-node group/node absolute top-0 left-0 w-[400px] data-dragging:z-10"
        :class="{ 'wf-node-fresh': node.fresh }"
        :style="{
            ...stepTheme(node.kind),
            translate: `${node.x}px ${node.y}px`,
        }"
    >
        <div
            class="wf-card relative flex flex-col gap-[3px] rounded-[12px] border px-[5px] pt-[5px] pb-[7px] backdrop-blur-[16px] transition-[border-color,box-shadow,opacity] duration-150"
            :class="
                readOnly
                    ? 'cursor-pointer'
                    : 'cursor-grab group-data-dragging/node:cursor-grabbing'
            "
            @pointerdown="emit('nodePointerdown', node.id, $event)"
            @dblclick="emit('open', node.id)"
        >
            <span
                v-if="active"
                aria-hidden="true"
                class="wf-ping pointer-events-none absolute -inset-px rounded-[inherit] motion-reduce:hidden"
            />
            <div class="flex h-9 items-center px-1 py-1.5">
                <div class="flex shrink-0 items-center pl-1">
                    <component
                        :is="step.icon"
                        class="wf-tone size-[18px]"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                </div>
                <div class="flex min-w-0 flex-1 items-center pr-4 pl-2">
                    <span
                        class="truncate text-[13.5px]/6 font-medium text-(--wf-muted)"
                    >
                        {{ step.label }}
                    </span>
                </div>
                <span
                    v-if="badge"
                    class="wf-badge mr-1.5 flex h-5 items-center gap-1.5 rounded-full px-2 text-[12px]/5 font-[550]"
                    :data-state="runState"
                >
                    <span
                        class="size-1.5 rounded-full bg-current"
                        :class="{ 'animate-pulse': active }"
                    />
                    {{ badge }}
                </span>
                <span
                    v-else-if="problem && !readOnly"
                    class="mr-1.5 flex size-5 items-center justify-center text-(--ds-amber)"
                    :title="problem"
                >
                    <TriangleAlert class="size-4" :stroke-width="2" />
                </span>
                <div
                    v-else-if="!readOnly"
                    class="flex items-center justify-end pr-0.5 opacity-0 transition-opacity duration-150 group-hover/node:opacity-100"
                >
                    <GripVertical
                        class="size-4 text-(--wf-faint)"
                        aria-hidden="true"
                    />
                </div>
                <span v-if="node.kind === 'trigger'" class="wf-if-pill">{{
                    $t('IF')
                }}</span>
            </div>
            <div
                class="wf-body flex flex-col gap-1 rounded-[10px] px-3.5 py-3 text-[14px]"
            >
                <span
                    class="font-semibold text-(--wf-text)"
                    :class="node.kind === 'trigger' ? 'leading-6' : 'leading-5'"
                >
                    {{ node.title || step.title }}
                </span>
                <span class="leading-5 font-medium text-(--wf-faint)">
                    {{ node.description || step.description }}
                </span>
                <span
                    v-if="summary"
                    class="mt-1 truncate font-ui-mono text-[12px]/4 text-(--wf-muted)"
                    :title="summary"
                >
                    {{ summary }}
                </span>
                <span
                    v-if="problem && !readOnly"
                    class="mt-1 text-[12px]/4 font-medium text-(--ds-amber)"
                >
                    {{ problem }}
                </span>
            </div>
        </div>

        <span
            v-if="hasInput(node)"
            aria-hidden="true"
            class="wf-handle -top-1"
        />
        <span
            role="presentation"
            class="wf-handle -bottom-1 before:absolute before:-inset-2 before:rounded-full"
            :class="readOnly ? '' : 'cursor-crosshair'"
            @pointerdown="emit('handlePointerdown', node.id, $event)"
        />

        <div
            v-if="selected && !dragging && !editing"
            class="absolute -top-9 right-0"
            @pointerdown.stop
        >
            <button
                type="button"
                class="ds-raised-button h-7! cursor-pointer px-2.5! text-xs!"
                @click="emit('open', node.id)"
            >
                {{ readOnly ? $t('Details') : $t('Edit') }}
            </button>
        </div>
    </div>
</template>
