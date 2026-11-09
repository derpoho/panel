<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { CircleDashed, CircleMinus, LoaderCircle } from '@lucide/vue';
import {
    SolarCircleCheck as CircleCheck,
    SolarCircleX as CircleX,
} from '@/lib/solarIcons';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import MorphHeight from '@/components/workspace/MorphHeight.vue';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import {
    rolloutOpen,
    rolloutStatus,
    sendJson,
    targetStatus,
    toneText,
} from './updates';
import type { RolloutDetail, RolloutTarget } from './updates';

/**
 * One rollout: its plan, how far it has got, and each machine's part,
 * kept up to date while it runs. Cancelling stops what hasn't started.
 */
const props = defineProps<{ rolloutId: number | null; canManage: boolean }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ changed: [] }>();

const rollout = ref<RolloutDetail | null>(null);
const error = ref<string | null>(null);
const cancelling = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    clearTimeout(timer);
    if (!open.value || props.rolloutId === null) return;
    try {
        const { rollout: fresh } = await sendJson<{ rollout: RolloutDetail }>(
            'GET',
            `/updates/rollouts/${props.rolloutId}`,
        );
        const wasOpen = rollout.value ? rolloutOpen(rollout.value) : false;
        rollout.value = fresh;
        error.value = null;
        if (wasOpen && !rolloutOpen(fresh)) emit('changed');
    } catch (cause) {
        error.value = (cause as Error).message;
    }
    if (open.value && rollout.value && rolloutOpen(rollout.value)) {
        timer = setTimeout(load, 3000);
    }
}

