<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-6 flex flex-col gap-1.5">
        <h1 class="text-[18px]/6 font-semibold tracking-[-0.01em]">
            {{ title }}
        </h1>
        <p
            v-if="description"
            class="text-[13.5px]/5 text-pretty text-(--ds-text-muted)"
        >
            {{ description }}
        </p>
    </div>
</template>
