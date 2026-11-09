<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { SolarClock as Clock } from '@/lib/solarIcons';
import type { PlannedMaintenance } from '@/components/machines/machines';
import { maintenanceWhen, startsIn } from '@/components/status/status';

/**
 * Maintenance planned on a machine: when, what ServerOS will do, whether
 * the status page shows it, and a way to call it off.
 */
const props = defineProps<{
    machineId: number;
    windows: PlannedMaintenance[];
    canManage: boolean;
}>();

const doing: Record<PlannedMaintenance['action'], string> = {
    none: '',
    reboot: trans('ServerOS restarts it'),
    updates: trans('ServerOS installs updates'),
};

const phase: Record<NonNullable<PlannedMaintenance['phase']>, string> = {
    updating: trans('Installing updates…'),
    rebooting: trans('Restarting, waiting for it to come back…'),
    back: trans('Back'),
};

function cancel(window: PlannedMaintenance): void {
    if (
        window.state === 'active' &&
        !confirm(
            trans(
                'End this maintenance now? Alerts come back on straight away.',
            ),
        )
    )
        return;
    router.delete(
        `/machines/${props.machineId}/maintenance/scheduled/${window.id}`,
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <div
        v-for="window in windows"
        :key="window.id"
        class="ds-tile flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-[13px]/5 shadow-[inset_3px_0_0_var(--ds-blue)]"
        role="status"
    >
        <Clock class="size-4 shrink-0 text-(--ds-blue)" :stroke-width="1.75" />
        <span class="font-medium">
            {{
                window.state === 'active'
                    ? $t('Planned maintenance under way')
                    : $t('Maintenance planned :when', {
                          when: startsIn(window.startsAt),
                      })
            }}
        </span>
        <span class="text-(--ds-text-secondary)">{{
            maintenanceWhen(window.startsAt, window.endsAt)
        }}</span>
        <span v-if="window.reason" class="text-(--ds-text-secondary)">{{
            window.reason
        }}</span>
        <span class="text-(--ds-text-muted)">
            {{
                [
                    window.state === 'active' && window.phase
                        ? phase[window.phase]
                        : doing[window.action],
                    window.public
                        ? $t('On the status page')
                        : $t('Not on the status page'),
                    window.outcome,
                ]
                    .filter(Boolean)
                    .join(' · ')
            }}
        </span>
        <button
            v-if="canManage"
            type="button"
            class="ml-auto cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
            @click="cancel(window)"
        >
            {{ window.state === 'active' ? $t('End now') : $t('Call off') }}
        </button>
    </div>
</template>
