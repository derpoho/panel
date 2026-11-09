<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { every, intervals } from '@/components/monitors/monitors';
import type { MachineOption, Monitor } from '@/components/monitors/monitors';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';

/**
 * Add or edit a monitor: what to check, how often, and what counts as up.
 * The less common settings sit under "More options".
 */
const props = defineProps<{
    monitor?: Monitor | null;
    machines: MachineOption[];
    minInterval: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    type: 'http' as 'http' | 'tcp',
    url: '',
    host: '',
    port: undefined as number | undefined,
    interval: 60,
    timeout: 10,
    expected_status: undefined as number | undefined,
    keyword: '',
    machine_id: null as number | null,
    alerts: true,
});

const more = ref(false);
/** The name follows the address until someone types one. */
const nameTouched = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) return;

    const m = props.monitor;
    form.clearErrors();
    form.defaults({
        name: m?.name ?? '',
        type: m?.type ?? 'http',
        url: m?.url ?? '',
        host: m?.host ?? '',
        port: m?.port ?? undefined,
        interval: m?.interval ?? Math.max(60, props.minInterval),
        timeout: m?.timeout ?? 10,
        expected_status: m?.expectedStatus ?? undefined,
        keyword: m?.keyword ?? '',
        machine_id: m?.machineId ?? null,
        alerts: m?.alerts ?? true,
    });
    form.reset();
    nameTouched.value = !!m;
    more.value = !!(m && (m.expectedStatus || m.keyword || m.machineId));
});

function suggestName(): void {
    if (nameTouched.value) return;

    if (form.type === 'tcp') {
        form.name = form.host && form.port ? `${form.host}:${form.port}` : '';

        return;
    }
    try {
        form.name = new URL(form.url).hostname.replace(/^www\./, '');
    } catch {
        form.name = '';
    }
}

watch(() => [form.url, form.host, form.port, form.type], suggestName);

const expectExact = computed({
    get: () => form.expected_status !== undefined,
    set: (on: boolean) => (form.expected_status = on ? 200 : undefined),
});

/** A machine's address as a starting point for a port check. */
function pickMachine(machine: MachineOption): void {
    form.machine_id = machine.id;
    if (form.type === 'tcp' && machine.ip && !form.host) form.host = machine.ip;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.monitor) {
        form.put(`/monitors/${props.monitor.id}`, options);
    } else {
        form.post('/monitors', options);
    }
}

