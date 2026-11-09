<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { useAssistant } from '@/composables/useAssistant';

/**
 * Hands a problem to Ask AI: opens it and asks, so the agent can look into
 * it and propose a fix for the person to approve.
 */
const props = defineProps<{ prompt: string; label?: string }>();

const { askAbout, streaming } = useAssistant();

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <button
        type="button"
        class="ds-raised-button flex shrink-0 cursor-pointer items-center gap-1.5 text-[13px]! disabled:cursor-default disabled:opacity-60"
        :class="focusRing"
        :disabled="streaming"
        :title="
            streaming
                ? $t('Ask AI is busy with your last message')
                : $t(
                      'Ask AI looks into it and proposes a fix for you to approve',
                  )
        "
        @click="askAbout(props.prompt)"
    >
        <Sparkles class="size-3.5 text-(--ds-blue)" :stroke-width="1.8" />
        {{ props.label ?? $t('Fix with Ask AI') }}
    </button>
</template>
