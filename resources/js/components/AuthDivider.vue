<script setup lang="ts">
const { label = 'or' } = defineProps<{ label?: string }>();
</script>

<template>
    <div class="my-6 flex items-center gap-3" role="separator">
        <span class="h-px grow bg-(--ds-divider)" />
        <span class="text-[13px]/4 text-(--ds-text-faint)">{{ label }}</span>
        <span class="h-px grow bg-(--ds-divider)" />
    </div>
</template>
