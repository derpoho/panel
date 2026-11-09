<script setup lang="ts">
import { statusLabels, statusTone } from '@/components/status/status';
import type { ComponentStatus } from '@/components/status/status';

/** A component's state, as a dot and a word. */
defineProps<{ status: ComponentStatus }>();
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.75 text-[13px]/4 font-medium"
        :class="statusTone[status].text"
    >
        <span
            class="size-1.75 rounded-full"
            :class="[
                statusTone[status].dot,
                { 'animate-pulse': status === 'down' },
            ]"
            aria-hidden="true"
        />
        {{ statusLabels[status] }}
    </span>
</template>
