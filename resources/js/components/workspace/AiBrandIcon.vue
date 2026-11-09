<script setup lang="ts">
import { computed } from 'vue';
import { AI_BRANDS } from '@/lib/aiBrands';
import type { AiBrand, AiBrandMark } from '@/lib/aiBrands';

/** An AI app's mark, inline so it follows the text colour and theme. */
const props = defineProps<{ brand: AiBrand; label?: boolean }>();

const mark = computed<AiBrandMark>(() => AI_BRANDS[props.brand]);
</script>

<template>
    <svg
        :viewBox="mark.viewBox"
        fill="currentColor"
        xmlns="http://www.w3.org/2000/svg"
        :role="label ? 'img' : undefined"
        :aria-label="label ? mark.title : undefined"
        :aria-hidden="label ? undefined : 'true'"
    >
        <path
            v-for="(path, i) in mark.paths"
            :key="i"
            :d="path.d"
            :fill="path.fill"
            :fill-rule="path.rule"
            :clip-rule="path.rule"
        />
    </svg>
</template>
