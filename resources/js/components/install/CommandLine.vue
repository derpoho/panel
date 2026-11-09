<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';

/* A command to run on the server, with a button that copies it. */
const props = defineProps<{ command: string }>();

const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.command);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // No clipboard (plain http): it can still be selected.
    }
}
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-md border border-(--ds-border) bg-(--ds-code) py-1.5 pr-1.5 pl-3"
    >
        <code
            class="min-w-0 grow overflow-x-auto font-ui-mono text-xs/5 whitespace-nowrap text-(--ds-text-secondary) select-all"
            >{{ command }}</code
        >
        <button
            type="button"
            class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-[5px] text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            :aria-label="$t('Copy')"
            @click="copy"
        >
            <Check v-if="copied" class="size-3.5" :stroke-width="2" />
            <Copy v-else class="size-3.5" :stroke-width="2" />
        </button>
    </div>
</template>
