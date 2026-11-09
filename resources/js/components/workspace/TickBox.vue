<script setup lang="ts">
/**
 * The dashboard's checkbox, matching the machine table rows: a thin bordered
 * square that fills and draws a tick when checked. The real input stays in
 * the page (visually hidden) so labels, forms and keyboards just work.
 */
const checked = defineModel<boolean>({ default: false });

defineProps<{ disabled?: boolean }>();
</script>

<template>
    <span class="relative inline-flex size-4 shrink-0">
        <input
            v-model="checked"
            type="checkbox"
            :disabled="disabled"
            class="peer absolute inset-0 size-full cursor-pointer appearance-none rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:cursor-not-allowed"
        />
        <span
            class="pointer-events-none flex size-4 items-center justify-center rounded-sm transition-colors duration-150"
            :class="
                checked
                    ? 'bg-(--ds-inverse)'
                    : 'border-[1.5px] border-(--ds-border-hover) peer-hover:border-(--ds-border-focus)'
            "
            aria-hidden="true"
        >
            <svg v-if="checked" width="10" height="10" viewBox="0 0 10 10">
                <path
                    class="mi-draw stroke-(--ds-inverse-text)"
                    pathLength="1"
                    d="M2 5.2L4.2 7.2L8 3"
                    fill="none"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        </span>
    </span>
</template>
