<script setup lang="ts">
/* Marks how this browser last signed in, on the sign-in page. */
</script>

<template>
    <span
        class="rounded-full bg-(--ds-hover) px-1.5 text-[11px]/4 font-medium text-(--ds-text-muted)"
        data-test="last-used"
        >{{ $t('Last used') }}</span
    >
</template>
