<script setup lang="ts">
import {
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';

/**
 * The name of an icon-only button on hover or focus, in the panel's own
 * menu surface: a label, and sometimes a quieter line under it. Wraps the
 * button (as-child), so it keeps its own focus and click.
 */
withDefaults(
    defineProps<{
        label: string;
        hint?: string | null;
        side?: 'top' | 'bottom' | 'left' | 'right';
        align?: 'start' | 'center' | 'end';
    }>(),
    { hint: null, side: 'bottom', align: 'center' },
);
</script>

<template>
    <TooltipProvider :delay-duration="150" :skip-delay-duration="300">
        <TooltipRoot>
            <TooltipTrigger as-child>
                <slot />
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side="side"
                    :align="align"
                    :side-offset="6"
                    :collision-padding="8"
                    class="ds-modal z-50 max-w-60 rounded-lg! px-2.5 py-1.5 font-ui text-[12.5px]/4.5 text-(--ds-text) data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=delayed-open]:animate-in data-[state=delayed-open]:fade-in-0"
                >
                    <span class="block font-medium">{{ label }}</span>
                    <span
                        v-if="hint || $slots.hint"
                        class="mt-0.5 flex items-center gap-1.5 text-(--ds-text-muted)"
                    >
                        <slot name="hint">{{ hint }}</slot>
                    </span>
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
