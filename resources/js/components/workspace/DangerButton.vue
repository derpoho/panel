<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import { appButtonClasses } from '@/lib/appButton';

type Props = {
    label: string;
    busyLabel?: string;
    /** The request is in flight: sweep and swap the label. */
    processing?: boolean;
    disabled?: boolean;
    /** Changes whenever a new error comes back, to trigger a shake. */
    error?: string | null;
};

const {
    label,
    busyLabel = trans('Deleting…'),
    processing = false,
    disabled = false,
    error = null,
} = defineProps<Props>();

const shaking = ref(false);

watch(
    () => error,
    (message) => {
        if (!message) {
            return;
        }

        shaking.value = false;
        requestAnimationFrame(() => (shaking.value = true));
    },
);
</script>

<template>
    <button
        type="submit"
        class="danger-button group/danger relative overflow-hidden"
        :class="[
            appButtonClasses(
                'primary',
                'sm',
                'border-(--ds-red)/50 bg-[#B42318] hover:border-(--ds-red) hover:bg-[#C8321F] active:scale-[0.97] motion-reduce:active:scale-100',
            ),
            {
                'danger-button--busy': processing,
                'danger-button--shake': shaking,
            },
        ]"
        :disabled="disabled"
        :aria-disabled="processing || undefined"
        :aria-busy="processing || undefined"
        @animationend="shaking = false"
        @click="processing && $event.preventDefault()"
    >
        <!-- A brighter red sweeping across while the request runs. -->
        <span
            class="danger-button__sweep pointer-events-none absolute inset-0"
            aria-hidden="true"
        />

        <span class="relative flex items-center gap-1.5">
            <svg
                viewBox="0 0 24 24"
                class="size-3.5 shrink-0 overflow-visible"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <g class="danger-button__lid">
                    <path d="M3 6h18" />
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                </g>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                <path d="M10 11v6" />
                <path d="M14 11v6" />
            </svg>

            <!-- Labels share one grid cell and blur-fade between each other.
                 The cell is as wide as the longer label, so "Clearing…"
                 never gets cut off on a "Clear" button. -->
            <span class="grid">
                <span
                    class="col-start-1 row-start-1 block whitespace-nowrap transition-[opacity,filter,translate] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                    :class="
                        processing
                            ? '-translate-y-1 opacity-0 blur-[3px]'
                            : 'translate-y-0 opacity-100 blur-none'
                    "
                >
                    {{ label }}
                </span>
                <span
                    class="col-start-1 row-start-1 flex items-center whitespace-nowrap transition-[opacity,filter,translate] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                    :class="
                        processing
                            ? 'translate-y-0 opacity-100 blur-none'
                            : 'translate-y-1 opacity-0 blur-[3px]'
                    "
                    aria-hidden="true"
                >
                    {{ busyLabel }}
                </span>
            </span>
        </span>
    </button>
</template>
