<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import MorphHeight from '@/components/workspace/MorphHeight.vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import type { PickerOption } from '@/components/workspace/PickerMenu.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { toast } from '@/lib/toast';
import { sendJson } from './updates';
import type {
    MachineGroupChoice,
    Rollout,
    UpdatesMachine,
    UpdatesPackage,
} from './updates';

/**
 * Rolling chosen updates out to machines: the latest of each, or exact
 * versions seen in the machines' last checks; on every machine that has
 * them, some, or a group's; all at once or in batches; restarting where
 * needed or not; now or later.
 */
const props = defineProps<{
    packages: UpdatesPackage[];
    machines: UpdatesMachine[];
    groups: MachineGroupChoice[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ created: [rollout: Rollout] }>();

type Segment<V> = { value: V; label: string };

const mode = ref<'latest' | 'exact'>('latest');
const versions = ref<Record<string, string>>({});
const allowDowngrade = ref(false);
const target = ref<'all' | 'pick' | 'group'>('all');
const picked = ref<number[]>([]);
const groupId = ref<number | null>(null);
const pace = ref<'once' | 'batches'>('once');
const batchSize = ref(2);
const stopOnFailure = ref(true);
const reboot = ref<'never' | 'if_required'>('never');
const when = ref<'now' | 'later'>('now');
const at = ref('');
const error = ref<string | null>(null);
const sending = ref(false);

const byId = computed(
    () => new Map(props.machines.map((machine) => [machine.id, machine])),
);

/* The versions each package is offered at, by OS release, for exact mode. */
function versionOptions(pkg: UpdatesPackage): PickerOption<string>[] {
    return [...pkg.versions]
        .sort(
            (a, b) =>
                a.release.localeCompare(b.release) ||
                b.machineIds.length - a.machineIds.length,
        )
        .map((v) => ({
            value: v.version,
            label: v.version,
            hint: trans(
                v.machineIds.length === 1
                    ? ':os · :count machine'
                    : ':os · :count machines',
                {
                    os: v.os ?? v.release,
                    count: String(v.machineIds.length),
                },
            ),
        }));
}

const canExact = computed(() =>
    props.packages.every((pkg) => pkg.versions.length > 0),
);

/* The machines it can run on: any with one of the packages waiting, or for
   exact versions, those offering every chosen version. */
const eligible = computed<UpdatesMachine[]>(() => {
    let ids: Set<number>;
    if (mode.value === 'exact') {
        ids = new Set(props.machines.map((m) => m.id));
        for (const pkg of props.packages) {
            const version = pkg.versions.find(
                (v) => v.version === versions.value[pkg.name],
            );
            const offering = new Set(version?.machineIds ?? []);
            ids = new Set([...ids].filter((id) => offering.has(id)));
        }
    } else {
        ids = new Set(props.packages.flatMap((pkg) => pkg.machineIds));
    }

    return [...ids]
        .map((id) => byId.value.get(id))
        .filter((m): m is UpdatesMachine => m !== undefined)
        .sort((a, b) => a.name.localeCompare(b.name));
});

const chosen = computed<UpdatesMachine[]>(() => {
    if (target.value === 'pick') {
        return eligible.value.filter((m) => picked.value.includes(m.id));
    }
    if (target.value === 'group') {
        const group = props.groups.find((g) => g.id === groupId.value);
        return eligible.value.filter((m) => group?.machineIds.includes(m.id));
    }
    return eligible.value;
});

const offline = computed(() => chosen.value.filter((m) => !m.online).length);
const tooOld = computed(() =>
    mode.value === 'exact'
        ? chosen.value.filter((m) => !m.canVersions).length
        : chosen.value.filter((m) => !m.canPick).length,
);

const modes = computed<Segment<'latest' | 'exact'>[]>(() => [
    { value: 'latest', label: trans('Latest available') },
    { value: 'exact', label: trans('Exact version') },
]);
const targets = computed<Segment<'all' | 'pick' | 'group'>[]>(() => [
    { value: 'all', label: trans('All with the update') },
    { value: 'pick', label: trans('Pick') },
    ...(props.groups.length
        ? [{ value: 'group' as const, label: trans('A group') }]
        : []),
]);
const paces = computed<Segment<'once' | 'batches'>[]>(() => [
    { value: 'once', label: trans('All at once') },
    { value: 'batches', label: trans('In batches') },
]);
const reboots = computed<Segment<'never' | 'if_required'>[]>(() => [
    { value: 'never', label: trans('Don’t restart') },
    { value: 'if_required', label: trans('Restart if needed') },
]);
const whens = computed<Segment<'now' | 'later'>[]>(() => [
    { value: 'now', label: trans('Now') },
    { value: 'later', label: trans('Later') },
]);
const groupOptions = computed<PickerOption<number | null>[]>(() => [
    { value: null, label: trans('Pick a group') },
    ...props.groups.map((g) => ({
        value: g.id,
        label: g.name,
        hint: trans(
            g.machineIds.length === 1 ? ':count machine' : ':count machines',
            { count: String(g.machineIds.length) },
        ),
    })),
]);

const packageLine = computed(() => {
    const names = props.packages.map((p) => p.name);
    return names.length > 4
        ? trans(':names and :count more', {
              names: names.slice(0, 3).join(', '),
              count: String(names.length - 3),
          })
        : names.join(', ');
});

const ready = computed(
    () =>
        chosen.value.length > 0 &&
        (mode.value === 'latest' ||
            props.packages.every((p) => versions.value[p.name])) &&
        (when.value === 'now' || at.value !== '') &&
        (pace.value === 'once' || batchSize.value >= 1),
);

const submitLabel = computed(() => {
    const count = chosen.value.length;
    if (when.value === 'later') {
        return count === 1
            ? trans('Plan for 1 machine')
            : trans('Plan for :count machines', { count: String(count) });
    }
    return count === 1
        ? trans('Roll out to 1 machine')
        : trans('Roll out to :count machines', { count: String(count) });
});

/* Fresh choices each time it opens: the most common version of each. */
watch(
    open,
    (value) => {
        if (!value) return;
        mode.value = 'latest';
        versions.value = Object.fromEntries(
            props.packages.map((pkg) => [
                pkg.name,
                [...pkg.versions].sort(
                    (a, b) => b.machineIds.length - a.machineIds.length,
                )[0]?.version ?? '',
            ]),
        );
        allowDowngrade.value = false;
        target.value = 'all';
        picked.value = [];
        groupId.value = null;
        pace.value = 'once';
        batchSize.value = 2;
        stopOnFailure.value = true;
        reboot.value = 'never';
        when.value = 'now';
        at.value = '';
        error.value = null;
    },
    { immediate: true },
);

watch(eligible, (list) => {
    picked.value = picked.value.filter((id) => list.some((m) => m.id === id));
});

function togglePick(id: number, on: boolean): void {
    picked.value = on
        ? [...new Set([...picked.value, id])]
        : picked.value.filter((n) => n !== id);
}

async function submit(): Promise<void> {
    if (!ready.value || sending.value) return;
    sending.value = true;
    error.value = null;
    try {
        const { rollout } = await sendJson<{ rollout: Rollout }>(
            'POST',
            '/updates/rollouts',
            {
                packages: props.packages.map((p) => ({
                    name: p.name,
                    version:
                        mode.value === 'exact' ? versions.value[p.name] : null,
                })),
                mode: mode.value,
                allow_downgrade:
                    mode.value === 'exact' ? allowDowngrade.value : false,
                machines: chosen.value.map((m) => m.id),
                batch_size: pace.value === 'batches' ? batchSize.value : null,
                stop_on_failure:
                    pace.value === 'batches' ? stopOnFailure.value : true,
                reboot: reboot.value,
                scheduled_for:
                    when.value === 'later'
                        ? new Date(at.value).toISOString()
                        : null,
            },
        );
        toast.success(
            rollout.scheduledFor
                ? trans('Rollout planned.')
                : trans('Rollout started.'),
        );
        open.value = false;
        emit('created', rollout);
    } catch (cause) {
        error.value = (cause as Error).message;
    } finally {
        sending.value = false;
    }
}

const label = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Roll out updates') }}</DialogTitle>
                <DialogDescription class="truncate font-ui-mono text-xs/5">
                    {{ packageLine }}
                </DialogDescription>
            </DialogHeader>

            <MorphHeight>
                <form
                    class="flex max-h-[65svh] flex-col gap-5 overflow-y-auto"
                    @submit.prevent="submit"
                >
                    <div class="flex flex-col gap-2">
                        <span :class="label">{{ $t('Version') }}</span>
                        <div class="ds-segments self-start" role="group">
                            <button
                                v-for="option in modes"
                                :key="option.value"
                                type="button"
                                class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                                :aria-pressed="mode === option.value"
                                :disabled="
                                    option.value === 'exact' && !canExact
                                "
                                @click="mode = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <template v-if="mode === 'exact'">
                            <div
                                v-for="pkg in packages"
                                :key="pkg.name"
                                class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] items-center gap-3"
                            >
                                <span class="truncate font-ui-mono text-xs/5">{{
                                    pkg.name
                                }}</span>
                                <PickerMenu
                                    v-model="versions[pkg.name]"
                                    :options="versionOptions(pkg)"
                                />
                            </div>
                            <label
                                class="flex items-center justify-between gap-3 text-[13px]/5 text-(--ds-text-secondary)"
                            >
                                {{ $t('Allow going back to an older version') }}
                                <ToggleSwitch
                                    v-model="allowDowngrade"
                                    :label="
                                        $t(
                                            'Allow going back to an older version',
                                        )
                                    "
                                />
                            </label>
                            <p class="text-[12.5px]/4.5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Versions differ between OS releases, so only machines offering the version you pick are in.',
                                    )
                                }}
                            </p>
                        </template>
                    </div>

                    <div class="flex flex-col gap-2">
                        <span :class="label">{{ $t('Machines') }}</span>
                        <div class="ds-segments self-start" role="group">
                            <button
                                v-for="option in targets"
                                :key="option.value"
                                type="button"
                                class="cursor-pointer"
                                :aria-pressed="target === option.value"
                                @click="target = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <PickerMenu
                            v-if="target === 'group'"
                            v-model="groupId"
                            :options="groupOptions"
                        />
                        <ul
                            v-if="target === 'pick'"
                            class="max-h-48 divide-y divide-(--ds-divider) overflow-y-auto rounded-[10px] border border-(--ds-border)"
                        >
                            <li v-for="machine in eligible" :key="machine.id">
                                <label
                                    class="flex cursor-pointer items-center gap-3 px-3.5 py-2 text-[13px]/5 hover:bg-(--ds-row-hover)"
                                >
                                    <TickBox
                                        :model-value="
                                            picked.includes(machine.id)
                                        "
                                        @update:model-value="
                                            (on: boolean) =>
                                                togglePick(machine.id, on)
                                        "
                                    />
                                    <span class="truncate font-medium">{{
                                        machine.name
                                    }}</span>
                                    <span
                                        class="ml-auto truncate text-(--ds-text-faint)"
                                        >{{ machine.os }}</span
                                    >
                                </label>
                            </li>
                        </ul>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            <template v-if="chosen.length">
                                {{
                                    chosen.length === 1
                                        ? $t('Runs on :names.', {
                                              names: chosen[0].name,
                                          })
                                        : $t(
                                              'Runs on :count machines: :names.',
                                              {
                                                  count: String(chosen.length),
                                                  names:
                                                      chosen
                                                          .slice(0, 4)
                                                          .map((m) => m.name)
                                                          .join(', ') +
                                                      (chosen.length > 4
                                                          ? ` +${chosen.length - 4}`
                                                          : ''),
                                              },
                                          )
                                }}
                            </template>
                            <template v-else>
                                {{ $t('No machines picked yet.') }}
                            </template>
                            <template v-if="offline">
                                {{
                                    ' ' +
                                    $t(
                                        offline === 1
                                            ? '1 is offline now and is skipped if it’s still offline at its turn.'
                                            : ':count are offline now and are skipped if they’re still offline at their turn.',
                                        { count: String(offline) },
                                    )
                                }}
                            </template>
                            <template v-if="tooOld">
                                {{
                                    ' ' +
                                    $t(
                                        tooOld === 1
                                            ? '1 has a daemon too old for this and is skipped. Update it with sudo serverosd update.'
                                            : ':count have a daemon too old for this and are skipped. Update them with sudo serverosd update.',
                                        { count: String(tooOld) },
                                    )
                                }}
                            </template>
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <span :class="label">{{ $t('How') }}</span>
                        <div class="ds-segments self-start" role="group">
                            <button
                                v-for="option in paces"
                                :key="option.value"
                                type="button"
                                class="cursor-pointer"
                                :aria-pressed="pace === option.value"
                                @click="pace = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <div
                            v-if="pace === 'batches'"
                            class="flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px]/5 text-(--ds-text-secondary)"
                        >
                            <label class="flex items-center gap-2">
                                {{ $t('Machines at a time') }}
                                <input
                                    v-model.number="batchSize"
                                    type="number"
                                    min="1"
                                    max="1000"
                                    class="h-8 w-16 rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-2 text-[13px] tabular-nums outline-none focus-visible:border-(--ds-border-hover)"
                                />
                            </label>
                            <label class="flex items-center gap-2">
                                <ToggleSwitch
                                    v-model="stopOnFailure"
                                    :label="$t('Stop if one fails')"
                                />
                                {{ $t('Stop if one fails') }}
                            </label>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <span :class="label">{{ $t('Restarts') }}</span>
                        <div class="ds-segments self-start" role="group">
                            <button
                                v-for="option in reboots"
                                :key="option.value"
                                type="button"
                                class="cursor-pointer"
                                :aria-pressed="reboot === option.value"
                                @click="reboot = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <p
                            v-if="reboot === 'if_required'"
                            class="text-[12.5px]/4.5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'A machine restarts after its install only if an update needs it. Alerts about it are held for 10 minutes.',
                                )
                            }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <span :class="label">{{ $t('When') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="ds-segments" role="group">
                                <button
                                    v-for="option in whens"
                                    :key="option.value"
                                    type="button"
                                    class="cursor-pointer"
                                    :aria-pressed="when === option.value"
                                    @click="when = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                            <input
                                v-if="when === 'later'"
                                v-model="at"
                                type="datetime-local"
                                :aria-label="$t('Start at')"
                                class="h-8 rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-2 text-[13px] tabular-nums outline-none focus-visible:border-(--ds-border-hover)"
                            />
                        </div>
                    </div>

                    <p v-if="error" class="text-[13px]/5 text-(--ds-red)">
                        {{ error }}
                    </p>

                    <div class="flex items-center justify-end gap-2">
                        <AppButton size="sm" @click="open = false">
                            {{ $t('Cancel') }}
                        </AppButton>
                        <AppButton
                            size="sm"
                            variant="primary"
                            type="submit"
                            :disabled="!ready || sending"
                        >
                            {{ submitLabel }}
                        </AppButton>
                    </div>
                </form>
            </MorphHeight>
        </DialogContent>
    </Dialog>
</template>
