<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { SolarWrench as Wrench } from '@/lib/solarIcons';
import { computed } from 'vue';

/**
 * Shown while something is in maintenance or muted: until when, why, and a
 * way to end it early.
 */
const props = defineProps<{
    until: string;
    reason?: string | null;
    /** Where DELETE ends it. */
    endUrl: string;
    canManage: boolean;
    noun?: string;
}>();

const time = computed(() =>
    new Date(props.until).toLocaleString(undefined, {
        weekday:
            new Date(props.until).toDateString() === new Date().toDateString()
                ? undefined
                : 'short',
        hour: '2-digit',
        minute: '2-digit',
    }),
);

function end(): void {
    router.delete(props.endUrl, { preserveScroll: true });
}
</script>

<template>
    <div
        class="ds-tile flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-[13px]/5 shadow-[inset_3px_0_0_var(--ds-amber)]"
        role="status"
    >
        <Wrench
            class="size-4 shrink-0 text-(--ds-amber)"
            :stroke-width="1.75"
        />
        <span class="font-medium">{{
            $t(':value until :time', {
                value: noun ?? $t('In maintenance'),
                time,
            })
        }}</span>
        <span v-if="reason" class="text-(--ds-text-secondary)">{{
            reason
        }}</span>
        <span class="text-(--ds-text-muted)">{{
            $t('Alerts and automatic workflows are held.')
        }}</span>
        <button
            v-if="canManage"
            type="button"
            class="ml-auto cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
            @click="end"
        >
            {{ ' ' + $t('End now') + ' ' }}
        </button>
    </div>
</template>
