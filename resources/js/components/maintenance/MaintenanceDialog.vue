<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
 * Put a machine in maintenance: now, or planned for later (announced on its
 * status page straight away, started on time, with a restart or updates
 * for ServerOS to run). For how long, why, and whether the status page
 * says so. Later suggests the machine's quietest hour of the week.
 */
const props = defineProps<{ machineId: number; machineName: string }>();
const open = defineModel<boolean>('open', { default: false });

const durations = [
    [30, '30 min'],
    [60, '1 h'],
    [120, '2 h'],
    [240, '4 h'],
    [480, '8 h'],
    [1440, '1 day'],
] as const;

const actions = computed(
    () =>
        [
            ['none', trans('Nothing')],
            ['reboot', trans('Restart it')],
            ['updates', trans('Install updates')],
        ] as const,
);

const when = ref<'now' | 'later'>('now');

const form = useForm({
    minutes: 60,
    reason: '',
    public: true,
    starts_at: '',
    action: 'none' as 'none' | 'reboot' | 'updates',
});

/** "YYYY-MM-DDTHH:mm" in the browser's time, as datetime-local wants. */
function localInput(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Tomorrow at 04:00 in the browser's time: a sensible start before any suggestion. */
function tomorrowEarly(): string {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    date.setHours(4, 0, 0, 0);

    return localInput(date);
}

const minStart = computed(() => localInput(new Date(Date.now() + 5 * 60000)));

/* The machine's quietest hour, from its last two weeks of CPU. */
type Quiet = { startsAt: string; cpu: number; days: number } | null;
const quiet = ref<Quiet>(null);

async function loadQuiet(): Promise<void> {
    quiet.value = null;
    try {
        const response = await fetch(
            `/machines/${props.machineId}/maintenance/quiet-time`,
            { headers: { Accept: 'application/json' } },
        );
        if (response.ok)
            quiet.value = ((await response.json()) as { quiet: Quiet }).quiet;
    } catch {
        quiet.value = null;
    }
}

const quietLabel = computed(() =>
    quiet.value
        ? new Date(quiet.value.startsAt).toLocaleString(undefined, {
              weekday: 'long',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);

function useQuiet(): void {
    if (quiet.value)
        form.starts_at = localInput(new Date(quiet.value.startsAt));
}

watch(open, (isOpen) => {
    if (isOpen) {
        when.value = 'now';
        form.reset();
        form.clearErrors();
        form.starts_at = tomorrowEarly();
        void loadQuiet();
    }
});

watch(when, (value) => {
    if (value === 'later' && quiet.value && form.starts_at === tomorrowEarly())
        useQuiet();
});

const actionHint = computed(() => {
    switch (form.action) {
        case 'reboot':
            return trans(
                'ServerOS restarts it when the window starts, and ends maintenance early once it’s back.',
            );
        case 'updates':
            return trans(
                'ServerOS installs every waiting update, restarts it if they need that, and ends maintenance early once it’s back.',
            );
        default:
            return trans(
                'You do the work. Maintenance ends when the time is up.',
            );
    }
});

function submit(): void {
    if (when.value === 'now') {
        form.transform((data) => ({
            minutes: data.minutes,
            reason: data.reason,
            public: data.public,
        })).post(`/machines/${props.machineId}/maintenance`, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });

        return;
    }

    form.transform((data) => ({
        ...data,
        // The browser's time, sent as an instant.
        starts_at: data.starts_at ? new Date(data.starts_at).toISOString() : '',
    })).post(`/machines/${props.machineId}/maintenance/scheduled`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{
                            ' ' +
                            $t('Maintenance on :machineName', { machineName })
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            (when === 'now'
                                ? $t(
                                      'Reboots and restarts while you work won’t page anyone or set off workflows. Anything still wrong when the time is up is alerted then.',
                                  )
                                : $t(
                                      'Plan it ahead: it shows on the status page from now, and starts on time with alerts held.',
                                  )) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-5 p-5">
                    <div class="flex flex-col gap-1.5">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('When') }}</span
                        >
                        <div
                            class="ds-segments self-start"
                            role="group"
                            :aria-label="$t('When')"
                        >
                            <button
                                type="button"
                                class="cursor-pointer whitespace-nowrap"
                                :aria-pressed="when === 'now'"
                                @click="when = 'now'"
                            >
                                {{ $t('Now') }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer whitespace-nowrap"
                                :aria-pressed="when === 'later'"
                                @click="when = 'later'"
                            >
                                {{ $t('Later') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="when === 'later'" class="flex flex-col gap-1.5">
                        <label
                            for="maintenance-starts"
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Starts') }}</label
                        >
                        <Input
                            id="maintenance-starts"
                            v-model="form.starts_at"
                            type="datetime-local"
                            :min="minStart"
                            required
                        />
                        <p
                            v-if="quiet && quietLabel"
                            class="text-xs/4 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Quietest time on :machine: :when, at :cpu% CPU on average over :days days.',
                                    {
                                        machine: machineName,
                                        when: quietLabel,
                                        cpu: String(quiet.cpu),
                                        days: String(quiet.days),
                                    },
                                ) + ' '
                            }}
                            <button
                                type="button"
                                class="cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                                @click="useQuiet"
                            >
                                {{ $t('Use it') }}
                            </button>
                        </p>
                        <InputError :message="form.errors.starts_at" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('For') }}</span
                        >
                        <div class="max-w-full overflow-x-auto">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="$t('For how long')"
                            >
                                <button
                                    v-for="[minutes, label] in durations"
                                    :key="minutes"
                                    type="button"
                                    class="cursor-pointer whitespace-nowrap"
                                    :aria-pressed="form.minutes === minutes"
                                    @click="form.minutes = minutes"
                                >
                                    {{ label }}
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors.minutes" />
                    </div>

                    <div v-if="when === 'later'" class="flex flex-col gap-1.5">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('When it starts, ServerOS will') }}</span
                        >
                        <div class="max-w-full overflow-x-auto">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="
                                    $t('When it starts, ServerOS will')
                                "
                            >
                                <button
                                    v-for="[value, label] in actions"
                                    :key="value"
                                    type="button"
                                    class="cursor-pointer whitespace-nowrap"
                                    :aria-pressed="form.action === value"
                                    @click="form.action = value"
                                >
                                    {{ label }}
                                </button>
                            </div>
                        </div>
                        <p class="text-xs/4 text-(--ds-text-muted)">
                            {{ actionHint }}
                        </p>
                        <InputError :message="form.errors.action" />
                    </div>

                    <label class="flex flex-col gap-1.5">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('What’s happening') }}</span
                        >
                        <Input
                            v-model="form.reason"
                            maxlength="200"
                            :placeholder="$t('Upgrading Postgres to 17')"
                        />
                        <InputError :message="form.errors.reason" />
                    </label>

                    <div class="flex items-center justify-between gap-4">
                        <span class="flex flex-col">
                            <span class="text-[13px]/5 font-medium">{{
                                $t('Show on its status page')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-muted)">
                                {{
                                    ' ' +
                                    (when === 'now'
                                        ? $t(
                                              'Visitors see a maintenance notice instead of an outage.',
                                          )
                                        : $t(
                                              'Visitors see it coming, then a maintenance notice instead of an outage.',
                                          )) +
                                    ' '
                                }}
                            </span>
                        </span>
                        <ToggleSwitch
                            v-model="form.public"
                            :label="$t('Show on its status page')"
                        />
                    </div>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <span class="grow" />
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="open = false"
                        >{{ $t('Cancel') }}</AppButton
                    >
                    <SubmitButton
                        :label="
                            when === 'now'
                                ? $t('Start maintenance')
                                : $t('Plan maintenance')
                        "
                        :busy-label="when === 'now' ? 'Starting…' : 'Planning…'"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
