<script setup lang="ts">
import type { Check } from '@/lib/install';
import StatusIcon from './StatusIcon.vue';

/* What a check found, a line each. */
defineProps<{ lines: Check[] }>();
</script>

<template>
    <ul
        class="flex flex-col gap-2 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13.5px]/5"
    >
        <li v-for="line in lines" :key="line.text" class="flex gap-3">
            <StatusIcon :status="line.status" class="mt-0.5" />
            <span>{{ line.text }}</span>
        </li>
    </ul>
</template>
