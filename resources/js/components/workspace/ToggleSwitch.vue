<script setup lang="ts">
/**
 * An on/off switch: a pill whose knob slides across and fills with the
 * inverse colour when on, like TickBox for settings that apply at once.
 */
defineProps<{
    label: string;
    disabled?: boolean;
}>();

const on = defineModel<boolean>({ default: false });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="on"
        :aria-label="label"
        :disabled="disabled"
        class="relative inline-flex h-5 w-8.5 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:cursor-not-allowed disabled:opacity-50"
        :class="
            on
                ? 'bg-(--ds-inverse)'
                : 'bg-(--ds-active) ring-1 ring-(--ds-border-hover) ring-inset'
        "
        @click="on = !on"
    >
        <span
            class="pointer-events-none absolute top-0.5 left-0.5 size-4 rounded-full shadow-sm transition-transform duration-200 ease-out"
            :class="
                on ? 'translate-x-3.5 bg-(--ds-inverse-text)' : 'bg-(--ds-card)'
            "
            aria-hidden="true"
        />
    </button>
</template>
