<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { PanelLeft, PanelTop } from '@lucide/vue';
import { useNavLayout } from '@/composables/useNavLayout';

/** Settings › Appearance: the dashboard's sections along the top or down the left. */
const { layout, setLayout } = useNavLayout();

const options = [
    { value: 'top', Icon: PanelTop, label: trans('Top bar') },
    { value: 'sidebar', Icon: PanelLeft, label: trans('Sidebar') },
] as const;
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="$t('Navigation')"
        class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="{ value, Icon, label } in options"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="layout === value"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                layout === value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
            @click="setLayout(value)"
        >
            <component :is="Icon" class="-ml-1 h-4 w-4" :stroke-width="1.75" />
            <span class="ml-1.5 text-sm">{{ label }}</span>
        </button>
    </div>
</template>
