<script setup lang="ts">
/**
 * One line of a settings list inside a tile: the label and a short hint on
 * the left, the control on the right. Stacks on small screens.
 */
defineProps<{
    label: string;
    hint?: string;
    /** The input the label names, when there's a single one. */
    inputId?: string;
}>();
</script>

<template>
    <div
        class="ds-tile-row grid items-start gap-x-10 gap-y-2.5 px-5 py-4 md:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]"
    >
        <div class="flex min-w-0 flex-col gap-0.5 md:pt-1.5">
            <label
                v-if="inputId"
                :for="inputId"
                class="text-[13px]/5 font-medium"
            >
                {{ label }}
            </label>
            <span v-else class="text-[13px]/5 font-medium">{{ label }}</span>
            <p v-if="hint" class="text-[13px]/5 text-(--ds-text-muted)">
                {{ hint }}
            </p>
        </div>
        <div class="flex min-w-0 flex-col gap-2">
            <slot />
        </div>
    </div>
</template>
