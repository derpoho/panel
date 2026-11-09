<script setup lang="ts">
import { SolarLock as Lock } from '@/lib/solarIcons';
import { computed } from 'vue';
import { policySends } from '@/lib/machinePolicies';
import type { PolicySettings } from '@/lib/machinePolicies';

/**
 * What a server following these settings sends to ServerOS, and what
 * stays on it: the honest list next to the switches, in the connect flows
 * and on a machine's settings. One row each, the answer on the right.
 */
const props = defineProps<{
    settings: PolicySettings;
    /** A heading inside the card, e.g. the machine's name. */
    heading?: string | null;
}>();

const lists = computed(() => policySends(props.settings));
</script>

<template>
    <div class="ds-tile ds-tile-rows flex flex-col">
        <div
            v-if="heading"
            class="ds-tile-row px-4 py-2.5 text-[13.5px]/5 font-medium"
        >
            {{ heading }}
        </div>
        <div
            v-for="line in [
                ...lists.sent.map((item) => ({ ...item, sent: true })),
                ...lists.kept.map((item) => ({ ...item, sent: false })),
            ]"
            :key="line.key"
            class="ds-tile-row flex items-center gap-4 px-4 py-2.5"
            :title="
                line.sent ? $t('Sent to ServerOS') : $t('Stays on your server')
            "
        >
            <span class="flex min-w-0 grow flex-col">
                <span class="text-[13.5px]/5 font-medium">
                    {{ line.title }}
                </span>
                <span class="text-[12.5px]/4.5 text-(--ds-text-muted)">
                    {{ line.body }}
                </span>
            </span>
            <span
                v-if="line.sent"
                class="flex shrink-0 items-center gap-1.5 text-[12.5px]/4 text-(--ds-text-secondary)"
            >
                <span
                    class="size-1.5 rounded-full bg-(--ds-green)"
                    aria-hidden="true"
                />
                {{ $t('Sent') }}
            </span>
            <span
                v-else
                class="flex shrink-0 items-center gap-1.5 text-[12.5px]/4 text-(--ds-text-muted)"
            >
                <Lock class="size-3" :stroke-width="2" aria-hidden="true" />
                {{ $t('Stays on server') }}
            </span>
        </div>
    </div>
</template>
