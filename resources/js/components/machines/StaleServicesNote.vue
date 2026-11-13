<script setup lang="ts">
import { SolarRotateCw as RotateCw } from '@/lib/solarIcons';
import { computed } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';

export type Restarts = {
    services: {
        unit: string;
        processes: number;
        files: string[];
        disruptive: boolean;
    }[];
    systemd: boolean;
    reboot: { kind: string; detail: string }[];
    later: string[];
    kernel: string | null;
    newestKernel: string | null;
};

const props = defineProps<{ restarts: Restarts; busy?: boolean }>();
const emit = defineEmits<{ restart: [] }>();

const name = (unit: string) => unit.replace(/\.service$/, '');

const safe = computed(() =>
    props.restarts.services.filter((s) => !s.disruptive).map((s) => s.unit),
);
const disruptive = computed(() =>
    props.restarts.services.filter((s) => s.disruptive).map((s) => s.unit),
);
const names = computed(() => [
    ...safe.value.map(name),
    ...(props.restarts.systemd ? ['systemd'] : []),
]);
const count = computed(() => names.value.length);
const kernel = computed(() =>
    props.restarts.reboot.find((r) => r.kind === 'kernel'),
);
</script>

<template>
    <div
        v-if="count || disruptive.length || kernel"
        class="flex flex-col gap-2 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13px]/5"
    >
        <template v-if="count">
            <p class="font-medium">
                {{
                    count === 1
                        ? $t('1 service still runs the old files')
                        : $t(':count services still run the old files', {
                              count: String(count),
                          })
                }}
            </p>
            <p class="text-(--ds-text-secondary)">
                {{ names.join(', ') }}
            </p>
            <p class="text-(--ds-text-muted)">
                {{
                    $t(
                        'Restarting them applies the update, no reboot needed. Each one is down for a moment.',
                    )
                }}
            </p>
        </template>
        <p v-if="disruptive.length" class="text-(--ds-text-muted)">
            {{
                disruptive.length === 1
                    ? $t(
                          'Left out: :name, since restarting it restarts every container or VM it runs.',
                          { name: name(disruptive[0]) },
                      )
                    : $t(
                          'Left out: :names, since restarting them restarts every container or VM they run.',
                          { names: disruptive.map(name).join(', ') },
                      )
            }}
        </p>
        <p v-if="kernel" class="text-(--ds-amber)">
            {{
                $t(
                    'A newer kernel is installed (:newest). Only restarting the machine starts it.',
                    { newest: restarts.newestKernel ?? kernel.detail },
                )
            }}
        </p>
        <div v-if="count" class="flex justify-end">
            <AppButton
                size="sm"
                variant="primary"
                :disabled="busy"
                @click="emit('restart')"
            >
                <RotateCw class="size-3.5 opacity-70" :stroke-width="1.75" />
                {{
                    count === 1
                        ? $t('Restart it')
                        : $t('Restart :count services', {
                              count: String(count),
                          })
                }}
            </AppButton>
        </div>
    </div>
</template>