const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[90dvh] gap-0 overflow-y-auto p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ monitor ? $t('Edit monitor') : $t('New monitor') }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Checked from ServerOS, outside your machines, so it sees what your visitors see.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div
                        class="ds-segments self-start"
                        role="group"
                        :aria-label="$t('What to check')"
                    >
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="form.type === 'http'"
                            @click="form.type = 'http'"
                        >
                            {{ ' ' + $t('Website or API') + ' ' }}
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="form.type === 'tcp'"
                            @click="form.type = 'tcp'"
                        >
                            {{ ' ' + $t('Port') + ' ' }}
                        </button>
                    </div>

                    <div
                        v-if="form.type === 'http'"
                        class="flex flex-col gap-1.5"
                    >
                        <label for="monitor-url" :class="labelClass">{{
                            $t('URL')
                        }}</label>
                        <Input
                            id="monitor-url"
                            v-model="form.url"
                            type="url"
                            inputmode="url"
                            :placeholder="$t('https://shop.example.com/health')"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono text-[13px]"
                            required
                        />
                        <InputError :message="form.errors.url" />
                    </div>

                    <div
                        v-else
                        class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3"
                    >
                        <div class="flex flex-col gap-1.5">
                            <label for="monitor-host" :class="labelClass">
                                {{ ' ' + $t('Host') + ' ' }}
                            </label>
                            <Input
                                id="monitor-host"
                                v-model="form.host"
                                :placeholder="$t('db.example.com')"
                                autocomplete="off"
                                spellcheck="false"
                                class="font-ui-mono text-[13px]"
                                required
                            />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="monitor-port" :class="labelClass">
                                {{ ' ' + $t('Port') + ' ' }}
                            </label>
                            <Input
                                id="monitor-port"
                                v-model.number="form.port"
                                type="number"
                                min="1"
                                max="65535"
                                placeholder="5432"
                                class="font-ui-mono text-[13px]"
                                required
                            />
                        </div>
                        <InputError
                            class="col-span-2"
                            :message="form.errors.host ?? form.errors.port"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="monitor-name" :class="labelClass">
                            {{ ' ' + $t('Name') + ' ' }}
                        </label>
                        <Input
                            id="monitor-name"
                            v-model="form.name"
                            maxlength="80"
                            :placeholder="$t('Shop')"
                            autocomplete="off"
                            required
                            @input="nameTouched = true"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Check every') }}</span>
                        <div class="max-w-full overflow-x-auto">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="$t('Check every')"
                            >
                                <button
                                    v-for="seconds in intervals"
                                    :key="seconds"
                                    type="button"
                                    class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-40"
                                    :class="focusRing"
                                    :aria-pressed="form.interval === seconds"
                                    :disabled="seconds < minInterval"
                                    :title="
                                        seconds < minInterval
                                            ? $t('Not on your plan')
                                            : undefined
                                    "
                                    @click="form.interval = seconds"
                                >
                                    {{ every(seconds) }}
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.interval" />
                    </div>

                    <button
                        type="button"
                        class="flex cursor-pointer items-center gap-1 self-start text-[13px]/5 font-medium text-(--ds-text-secondary) hover:text-(--ds-text)"
                        :class="focusRing"
                        :aria-expanded="more"
                        @click="more = !more"
                    >
                        <ChevronRight
                            class="size-3.5 transition-transform"
                            :class="{ 'rotate-90': more }"
                        />
                        {{ $t('More options') + ' ' }}
                    </button>

                    <div
                        v-if="more"
                        class="flex flex-col divide-y divide-(--ds-divider) rounded-[10px] border border-(--ds-border)"
                    >
                        <template v-if="form.type === 'http'">
                            <div class="flex flex-col gap-2 px-4 py-3">
                                <div
                                    class="flex items-center justify-between gap-4"
                                >
                                    <span class="flex flex-col">
                                        <span class="text-[13px]/5 font-medium">
                                            {{
                                                ' ' +
                                                $t('Exact status code') +
                                                ' '
                                            }}
                                        </span>
                                        <span
                                            class="text-xs/4 text-(--ds-text-muted)"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'Otherwise any 2xx or 3xx is up.',
                                                ) +
                                                ' '
                                            }}
                                        </span>
                                    </span>
                                    <ToggleSwitch
                                        v-model="expectExact"
                                        :label="
                                            $t('Expect an exact status code')
                                        "
                                    />
                                </div>
                                <Input
                                    v-if="expectExact"
                                    v-model.number="form.expected_status"
                                    type="number"
                                    min="100"
                                    max="599"
                                    :aria-label="$t('Status code')"
                                    class="w-28 font-ui-mono text-[13px]"
                                />
                                <InputError
                                    :message="form.errors.expected_status"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5 px-4 py-3">
                                <label
                                    for="monitor-keyword"
                                    class="text-[13px]/5 font-medium"
                                >
                                    {{ ' ' + $t('Page must contain') + ' ' }}
                                </label>
                                <Input
                                    id="monitor-keyword"
                                    v-model="form.keyword"
                                    maxlength="200"
                                    :placeholder="$t('Add to cart')"
                                    autocomplete="off"
                                />
                                <span class="text-xs/4 text-(--ds-text-muted)">
                                    {{
                                        ' ' +
                                        $t(
                                            'Catches a page that loads but shows an error. Not case-sensitive.',
                                        ) +
                                        ' '
                                    }}
                                </span>
                                <InputError :message="form.errors.keyword" />
                            </div>
                        </template>

                        <div
                            class="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <label
                                for="monitor-timeout"
                                class="text-[13px]/5 font-medium"
                            >
                                {{ ' ' + $t('Time out after') + ' ' }}
                            </label>
                            <select
                                id="monitor-timeout"
                                v-model.number="form.timeout"
                                class="ds-select ds-raised-button h-8 cursor-pointer appearance-none pr-8! text-[13px]"
                            >
                                <option :value="5">
                                    {{ $t('5 seconds') }}
                                </option>
                                <option :value="10">
                                    {{ $t('10 seconds') }}
                                </option>
                                <option :value="15">
                                    {{ $t('15 seconds') }}
                                </option>
                                <option :value="30">
                                    {{ $t('30 seconds') }}
                                </option>
                            </select>
                        </div>

                        <div
                            v-if="machines.length"
                            class="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <span class="flex flex-col">
                                <label
                                    for="monitor-machine"
                                    class="text-[13px]/5 font-medium"
                                >
                                    {{ ' ' + $t('Runs on') + ' ' }}
                                </label>
                                <span class="text-xs/4 text-(--ds-text-muted)">
                                    {{
                                        ' ' +
                                        $t(
                                            'Lists it on that machine’s status page.',
                                        ) +
                                        ' '
                                    }}
                                </span>
                            </span>
                            <select
                                id="monitor-machine"
                                :value="form.machine_id ?? ''"
                                class="ds-select ds-raised-button h-8 max-w-44 cursor-pointer appearance-none pr-8! text-[13px]"
                                @change="
                                    (e) => {
                                        const id = Number(
                                            (e.target as HTMLSelectElement)
                                                .value,
                                        );
                                        const machine = machines.find(
                                            (m) => m.id === id,
                                        );
                                        if (machine) pickMachine(machine);
                                        else form.machine_id = null;
                                    }
                                "
                            >
                                <option value="">{{ $t('No machine') }}</option>
                                <option
                                    v-for="machine in machines"
                                    :key="machine.id"
                                    :value="machine.id"
                                >
                                    {{ machine.name }}
                                </option>
                            </select>
                        </div>

                        <div
                            class="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <span class="flex flex-col">
                                <span class="text-[13px]/5 font-medium">
                                    {{ ' ' + $t('Alerts') + ' ' }}
                                </span>
                                <span class="text-xs/4 text-(--ds-text-muted)">
                                    {{
                                        $t(
                                            "When it goes down and when it's back.",
                                        )
                                    }}
                                </span>
                            </span>
                            <ToggleSwitch
                                v-model="form.alerts"
                                :label="$t('Send alerts for this monitor')"
                            />
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="monitor ? $t('Save') : $t('Add monitor')"
                        busy-label="Saving…"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
