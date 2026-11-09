<script setup lang="ts">
import { computed } from 'vue';
import { environmentLabels } from '@/components/machines/machines';
import type { Environment } from '@/components/machines/machines';

type Props = {
    environment: Environment;
    /**
     * In the dashboard: follow the light / dark theme. Off on the landing
     * page preview, which is always dark.
     */
    themed?: boolean;
};

const props = defineProps<Props>();

const badgeShadowTail =
    '#00000059 0px -1px 0px inset, #00000080 0px 1px 2px, #00000099 0px 2px 6px -2px';

const variants: Record<
    Environment,
    {
        badgeClass: string;
        dotClass: string;
        textClass: string;
        style: Record<string, string>;
    }
> = {
    production: {
        badgeClass: 'border-[#2F4C80] bg-[#1B2A45]',
        dotClass: 'bg-[#60A5FA] shadow-[0_0_0_2px_#60A5FA33]',
        textClass: 'text-[#93BBFD]',
        style: {
            boxShadow: `#93BBFD38 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(33.3% -0.009 -0.070) 0%, oklab(26.7% -0.007 -0.054) 100%)',
        },
    },
    staging: {
        badgeClass: 'border-[#6B5220] bg-[#3A2C12]',
        dotClass: 'bg-[#FBBF24] shadow-[0_0_0_2px_#FBBF2433]',
        textClass: 'text-[#FCD34D]',
        style: {
            boxShadow: `#FCD34D33 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(34.5% 0.010 0.048) 0%, oklab(28.1% 0.005 0.041) 100%)',
        },
    },
    development: {
        badgeClass: 'border-[#3F3F43] bg-[#262628]',
        dotClass: 'bg-[#A1A1AA] shadow-[0_0_0_2px_#A1A1AA2E]',
        textClass: 'text-[#C4C4CC]',
        style: {
            boxShadow: `#FFFFFF1A 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(30.2% 0.001 -0.005) 0%, oklab(25.3% 0.001 -0.004) 100%)',
        },
    },
};

/**
 * Dashboard colours: light by default, the landing colours under `dark:`.
 * Spelled out in full so Tailwind can see every class.
 */
const themedVariants: Record<
    Environment,
    { badgeClass: string; dotClass: string; textClass: string }
> = {
    production: {
        badgeClass:
            'border-[#C3D5FB] bg-[#EEF4FF] dark:border-[#2F4C80] dark:bg-[#1B2A45]',
        dotClass:
            'bg-[#1F5FE0] shadow-[0_0_0_2px_#1F5FE026] dark:bg-[#60A5FA] dark:shadow-[0_0_0_2px_#60A5FA33]',
        textClass: 'text-[#1A4FBF] dark:text-[#93BBFD]',
    },
    staging: {
        badgeClass:
            'border-[#F3D58F] bg-[#FEF6E0] dark:border-[#6B5220] dark:bg-[#3A2C12]',
        dotClass:
            'bg-[#D97706] shadow-[0_0_0_2px_#D9770626] dark:bg-[#FBBF24] dark:shadow-[0_0_0_2px_#FBBF2433]',
        textClass: 'text-[#92400E] dark:text-[#FCD34D]',
    },
    development: {
        badgeClass:
            'border-[#E0E0DC] bg-[#F4F4F2] dark:border-[#3F3F43] dark:bg-[#262628]',
        dotClass:
            'bg-[#8E8E88] shadow-[0_0_0_2px_#8E8E8826] dark:bg-[#A1A1AA] dark:shadow-[0_0_0_2px_#A1A1AA2E]',
        textClass: 'text-[#52524E] dark:text-[#C4C4CC]',
    },
};

const variant = computed(() => {
    const landing = variants[props.environment];

    if (!props.themed) {
        return landing;
    }

    // The dark gradient and shadow go through custom properties so `dark:`
    // can switch them on; the light badge stays flat.
    return {
        ...themedVariants[props.environment],
        badgeClass: `${themedVariants[props.environment].badgeClass} shadow-[0_1px_2px_rgb(0_0_0/0.05)] dark:[box-shadow:var(--badge-shadow)] dark:[background-image:var(--badge-image)]`,
        style: {
            '--badge-shadow': landing.style.boxShadow ?? '',
            '--badge-image': landing.style.backgroundImage ?? '',
        },
    };
});
</script>

<template>
    <span
        class="flex items-center gap-1.5 rounded-[7px] border bg-origin-border py-0.75 pr-2.25 pl-2"
        :class="variant.badgeClass"
        :style="variant.style"
    >
        <span class="size-1.5 rounded-full" :class="variant.dotClass" />
        <span class="text-xs/4 font-medium" :class="variant.textClass">
            {{ environmentLabels[environment] }}
        </span>
    </span>
</template>
