<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, X } from '@lucide/vue';
import {
    SolarLock as Lock,
    SolarShieldCheck as ShieldCheck,
    SolarSquareTerminal as SquareTerminal,
} from '@/lib/solarIcons';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import type { DaemonJob } from '@/components/machines/JobLogDialog.vue';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import AppButton from '@/components/workspace/AppButton.vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import { edit as securityEdit } from '@/routes/security';

/**
 * A machine's console: a terminal that slides up over the page, for one-off
 * commands in any of its containers (the daemon runs them with sh -c, up to
 * a minute each). Pick the container in the header. It needs two-factor on
 * the account and a fresh code per session, like the service page's.
 */
const props = defineProps<{
    machineId: number;
    machineName: string;
    /** The machine's containers: key and the name people know it by. */
    containers: { key: string; name: string }[];
    jobs: DaemonJob[];
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
}>();

const open = defineModel<boolean>('open', { default: false });

/* Which container commands go to */
const container = ref<string>(props.containers[0]?.key ?? '');
watch(
    () => props.containers.map((c) => c.key).join(),
    () => {
        if (!props.containers.some((c) => c.key === container.value)) {
            container.value = props.containers[0]?.key ?? '';
        }
    },
);
const containerOptions = computed(() =>
    props.containers.map((c) => ({ value: c.key, label: c.name })),
);

/* The lock: two-factor on the account, then a code per session */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
const unlockedMs = computed(() =>
    props.consoleLock.unlockedUntil
        ? Date.parse(props.consoleLock.unlockedUntil) - now.value
        : 0,
);
const unlocked = computed(() => unlockedMs.value > 0);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(unlockedMs.value / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(url: string, body: Record<string, unknown> = {}) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });

    return {
        ok: response.ok,
        body: (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >,
    };
}

const unlockCode = ref('');
const unlocking = ref(false);
const unlockError = ref<string | null>(null);

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) return;
    unlocking.value = true;
    unlockError.value = null;
    const { ok, body } = await post('/console/unlock', {
        code: unlockCode.value,
    });
    unlocking.value = false;
    if (!ok) {
        const errors = body.errors as Record<string, string[]> | undefined;
        unlockError.value =
            errors?.code?.[0] ??
            (body.error as string | undefined) ??
            trans('Couldn’t unlock.');
        unlockCode.value = '';
        return;
    }
    unlockCode.value = '';
    router.reload({
        only: ['consoleLock'],
        onFinish: () => nextTick(() => input.value?.focus()),
    });
}

async function lock(): Promise<void> {
    await post('/console/lock');
    router.reload({ only: ['consoleLock'] });
}

/* Commands and their output, oldest first, for the chosen container */
const command = ref('');
const commandError = ref<string | null>(null);
const sending = ref(false);
const input = useTemplateRef<HTMLInputElement>('input');
const box = useTemplateRef<HTMLElement>('box');
let historyIndex = -1;

const transcript = computed(() =>
    props.jobs
        .filter(
            (job) =>
                job.kind === 'service_exec' && job.service === container.value,
        )
        .slice()
        .reverse(),
);
const running = computed(() =>
    props.jobs.some(
        (job) =>
            job.kind === 'service_exec' &&
            ['queued', 'running'].includes(job.state),
    ),
);

async function run(): Promise<void> {
    const text = command.value.trim();
    if (!text || sending.value || !container.value) return;
    sending.value = true;
    commandError.value = null;
    const { ok, body } = await post(
        `/machines/${props.machineId}/services/${encodeURIComponent(container.value)}/actions`,
        { action: 'exec', command: text },
    );
    sending.value = false;
    if (ok) {
        command.value = '';
        historyIndex = -1;
        follow();
    } else if (body.code === 'console_locked') {
        router.reload({ only: ['consoleLock'] });
    } else {
        const errors = body.errors as Record<string, string[]> | undefined;
        commandError.value =
            Object.values(errors ?? {})[0]?.[0] ??
            (body.error as string | undefined) ??
            trans('That didn’t work.');
    }
}

function recall(direction: 1 | -1): void {
    const past = transcript.value.map((job) => job.command ?? '').reverse();
    historyIndex = Math.max(
        -1,
        Math.min(past.length - 1, historyIndex + direction),
    );
    command.value = historyIndex === -1 ? '' : past[historyIndex];
}

/* Follow a command until it's done: reload the jobs every second or so. */
let poller: ReturnType<typeof setTimeout> | undefined;

