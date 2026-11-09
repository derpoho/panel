<script setup lang="ts">
import { ChevronsDownUp, ChevronsUpDown, GripVertical, X } from '@lucide/vue';
import { STEP_GROUPS, STEPS, stepTheme } from './kinds';
import type { StepKind } from './kinds';

defineProps<{
    open: boolean;
    draggingKind: StepKind | null;
    /** Set when a + was clicked: what the next step will follow. */
    pendingLabel: string | null;
}>();

const emit = defineEmits<{
    toggle: [];
    itemPointerdown: [kind: StepKind, event: PointerEvent];
    add: [kind: StepKind];
    cancelPending: [];
}>();

function onKey(kind: StepKind, event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    emit('add', kind);
}
</script>

<template>
    <aside
        :aria-label="$t('Steps')"
        data-canvas-overlay
        class="wf-panel absolute top-4 left-4 z-20 flex max-h-[calc(100%-32px)] w-[calc(100%-32px)] flex-col overflow-clip rounded-[12px] sm:w-[min(300px,calc(100%-32px))]"
        @pointerdown.stop
    >
        <div
            class="flex h-[52px] shrink-0 items-center justify-between px-[18px] py-2.5"
        >
            <span class="text-[14px]/6 font-[550] text-(--wf-text)">{{
                $t('Steps')
            }}</span>
            <button
                type="button"
                class="flex size-8 cursor-pointer items-center justify-center rounded-[8px] text-(--wf-muted) hover:bg-(--wf-hover) hover:text-(--wf-text)"
                :aria-expanded="open"
                :aria-label="open ? $t('Collapse steps') : $t('Expand steps')"
                @click="emit('toggle')"
            >
                <component
                    :is="open ? ChevronsDownUp : ChevronsUpDown"
                    class="size-4"
                />
            </button>
        </div>

        <div
            class="grid min-h-0 transition-[grid-template-rows] duration-300 motion-reduce:transition-none"
            :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            :inert="!open"
        >
            <div class="flex min-h-0 flex-col overflow-hidden">
                <div class="wf-divider" />
                <div
                    v-if="pendingLabel"
                    class="flex items-center gap-2 bg-(--wf-hover) px-[18px] py-2 text-[12px]/4 text-(--wf-muted)"
                >
                    <span class="min-w-0 grow">
                        {{ ' ' + $t('Choose the step after') }}
                        <span class="font-medium text-(--wf-text)">{{
                            pendingLabel
                        }}</span>
                    </span>
                    <button
                        type="button"
                        class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md hover:bg-(--wf-hover)"
                        :aria-label="$t('Cancel')"
                        @click="emit('cancelPending')"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <div class="min-h-0 overflow-y-auto px-4 py-3">
                    <div class="flex flex-col gap-3">
                        <section
                            v-for="group in STEP_GROUPS"
                            :key="group.label"
                            :aria-label="group.label"
                            class="flex flex-col gap-0.5"
                        >
                            <div class="flex h-7 items-center px-1">
                                <span
                                    class="text-[11px]/6 font-[550] tracking-wide text-(--wf-faint) uppercase"
                                >
                                    {{ group.label }}
                                </span>
                            </div>
                            <ul class="flex flex-col gap-0.5">
                                <li
                                    v-for="kind in group.kinds"
                                    :key="kind"
                                    role="button"
                                    tabindex="0"
                                    :aria-label="
                                        $t('Add :value', {
                                            value: String(STEPS[kind].label),
                                        })
                                    "
                                    :title="STEPS[kind].help"
                                    :data-dragging="
                                        draggingKind === kind || undefined
                                    "
                                    class="wf-item group/item relative flex w-full cursor-grab touch-none items-center rounded-[8px] px-1 py-1.5 outline-none select-none active:cursor-grabbing data-dragging:opacity-50"
                                    :style="stepTheme(kind)"
                                    @pointerdown="
                                        emit('itemPointerdown', kind, $event)
                                    "
                                    @keydown="onKey(kind, $event)"
                                >
                                    <span
                                        class="flex shrink-0 items-center pl-1"
                                    >
                                        <component
                                            :is="STEPS[kind].icon"
                                            class="wf-tone size-[18px]"
                                            :stroke-width="2"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span
                                        class="flex min-w-0 flex-1 items-center pr-4 pl-2 text-[14px]/6 font-medium text-(--wf-text)"
                                    >
                                        {{ STEPS[kind].label }}
                                    </span>
                                    <span
                                        class="flex items-center justify-end pr-0.5 opacity-0 transition-opacity duration-150 group-hover/item:opacity-100 group-focus-visible/item:opacity-100"
                                    >
                                        <GripVertical
                                            class="size-4 text-(--wf-faint)"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
                <div class="wf-divider" />
                <p class="px-[18px] py-3 text-[12px]/5 text-(--wf-faint)">
                    {{ ' ' + $t('Drag a step onto a') }}
                    <span class="font-medium text-(--wf-muted)">+</span
                    >{{
                        $t(
                            ', or click one. Drag from a step’s bottom dot to connect it.',
                        )
                    }}
                    <kbd class="wf-kbd">⌫</kbd> {{ $t('deletes,') }}
                    <kbd class="wf-kbd">⌘Z</kbd> {{ $t('undoes.') + ' ' }}
                </p>
            </div>
        </div>
    </aside>
</template>
