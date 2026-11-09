<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { appButtonClasses } from '@/lib/appButton';
import type { AppButtonSize } from '@/lib/appButton';

/**
 * The primary submit button for dashboard forms, after DangerButton: a sheen
 * sweeps across while the request runs, and the label blur-fades from
 * "Save changes" to "Saving…" to "Saved" as a tick draws in.
 */
type Props = {
    label?: string;
    busyLabel?: string;
    doneLabel?: string;
    processing?: boolean;
    /** The last submit worked: show the done state for a moment. */
    done?: boolean;
    disabled?: boolean;
    size?: AppButtonSize;
};

const {
    label = trans('Save changes'),
    busyLabel = trans('Saving…'),
    doneLabel = trans('Saved'),
    processing = false,
    done = false,
    disabled = false,
    size = 'sm',
} = defineProps<Props>();

const state = computed(() => (processing ? 'busy' : done ? 'done' : 'idle'));

const swap =
    'transition-[opacity,filter,translate] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none';

function shown(which: 'idle' | 'busy' | 'done'): string {
    if (state.value === which) {
        return 'translate-y-0 opacity-100 blur-none';
    }

    // Leaving labels drift up, arriving ones come from below.
    const order = ['idle', 'busy', 'done'];

    return order.indexOf(which) < order.indexOf(state.value)
        ? '-translate-y-1 opacity-0 blur-[3px]'
        : 'translate-y-1 opacity-0 blur-[3px]';
}
</script>

<template>
    <button
        type="submit"
        class="relative overflow-hidden"
        :class="appButtonClasses('primary', size)"
        :disabled="disabled"
        :aria-disabled="processing || undefined"
        :aria-busy="processing || undefined"
        @click="processing && $event.preventDefault()"
    >
        <span
            v-if="processing"
            class="mi-sweep pointer-events-none absolute inset-0"
            aria-hidden="true"
        />

        <span class="relative grid items-center">
            <span
                class="col-start-1 row-start-1"
                :class="[swap, shown('idle')]"
            >
                {{ label }}
            </span>
            <span
                class="col-start-1 row-start-1 whitespace-nowrap"
                :class="[swap, shown('busy')]"
                aria-hidden="true"
            >
                {{ busyLabel }}
            </span>
            <span
                class="col-start-1 row-start-1 flex items-center gap-1.5 whitespace-nowrap"
                :class="[swap, shown('done')]"
                aria-hidden="true"
            >
                <svg
                    v-if="state === 'done'"
                    viewBox="0 0 24 24"
                    class="size-3.5 shrink-0"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        class="mi-draw"
                        pathLength="1"
                        d="M5 12.5 10 17.5 19 7"
                    />
                </svg>
                {{ doneLabel }}
            </span>
        </span>
        <span class="sr-only" aria-live="polite">
            {{
                state === 'busy' ? busyLabel : state === 'done' ? doneLabel : ''
            }}
        </span>
    </button>
</template>