function follow(): void {
    clearTimeout(poller);
    poller = setTimeout(() => {
        router.reload({
            only: ['jobs'],
            onFinish: () => {
                if (open.value && running.value) follow();
            },
        });
    }, 1200);
}

/** The daemon echoes the command as the first line; skip it. */
function output(job: DaemonJob): string[] {
    return job.log[0]?.startsWith('$ ') ? job.log.slice(1) : job.log;
}

watch(
    () => transcript.value.map((job) => job.log.length).join(),
    () =>
        nextTick(() => {
            if (box.value) box.value.scrollTop = box.value.scrollHeight;
        }),
);

watch(open, (value) => {
    if (value) {
        void nextTick(() => input.value?.focus());
        if (running.value) follow();
    } else {
        clearTimeout(poller);
    }
});

onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => {
    clearInterval(clock);
    clearTimeout(poller);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="console-sheet">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex flex-col justify-end"
                @keydown.esc="open = false"
            >
                <div
                    class="console-scrim absolute inset-0 bg-black/40"
                    aria-hidden="true"
                    @click="open = false"
                />
                <section
                    role="dialog"
                    aria-modal="true"
                    :aria-label="$t('Console')"
                    class="console-panel dark workspace-panel relative mx-2 mb-2 flex h-[min(80vh,780px)] flex-col overflow-hidden rounded-2xl border border-white/[0.08] bg-[#151514] font-ui text-(--ds-text) shadow-[0_-24px_80px_-20px_rgb(0_0_0/0.6)]"
                >
                    <header
                        class="flex h-12 shrink-0 items-center gap-3 border-b border-white/[0.06] bg-[#1b1b1a] px-4"
                    >
                        <SquareTerminal
                            class="size-4 shrink-0 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <span class="shrink-0 text-[13px]/5 font-medium">{{
                            machineName
                        }}</span>
                        <span class="text-(--ds-text-faint)" aria-hidden="true"
                            >/</span
                        >
                        <div v-if="containers.length" class="w-64 max-w-[40vw]">
                            <PickerMenu
                                v-model="container"
                                :options="containerOptions"
                            />
                        </div>
                        <span
                            v-if="consoleLock.twoFactorEnabled && unlocked"
                            class="ml-auto flex shrink-0 items-center gap-2 text-[12.5px] text-(--ds-text-faint)"
                        >
                            <ShieldCheck
                                class="size-3.5 text-(--ds-green)"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            {{ $t('Locks in') }}
                            <span class="font-ui-mono tabular-nums">{{
                                countdown
                            }}</span>
                            <button
                                type="button"
                                class="ml-1 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-white/[0.06] hover:text-(--ds-text)"
                                :title="$t('Lock now')"
                                :aria-label="$t('Lock now')"
                                @click="lock"
                            >
                                <Lock class="size-3.5" :stroke-width="1.75" />
                            </button>
                        </span>
                        <button
                            type="button"
                            class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-white/[0.06] hover:text-(--ds-text)"
                            :class="{
                                'ml-auto': !(
                                    consoleLock.twoFactorEnabled && unlocked
                                ),
                            }"
                            :aria-label="$t('Close console')"
                            @click="open = false"
                        >
                            <X class="mi-close size-4" :stroke-width="1.75" />
                        </button>
                    </header>

                    <div
                        v-if="!containers.length"
                        class="flex grow flex-col items-center justify-center gap-1.5 px-6 text-center"
                    >
                        <h2 class="text-[15px]/6 font-semibold">
                            {{ $t('No containers on this machine') }}
                        </h2>
                        <p
                            class="max-w-md text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'The console runs commands inside containers. Deploy or adopt one, and it shows up here.',
                                )
                            }}
                        </p>
                    </div>

                    <div
                        v-else-if="!consoleLock.twoFactorEnabled"
                        class="flex grow flex-col items-center justify-center gap-5 px-6 text-center"
                    >
                        <div class="flex max-w-md flex-col gap-1.5">
                            <h2 class="text-[15px]/6 font-semibold">
                                {{
                                    $t(
                                        'The console needs two-factor authentication',
                                    )
                                }}
                            </h2>
                            <p class="text-[13px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'It runs commands on a real machine, so a password alone isn’t enough. Turn on two-factor for your account, then come back to unlock it.',
                                    )
                                }}
                            </p>
                        </div>
                        <AppButton
                            variant="primary"
                            size="sm"
                            :href="securityEdit()"
                        >
                            {{ $t('Set up two-factor') }}
                        </AppButton>
                    </div>

                    <form
                        v-else-if="!unlocked"
                        class="flex grow flex-col items-center justify-center gap-4 px-6 text-center"
                        @submit.prevent="unlock"
                    >
                        <div class="flex max-w-md flex-col gap-1.5">
                            <h2 class="text-[15px]/6 font-semibold">
                                {{ $t('Unlock the console') }}
                            </h2>
                            <p class="text-[13px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Enter the 6-digit code from your authenticator app. The console stays unlocked for :minutes minutes on this device.',
                                        {
                                            minutes: String(
                                                consoleLock.minutes,
                                            ),
                                        },
                                    )
                                }}
                            </p>
                        </div>
                        <InputOTP
                            v-model="unlockCode"
                            :maxlength="6"
                            :disabled="unlocking"
                            autofocus
                            @complete="unlock"
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                        <InputError :message="unlockError ?? undefined" />
                        <SubmitButton
                            :label="$t('Unlock')"
                            busy-label="Checking…"
                            :processing="unlocking"
                            :disabled="unlockCode.length !== 6"
                        />
                    </form>

                    <template v-else>
                        <div
                            ref="box"
                            class="min-h-0 grow overflow-auto px-5 pt-4 pb-2 font-ui-mono text-[12.5px]/5.5"
                            role="log"
                            :aria-label="$t('Command output')"
                        >
                            <p class="mb-3 text-(--ds-text-faint)">
                                #
                                {{
                                    $t(
                                        'One-off commands with sh -c, up to a minute each, no interactive input. Each one is recorded with your name.',
                                    )
                                }}
                            </p>
                            <p
                                v-if="!transcript.length"
                                class="text-(--ds-text-faint)"
                            >
                                {{ $t('Nothing run here yet. Try') }}
                                <span class="text-(--ds-text-secondary)"
                                    >ls -la</span
                                >
                                {{ $t('or') }}
                                <span class="text-(--ds-text-secondary)"
                                    >env</span
                                >.
                            </p>
                            <div
                                v-for="job in transcript"
                                :key="job.id"
                                class="mb-3 flex flex-col gap-0.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="text-(--ds-green)">$</span>
                                    <span class="text-(--ds-text)">{{
                                        job.command
                                    }}</span>
                                    <LoaderCircle
                                        v-if="
                                            ['queued', 'running'].includes(
                                                job.state,
                                            )
                                        "
                                        class="ml-auto size-3.5 animate-spin text-(--ds-text-faint)"
                                        :stroke-width="2"
                                    />
                                    <span
                                        v-else-if="
                                            job.result?.exit_code !== undefined
                                        "
                                        class="ml-auto text-[11px]"
                                        :class="
                                            job.result.exit_code === 0
                                                ? 'text-(--ds-text-faint)'
                                                : 'text-(--ds-red)'
                                        "
                                        >{{
                                            $t('exit :exit_code', {
                                                exit_code: String(
                                                    job.result.exit_code,
                                                ),
                                            })
                                        }}</span
                                    >
                                </div>
                                <div
                                    v-for="(line, i) in output(job)"
                                    :key="i"
                                    class="break-words whitespace-pre-wrap text-(--ds-text-secondary)"
                                >
                                    {{ line }}
                                </div>
                                <p v-if="job.error" class="text-(--ds-red)">
                                    {{ job.error.message }}
                                </p>
                            </div>
                        </div>
                        <form
                            class="flex shrink-0 items-center gap-2 border-t border-white/[0.06] px-5 py-3 font-ui-mono text-[13px]/5"
                            @submit.prevent="run"
                        >
                            <span class="text-(--ds-green)" aria-hidden="true"
                                >$</span
                            >
                            <span class="sr-only">{{ $t('Command') }}</span>
                            <input
                                ref="input"
                                v-model="command"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="$t('e.g. redis-cli INFO memory')"
                                class="h-6 min-w-0 grow bg-transparent text-(--ds-text) caret-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                                @keydown.up.prevent="recall(1)"
                                @keydown.down.prevent="recall(-1)"
                            />
                            <LoaderCircle
                                v-if="sending"
                                class="size-3.5 animate-spin text-(--ds-text-faint)"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            <span
                                v-else
                                class="text-[11px] text-(--ds-text-faint)"
                                >{{ $t('Enter to run') }}</span
                            >
                        </form>
                        <InputError
                            v-if="commandError"
                            class="px-5 pb-3"
                            :message="commandError"
                        />
                    </template>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