watch(
    [open, () => props.rolloutId],
    ([value]) => {
        if (value) {
            rollout.value = null;
            void load();
        } else {
            clearTimeout(timer);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

async function cancel(): Promise<void> {
    if (!rollout.value || cancelling.value) return;
    cancelling.value = true;
    try {
        const { rollout: fresh } = await sendJson<{ rollout: RolloutDetail }>(
            'DELETE',
            `/updates/rollouts/${rollout.value.id}`,
        );
        rollout.value = fresh;
        toast.success(
            trans('Rollout cancelled. Installs already running finish.'),
        );
        emit('changed');
        void load();
    } catch (cause) {
        toast.error((cause as Error).message);
    } finally {
        cancelling.value = false;
    }
}

const icons: Record<RolloutTarget['state'], Component> = {
    pending: CircleDashed,
    running: LoaderCircle,
    done: CircleCheck,
    failed: CircleX,
    skipped: CircleMinus,
};

/* "Latest versions · 2 at a time, stops if one fails · restarts if needed". */
const plan = computed(() => {
    const r = rollout.value;
    if (!r) return '';
    return [
        r.everything
            ? trans('Every waiting update')
            : r.mode === 'exact'
              ? trans('Exact versions')
              : trans('Latest versions'),
        r.batchSize
            ? r.stopOnFailure
                ? trans(':count at a time, stops if one fails', {
                      count: String(r.batchSize),
                  })
                : trans(':count at a time', { count: String(r.batchSize) })
            : trans('All at once'),
        r.reboot === 'if_required'
            ? trans('Restarts if needed')
            : trans('No restarts'),
        r.scheduledFor && r.state === 'pending'
            ? trans('Starts :when', {
                  when: new Date(r.scheduledFor).toLocaleString(),
              })
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

const packages = computed(() =>
    (rollout.value?.packages ?? [])
        .map((p) => (p.version ? `${p.name} ${p.version}` : p.name))
        .join(', '),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle class="truncate">{{
                    rollout?.name ?? $t('Rollout')
                }}</DialogTitle>
                <DialogDescription>
                    {{ plan || ' ' }}
                </DialogDescription>
            </DialogHeader>

            <MorphHeight>
                <p
                    v-if="!rollout && !error"
                    class="flex items-center gap-2 py-4 text-[13px]/5 text-(--ds-text-muted)"
                    role="status"
                >
                    <LoaderCircle
                        class="size-3.5 animate-spin motion-reduce:animate-none"
                        :stroke-width="2"
                    />
                    {{ $t('Loading…') }}
                </p>
                <p v-else-if="error" class="py-4 text-[13px]/5 text-(--ds-red)">
                    {{ error }}
                </p>
                <div v-else-if="rollout" class="flex flex-col gap-4">
                    <p
                        class="flex flex-wrap items-center gap-x-2 text-[13px]/5"
                    >
                        <span
                            class="font-medium"
                            :class="toneText[rolloutStatus(rollout).tone]"
                            >{{ rolloutStatus(rollout).label }}</span
                        >
                        <span class="text-(--ds-text-muted) tabular-nums">
                            {{
                                $t(':finished of :total done', {
                                    finished: String(rollout.finished),
                                    total: String(rollout.total),
                                })
                            }}
                        </span>
                        <span
                            v-if="rollout.failed"
                            class="text-(--ds-red) tabular-nums"
                            >·
                            {{
                                $t(':count failed', {
                                    count: String(rollout.failed),
                                })
                            }}</span
                        >
                        <span
                            v-if="rollout.skipped"
                            class="text-(--ds-text-muted) tabular-nums"
                            >·
                            {{
                                $t(':count skipped', {
                                    count: String(rollout.skipped),
                                })
                            }}</span
                        >
                    </p>
                    <p
                        v-if="packages"
                        class="truncate font-ui-mono text-xs/5 text-(--ds-text-muted)"
                        :title="packages"
                    >
                        {{ packages }}
                    </p>

                    <ul
                        class="ds-tile ds-tile-rows flex max-h-[50svh] flex-col overflow-y-auto"
                    >
                        <li
                            v-for="machine in rollout.machines"
                            :key="machine.machineId"
                            class="ds-tile-row"
                        >
                            <div
                                class="flex min-h-11 items-start gap-3 px-4 py-2.5 text-[13.5px]/5"
                            >
                                <component
                                    :is="icons[machine.state]"
                                    class="mt-0.5 size-4 shrink-0"
                                    :class="[
                                        toneText[targetStatus(machine).tone],
                                        {
                                            'animate-spin motion-reduce:animate-none':
                                                targetStatus(machine).busy,
                                        },
                                    ]"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="truncate font-medium">{{
                                        machine.name
                                    }}</span>
                                    <span
                                        v-if="machine.outcome"
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                        >{{ machine.outcome }}</span
                                    >
                                </div>
                                <span
                                    class="ml-auto flex shrink-0 items-center gap-3 text-[13px] tabular-nums"
                                >
                                    <span
                                        :class="
                                            toneText[targetStatus(machine).tone]
                                        "
                                        >{{ targetStatus(machine).label }}</span
                                    >
                                    <span
                                        class="hidden w-24 text-right text-(--ds-text-muted) sm:inline"
                                        >{{
                                            machine.finishedAt ||
                                            machine.startedAt
                                                ? timeAgo(
                                                      machine.finishedAt ??
                                                          machine.startedAt,
                                                  )
                                                : ''
                                        }}</span
                                    >
                                </span>
                            </div>
                        </li>
                    </ul>

                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                rollout.by
                                    ? $t('Started by :name, :when', {
                                          name: rollout.by,
                                          when: timeAgo(rollout.createdAt),
                                      })
                                    : timeAgo(rollout.createdAt)
                            }}
                        </span>
                        <div class="flex items-center gap-2">
                            <AppButton
                                v-if="canManage && rolloutOpen(rollout)"
                                size="sm"
                                :disabled="cancelling"
                                @click="cancel"
                            >
                                {{ $t('Cancel rollout') }}
                            </AppButton>
                            <AppButton size="sm" @click="open = false">
                                {{ $t('Close') }}
                            </AppButton>
                        </div>
                    </div>
                </div>
            </MorphHeight>
        </DialogContent>
    </Dialog>
</template>
