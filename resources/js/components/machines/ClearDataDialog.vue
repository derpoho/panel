<script setup lang="ts">
/**
 * Confirm clearing what machines have sent ServerOS: logs, usage history,
 * discovered services. One machine (its settings) or several (picked on
 * Machines). Clearing everything from one machine needs its name typed,
 * like removing it. The machines stay connected either way.
 */
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';

export type DataKind = 'logs' | 'metrics' | 'services';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    machines: { id: number; name: string }[];
    /** Ticked when the dialog opens. */
    kinds: DataKind[];
    /** How much of each there is, for one machine. */
    sizes?: Partial<Record<DataKind, string>>;
}>();

const emit = defineEmits<{ done: [] }>();

const allKinds: DataKind[] = ['logs', 'metrics', 'services'];

const labels = computed<Record<DataKind, string>>(() => ({
    logs: trans('Logs'),
    metrics: trans('Usage history'),
    services: trans('Discovered services'),
}));

const hints = computed<Record<DataKind, string>>(() => ({
    logs: trans('Every log line it has sent, and the chart’s counts of them.'),
    metrics: trans(
        'CPU, memory, disk and per-service usage charts. They start again from now.',
    ),
    services: trans(
        'Services nobody adopted or deployed, open ports and firewall rules. ServerOS will find what’s running again on the next scan.',
    ),
}));

const chosen = ref<DataKind[]>([]);
const confirmation = ref('');
const processing = ref(false);
const errors = ref<Record<string, string>>({});

watch(open, (isOpen) => {
    if (isOpen) {
        chosen.value = [...props.kinds];
        confirmation.value = '';
        errors.value = {};
    }
});

function toggle(kind: DataKind, on: boolean): void {
    chosen.value = on
        ? allKinds.filter((k) => k === kind || chosen.value.includes(k))
        : chosen.value.filter((k) => k !== kind);
}

const single = computed(() =>
    props.machines.length === 1 ? props.machines[0] : null,
);
const everything = computed(() => chosen.value.length === allKinds.length);
const needsName = computed(() => single.value !== null && everything.value);

const title = computed(() => {
    if (!single.value) {
        return trans('Clear collected data from :count machines?', {
            count: String(props.machines.length),
        });
    }
    if (everything.value || chosen.value.length !== 1) {
        return trans('Clear what :name has collected?', {
            name: single.value.name,
        });
    }

    const titles: Record<DataKind, string> = {
        logs: trans('Clear logs from :name?', { name: single.value.name }),
        metrics: trans('Clear usage history from :name?', {
            name: single.value.name,
        }),
        services: trans('Clear discovered services from :name?', {
            name: single.value.name,
        }),
    };

    return titles[chosen.value[0]];
});

function submit(): void {
    if (processing.value || chosen.value.length === 0) return;
    if (needsName.value && confirmation.value !== single.value?.name) return;

    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onError: (bag: Record<string, string>) => (errors.value = bag),
        onSuccess: () => {
            open.value = false;
            emit('done');
        },
    };

    if (single.value) {
        router.post(
            `/machines/${single.value.id}/collected-data`,
            { kinds: chosen.value, confirm: confirmation.value },
            options,
        );
    } else {
        router.post(
            '/machines/collected-data',
            { machines: props.machines.map((m) => m.id), kinds: chosen.value },
            options,
        );
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{
                        single
                            ? $t(
                                  'It stays connected, and its settings, adopted services, deploys and backups are kept. This can’t be undone.',
                              )
                            : $t(
                                  'They stay connected, and their settings, adopted services, deploys and backups are kept. This can’t be undone.',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <ul
                    v-if="!single"
                    class="max-h-28 overflow-y-auto text-[13px]/5 text-(--ds-text-secondary)"
                    :aria-label="$t('Machines')"
                >
                    <li
                        v-for="machine in machines"
                        :key="machine.id"
                        class="truncate font-ui-mono"
                    >
                        {{ machine.name }}
                    </li>
                </ul>

                <ul
                    class="overflow-hidden rounded-[10px] border border-(--ds-border)"
                >
                    <li
                        v-for="kind in allKinds"
                        :key="kind"
                        class="border-t border-(--ds-border) first:border-t-0"
                    >
                        <label
                            class="flex cursor-pointer items-start gap-3 px-3 py-2.5 text-[13px]/5 hover:bg-(--ds-row-hover)"
                        >
                            <span class="pt-0.5">
                                <TickBox
                                    :model-value="chosen.includes(kind)"
                                    @update:model-value="
                                        (on) => toggle(kind, on)
                                    "
                                />
                            </span>
                            <span class="flex min-w-0 grow flex-col">
                                <span
                                    class="flex flex-wrap items-baseline gap-x-2"
                                >
                                    <span class="font-medium">{{
                                        labels[kind]
                                    }}</span>
                                    <span
                                        v-if="sizes?.[kind]"
                                        class="text-xs text-(--ds-text-muted) tabular-nums"
                                        >{{ sizes[kind] }}</span
                                    >
                                </span>
                                <span class="text-(--ds-text-muted)">{{
                                    hints[kind]
                                }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <InputError :message="errors.kinds ?? errors.machines" />

                <div v-if="needsName" class="grid gap-2">
                    <label for="clear-data-confirm" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            single?.name
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <Input
                        id="clear-data-confirm"
                        v-model="confirmation"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                    <InputError :message="errors.confirm" />
                </div>

                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="open = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Clear')"
                        :busy-label="$t('Clearing…')"
                        :processing="processing"
                        :disabled="
                            !processing &&
                            (chosen.length === 0 ||
                                (needsName && confirmation !== single?.name))
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
