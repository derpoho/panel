<script setup lang="ts">
import { SolarBox as Box } from '@/lib/solarIcons';
import { computed } from 'vue';
import { serviceBrand, serviceBrandColour } from '@/lib/serviceBrand';

/** A discovered service's brand mark; see serviceBrand for the matching. */
const props = defineProps<{
    name: string;
    kind?: string;
    serviceKey?: string;
    /** The container image, e.g. "clickhouse/clickhouse-server:24.8". */
    image?: string | null;
    /** An icon someone uploaded, shown instead of the brand mark. */
    iconUrl?: string | null;
}>();

const brand = computed(() => serviceBrand(props.name, props.image));

const colour = computed(() => serviceBrandColour(brand.value));
</script>

<template>
    <span
        class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-(--ds-border-strong) bg-(--ds-surface)"
        :title="brand?.title ?? (kind || $t('Service'))"
        aria-hidden="true"
    >
        <img
            v-if="iconUrl"
            :src="iconUrl"
            alt=""
            class="size-full rounded-[inherit] object-cover"
        />
        <svg
            v-else-if="brand"
            viewBox="0 0 24 24"
            class="size-5"
            :class="{ 'text-(--ds-text)': !colour }"
            :style="colour ? { color: colour } : undefined"
            fill="currentColor"
        >
            <path :d="brand.path" />
        </svg>
        <Box
            v-else
            class="size-4.5 text-(--ds-text-muted)"
            :stroke-width="1.6"
        />
    </span>
</template>
