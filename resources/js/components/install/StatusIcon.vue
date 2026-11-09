<script setup lang="ts">
import { CircleCheck, CircleX, TriangleAlert } from '@lucide/vue';
import type { Status } from './types';

defineProps<{ status: Status }>();
</script>

<template>
    <CircleCheck
        v-if="status === 'ok'"
        class="size-4 shrink-0 text-(--ds-green)"
        :stroke-width="2"
        :aria-label="$t('Good')"
    />
    <TriangleAlert
        v-else-if="status === 'warn'"
        class="size-4 shrink-0 text-(--ds-amber)"
        :stroke-width="2"
        :aria-label="$t('Worth a look')"
    />
    <CircleX
        v-else
        class="size-4 shrink-0 text-(--ds-red)"
        :stroke-width="2"
        :aria-label="$t('Needs fixing')"
    />
</template>
