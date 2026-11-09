<script setup lang="ts">
import { computed } from 'vue';
import { useIsDark } from '@/composables/useAppearance';
import { providerBrand } from '@/lib/providerBrand';

/**
 * A provider's name with its logo beside it, when we know the logo. With
 * logo-only, just the mark (a bit larger), the name kept for screen readers.
 */
const props = defineProps<{
    name: string | null | undefined;
    logoOnly?: boolean;
}>();

const brand = computed(() => providerBrand(props.name));
const isDark = useIsDark();

/** Brand colours too dark for the dark theme fall back to the text colour. */
const colour = computed(() => {
    const hex = brand.value?.hex;
    if (!hex) return undefined;
    const [r, g, b] = [0, 2, 4].map(
        (i) => parseInt(hex.slice(i, i + 2), 16) / 255,
    );
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    return isDark.value && luminance < 0.2 ? undefined : `#${hex}`;
});
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2">
        <svg
            v-if="brand"
            viewBox="0 0 24 24"
            class="shrink-0"
            :class="logoOnly ? 'size-4' : 'size-3.5'"
            :style="colour ? { color: colour } : undefined"
            fill="currentColor"
            aria-hidden="true"
        >
            <path :d="brand.path" />
        </svg>
        <span :class="logoOnly && brand ? 'sr-only' : 'truncate'">{{
            name === 'Other' ? $t('Other') : name
        }}</span>
    </span>
</template>
