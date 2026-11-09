<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import { appButtonClasses } from '@/lib/appButton';
import type { AppButtonSize, AppButtonVariant } from '@/lib/appButton';

type Props = {
    variant?: AppButtonVariant;
    size?: AppButtonSize;
    /** Renders an Inertia link instead of a button. */
    href?: InertiaLinkProps['href'];
    type?: 'button' | 'submit';
    disabled?: boolean;
};

const {
    variant = 'secondary',
    size = 'md',
    href,
    type = 'button',
    disabled = false,
} = defineProps<Props>();

const classes = computed(() => appButtonClasses(variant, size));
</script>

<template>
    <Link v-if="href !== undefined" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
    </button>
</template>
