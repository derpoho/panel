<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronDown, CornerDownRight } from '@lucide/vue';
import {
    SolarTriangleAlert as TriangleAlert,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import { STEPS, stepTheme } from './kinds';
import type { PortId } from './kinds';
import type { SimpleStep } from './simple';
import SimpleAddMenu from './SimpleAddMenu.vue';
import { useSimple } from './simple';
import StepSettings from './StepSettings.vue';
import { useWorkflowEditor } from './useWorkflowEditor';

/**
 * Steps one after another, each with the paths that leave it another way
 * indented beneath it, to any depth.
 */
defineOptions({ name: 'SimpleChain' });

const props = defineProps<{
    steps: SimpleStep[];
    /** 0 for the main path. */
    depth: number;
}>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;
const ctx = useSimple();

const runLook: Record<string, { label: string; class: string }> = {
    succeeded: {
        label: trans('Done'),
        class: 'text-(--ds-green) bg-(--ds-green)/12',
    },
    failed: {
        label: trans('Failed'),
        class: 'text-(--ds-red) bg-(--ds-red)/12',
    },
    running: {
        label: trans('Running'),
        class: 'text-(--ds-blue) bg-(--ds-blue)/12',
    },
    waiting: {
        label: trans('Waiting'),
        class: 'text-(--ds-blue) bg-(--ds-blue)/12',
    },
    pending: {
        label: trans('Next'),
        class: 'text-(--ds-text-muted) bg-(--ds-hover)',
    },
    cancelled: {
        label: trans('Cancelled'),
        class: 'text-(--ds-text-muted) bg-(--ds-hover)',
    },
};

/** Where "add a step" at the end of a side path attaches. */
function addAt(side: SimpleStep['sides'][number]): {
    from: string;
    port: PortId;
} {
    const last = side.chain.at(-1)?.node;

    return last
        ? { from: last.id, port: ctx.mainPort(last) }
        : { from: side.from, port: side.port };
}

const top = props.depth === 0;
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div class="flex flex-col" :class="top ? '' : 'gap-2'">
        <template v-for="(step, i) in steps" :key="step.node.id">
            <template v-if="top && i > 0">
                <div
                    class="ml-[27px] h-5 w-px bg-(--ds-border-strong)"
                    aria-hidden="true"
                />
                <p
                    v-if="i === 1"
                    class="mb-2 ml-[52px] text-[11px]/4 font-semibold tracking-wide text-(--ds-text-faint) uppercase"
                >
                    {{ ' ' + $t('Then') + ' ' }}
                </p>
            </template>

            <div
                class="ds-tile overflow-hidden transition-opacity"
                :style="stepTheme(step.node.kind)"
                :class="{
                    'opacity-45':
                        editor.run.value && !ctx.runStep(step.node.id),
                }"
            >
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-start gap-3 text-left hover:bg-(--ds-row-hover)"
                    :class="[focusRing, top ? 'px-3.5 py-3' : 'px-3.5 py-2.5']"
                    :aria-expanded="ctx.open.value === step.node.id"
                    @click="ctx.toggle(step.node.id)"
                >
                    <span
                        class="simple-icon mt-0.5 flex shrink-0 items-center justify-center"
                        :class="
                            top
                                ? 'size-8 rounded-[8px]'
                                : 'size-7 rounded-[7px]'
                        "
                        aria-hidden="true"
                    >
                        <component
                            :is="STEPS[step.node.kind].icon"
                            :class="top ? 'size-4' : 'size-3.5'"
                            :stroke-width="2"
                        />
                    </span>
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <span
                            v-if="top"
                            class="text-[11px]/4 font-semibold tracking-wide text-(--ds-text-faint) uppercase"
                            >{{
                                step.node.kind === 'trigger'
                                    ? $t('When')
                                    : STEPS[step.node.kind].label
                            }}</span
                        >
                        <span
                            class="font-medium"
                            :class="top ? 'text-[14px]/5' : 'text-[13.5px]/5'"
                            >{{
                                step.node.title || STEPS[step.node.kind].title
                            }}</span
                        >
                        <span
                            class="truncate text-(--ds-text-muted)"
                            :class="top ? 'text-[13px]/5' : 'text-[12.5px]/5'"
                            >{{ ctx.detail(step.node) }}</span
                        >
                        <span
                            v-if="
                                ctx.problems()[step.node.id] &&
                                !editor.run.value
                            "
                            class="mt-1 flex items-center gap-1.5 text-[12.5px]/5 text-(--ds-amber)"
                        >
                            <TriangleAlert class="size-3.5 shrink-0" />
                            {{ ctx.problems()[step.node.id] }}
                        </span>
                        <span
                            v-if="ctx.runStep(step.node.id)?.message"
                            class="mt-1 text-[12.5px]/5 text-(--ds-text-secondary)"
                            >{{ ctx.runStep(step.node.id)?.message }}</span
                        >
                    </span>
                    <span
                        v-if="ctx.runStep(step.node.id)"
                        class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[11px]/4 font-semibold"
                        :class="
                            runLook[ctx.runStep(step.node.id)!.state]?.class
                        "
                        >{{
                            runLook[ctx.runStep(step.node.id)!.state]?.label
                        }}</span
                    >
                    <ChevronDown
                        class="mt-1.5 size-4 shrink-0 text-(--ds-text-faint) transition-transform"
                        :class="
                            ctx.open.value === step.node.id ? '' : '-rotate-90'
                        "
                    />
                </button>

                <div
                    v-if="ctx.open.value === step.node.id"
                    class="flex flex-col gap-4 border-t border-(--ds-divider) px-4 py-4"
                >
                    <label class="flex flex-col gap-1.5">
                        <span
                            class="text-[12px]/5 font-[550] text-(--wf-muted)"
                            >{{ $t('Name') }}</span
                        >
                        <input
                            class="wf-field h-9 w-full px-3 text-[13px]/5"
                            :value="step.node.title"
                            :disabled="readOnly"
                            maxlength="80"
                            @input="
                                editor.updateNode(step.node.id, {
                                    title: ($event.target as HTMLInputElement)
                                        .value,
                                })
                            "
                        />
                    </label>
                    <StepSettings
                        :node="step.node"
                        :options="ctx.options()"
                        :webhook-url="ctx.webhookUrl()"
                        :two-factor="ctx.twoFactor()"
                        @rotate-webhook="ctx.rotateWebhook()"
                    />
                    <button
                        v-if="step.node.kind !== 'trigger' && !readOnly"
                        type="button"
                        class="flex w-fit cursor-pointer items-center gap-1.5 rounded text-[12.5px]/5 text-(--ds-text-muted) hover:text-(--ds-red)"
                        :class="focusRing"
                        @click="ctx.remove(step.node)"
                    >
                        <Trash2 class="size-3.5" />
                        {{ $t('Remove this step') + ' ' }}
                    </button>
                </div>
            </div>

            <!-- Paths that leave this step another way -->
            <div
                v-for="side in step.sides"
                :key="side.port + (side.chain[0]?.node.id ?? '')"
                class="ml-[27px] border-l border-dashed border-(--ds-border-strong) pl-5"
                :class="top ? 'mt-2' : ''"
            >
                <p
                    class="flex items-center gap-1.5 pt-1 pb-2 text-[12.5px]/5 font-medium text-(--ds-text-secondary)"
                >
                    <CornerDownRight class="size-3.5 text-(--ds-text-faint)" />
                    {{ side.label }}
                </p>
                <SimpleChain
                    v-if="side.chain.length"
                    :steps="side.chain"
                    :depth="depth + 1"
                />
                <p
                    v-if="side.jump"
                    class="mt-2 text-[12.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t('Then carries on at “:value”.', {
                            value:
                                side.jump.title || STEPS[side.jump.kind].title,
                        }) +
                        ' '
                    }}
                </p>
                <SimpleAddMenu
                    v-else-if="!readOnly && !side.parallel"
                    class="mt-1"
                    :label="
                        side.chain.length
                            ? $t('Add a step')
                            : side.port === 'failed'
                              ? $t('Choose what happens if it fails')
                              : side.port === 'true'
                                ? $t('Choose what happens if yes')
                                : $t('Choose what happens if not')
                    "
                    @pick="
                        (kind) =>
                            ctx.add(kind, addAt(side).from, addAt(side).port)
                    "
                />
            </div>

            <p
                v-if="step.jump"
                class="mt-2 text-[12.5px]/5 text-(--ds-text-muted)"
                :class="top ? 'ml-[52px]' : ''"
            >
                {{
                    ' ' +
                    $t('Then carries on at “:value”.', {
                        value: step.jump.title || STEPS[step.jump.kind].title,
                    }) +
                    ' '
                }}
            </p>
        </template>
    </div>
</template>

<style scoped>
.simple-icon {
    color: var(--accent-ink);
    background: color-mix(in oklab, var(--accent-ink) 12%, transparent);
}

:global(.dark) .simple-icon {
    color: var(--accent);
    background: color-mix(in oklab, var(--accent) 14%, transparent);
}
</style>
