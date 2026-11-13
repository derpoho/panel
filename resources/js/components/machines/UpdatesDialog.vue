<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';
import { SolarPower as Power } from '@/lib/solarIcons';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import StaleServicesNote from '@/components/machines/StaleServicesNote.vue';
import type { Restarts } from '@/components/machines/StaleServicesNote.vue';
import TickBox from '@/components/workspace/TickBox.vue';

const props = defineProps<{ machineId: number; machineName: string }>();
const open = defineModel<boolean>('open', { required: true });

type Package = {
    name: string;
    from: string | null;
    to: string | null;
    security: boolean;
};
type Report = {
    manager: string | null;
    packages: Package[];
    rebootRequired: boolean;
    restarts?: Restarts | null;
    applied: boolean;
    held?: string[];
};
type Snapshot = {
    checkedAt: string | null;
    complete: boolean;
    rebootRequired: boolean;
    restarts: Restarts | null;
    held: string[];
    packages: Package[];
    canPick: boolean;
};
type JobStatus = {
    state: string;
    canPick: boolean;
    phase: string | null;
    log: string[];
    error: { message?: string; next_step?: string } | null;
    report: Report | null;
    restarts: {
        failed: { unit: string; message: string | null }[];
        after: Restarts | null;
    } | null;
};

type Step =
    | 'checking'
    | 'ready'
    | 'installing'
    | 'done'
    | 'failed'
    | 'rebooting';

const step = ref<Step>('checking');
const report = ref<Report | null>(null);
const log = ref<string[]>([]);
const error = ref<string | null>(null);
const installedSecurityOnly = ref(false);
const doing = ref<'install' | 'restart'>('install');
const refreshing = ref(false);
const checkedAt = ref<string | null>(null);
const held = ref<string[]>([]);
const refreshError = ref<string | null>(null);
const logBox = useTemplateRef<HTMLElement>('logBox');
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let active = false;

const security = computed(
    () => report.value?.packages.filter((p) => p.security) ?? [],
);
const total = computed(() => report.value?.packages.length ?? 0);

const selected = ref<string[]>([]);
const canPick = ref(false);
const sorted = computed(() =>
    [...(report.value?.packages ?? [])].sort(
        (a, b) => Number(b.security) - Number(a.security),
    ),
);
const allSelected = computed(
    () => total.value > 0 && selected.value.length === total.value,
);

function pick(name: string, on: boolean): void {
    selected.value = on
        ? [...new Set([...selected.value, name])]
        : selected.value.filter((n) => n !== name);
}

function pickAll(on: boolean): void {
    selected.value = on
        ? (report.value?.packages.map((p) => p.name) ?? [])
        : [];
}

function pickSecurity(): void {
    selected.value = security.value.map((p) => p.name);
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(path: string, body: object = {}): Promise<string | null> {
    const response = await fetch(`/machines/${props.machineId}/${path}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(
            json.error ?? json.message ?? trans('That didn’t work. Try again.'),
        );
    }
    return json.jobId ?? null;
}

function follow(
    jobId: string,
    onUpdate?: (s: JobStatus) => void,
): Promise<JobStatus> {
    return new Promise((resolve) => {
        const tick = async () => {
            if (!active) return;
            try {
                const response = await fetch(
                    `/machines/${props.machineId}/updates/${jobId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    },
                );
                const status = (await response.json()) as JobStatus;
                onUpdate?.(status);
                if (!['queued', 'running'].includes(status.state)) {
                    resolve(status);
                    return;
                }
            } catch {}
            pollTimer = setTimeout(tick, 1500);
        };
        void tick();
    });
}

function failure(status: JobStatus): string {
    if (status.state === 'stale')
        return trans(
            'The machine stopped answering. Check it’s online and try again.',
        );
    return (
        [status.error?.message, status.error?.next_step]
            .filter(Boolean)
            .join(' ') || trans('That didn’t finish. Try again.')
    );
}

async function snapshot(): Promise<Snapshot | null> {
    try {
        const response = await fetch(`/machines/${props.machineId}/updates`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        return response.ok ? ((await response.json()) as Snapshot) : null;
    } catch {
        return null;
    }
}

async function check(): Promise<void> {
    error.value = null;
    refreshError.value = null;
    const kept = await snapshot();
    if (!active) return;
    if (kept?.checkedAt) {
        report.value = {
            manager: null,
            packages: kept.packages,
            rebootRequired: kept.rebootRequired,
            restarts: kept.restarts,
            applied: false,
        };
        selected.value = kept.packages.map((p) => p.name);
        canPick.value = kept.canPick;
        checkedAt.value = kept.checkedAt;
        held.value = kept.held;
        refreshing.value = true;
        step.value = 'ready';
    } else {
        report.value = null;
        step.value = 'checking';
    }
    try {
        const jobId = await post('updates/check');
        if (!jobId) throw new Error('Couldn’t start the check.');
        const status = await follow(jobId);
        if (status.state !== 'succeeded' || !status.report) {
            throw new Error(failure(status));
        }
        if (!active || (step.value !== 'ready' && step.value !== 'checking')) {
            return;
        }
        report.value = status.report;
        selected.value = status.report.packages.map((p) => p.name);
        canPick.value = status.canPick;
        checkedAt.value = new Date().toISOString();
        held.value = status.report.held ?? held.value;
        step.value = 'ready';
    } catch (cause) {
        if (!active) return;
        if (refreshing.value && step.value === 'ready') {
            refreshError.value = (cause as Error).message;
        } else {
            error.value = (cause as Error).message;
            step.value = 'failed';
        }
    } finally {
        refreshing.value = false;
    }
}

async function install(): Promise<void> {
    const packages = allSelected.value ? null : [...selected.value];
    const onlySecurity =
        packages !== null &&
        security.value.length > 0 &&
        packages.length === security.value.length &&
        security.value.every((p) => packages.includes(p.name));
    step.value = 'installing';
    doing.value = 'install';
    installedSecurityOnly.value = onlySecurity;
    error.value = null;
    log.value = [];
    try {
        const jobId = await post(
            'updates',
            packages
                ? { security_only: onlySecurity, packages }
                : { security_only: false },
        );
        if (!jobId) throw new Error('Couldn’t start the install.');
        const status = await follow(jobId, (s) => (log.value = s.log));
        if (status.state !== 'succeeded') throw new Error(failure(status));
        report.value = status.report;
        step.value = 'done';
        toast.success(
            trans('Updates installed on :machineName.', {
                machineName: String(props.machineName),
            }),
        );
    } catch (cause) {
        if (!active) return;
        error.value = (cause as Error).message;
        step.value = 'failed';
    }
}

async function restartServices(): Promise<void> {
    const before = report.value;
    step.value = 'installing';
    doing.value = 'restart';
    error.value = null;
    log.value = [];
    try {
        const jobId = await post('updates/restart');
        if (!jobId) throw new Error('Couldn’t start the restart.');
        const status = await follow(jobId, (s) => (log.value = s.log));
        if (status.state !== 'succeeded') throw new Error(failure(status));
        const after = status.restarts?.after ?? null;
        if (before) {
            report.value = {
                ...before,
                restarts: after,
                rebootRequired: after
                    ? after.reboot.length > 0
                    : before.rebootRequired,
            };
        }
        step.value = 'done';
        const failed = status.restarts?.failed ?? [];
        if (failed.length) {
            toast.error(
                trans('These didn’t come back up: :units', {
                    units: failed.map((f) => f.unit).join(', '),
                }),
            );
        } else {
            toast.success(
                trans('Services restarted on :machineName.', {
                    machineName: String(props.machineName),
                }),
            );
        }
    } catch (cause) {
        if (!active) return;
        error.value = (cause as Error).message;
        step.value = 'failed';
    }
}

async function reboot(): Promise<void> {
    try {
        await post('reboot');
        step.value = 'rebooting';
        toast.success(
            trans('Restarting :machineName. Alerts are held for 10 minutes.', {
                machineName: String(props.machineName),
            }),
        );
    } catch (cause) {
        toast.error((cause as Error).message);
    }
}

watch(
    () => log.value.length,
    async () => {
        await nextTick();
        if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight;
    },
);

watch(
    open,
    (value) => {
        active = value;
        clearTimeout(pollTimer);
        if (value && step.value !== 'installing') {
            void check();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    active = false;
    clearTimeout(pollTimer);
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('System updates') }}</DialogTitle>
                <DialogDescription>
                    {{
                        ' ' +
                        $t(
                            'Operating system packages waiting on :machineName, installed with :value. Your apps and databases in Docker aren’t touched.',
                            {
                                machineName,
                                value:
                                    report?.manager ??
                                    $t('its package manager'),
                            },
                        ) +
                        ' '
                    }}
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="step === 'checking'"
                class="flex items-center gap-2 py-6 text-[13px]/5 text-(--ds-text-muted)"
                role="status"
            >
                <LoaderCircle
                    class="size-3.5 animate-spin motion-reduce:animate-none"
                    :stroke-width="2"
                />
                {{ $t('Checking for updates. This can take a minute.') + ' ' }}
            </p>

            <template v-else-if="step === 'ready' && report">
                <p class="flex items-center gap-2 text-[13px]/5">
                    <span
                        class="size-2 rounded-full"
                        :class="
                            security.length
                                ? 'bg-(--ds-amber)'
                                : total
                                  ? 'bg-(--ds-blue)'
                                  : 'bg-(--ds-green)'
                        "
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{
                        total === 0
                            ? $t('Everything is up to date')
                            : $t(
                                  total === 1
                                      ? ':total update waiting'
                                      : ':total updates waiting',
                                  { total: String(total) },
                              )
                    }}</span>
                    <span
                        v-if="security.length"
                        class="text-(--ds-text-muted)"
                        >{{
                            $t('· :length for security', {
                                length: String(security.length ?? ''),
                            })
                        }}</span
                    >
                </p>

                <p
                    class="-mt-2 flex items-center gap-2 text-[12.5px]/4.5 text-(--ds-text-muted)"
                    role="status"
                >
                    <template v-if="refreshing">
                        <LoaderCircle
                            class="size-3 animate-spin motion-reduce:animate-none"
                            :stroke-width="2"
                        />
                        {{
                            $t(
                                'From the check :when. Checking again for anything newer…',
                                { when: timeAgo(checkedAt) },
                            )
                        }}
                    </template>
                    <span v-else-if="refreshError" class="text-(--ds-amber)">
                        {{
                            $t(
                                'From the check :when. Checking again didn’t work: :error',
                                {
                                    when: timeAgo(checkedAt),
                                    error: refreshError,
                                },
                            )
                        }}
                    </span>
                </p>

                <div
                    v-if="canPick && total > 1"
                    class="-mb-1 flex items-center gap-3 text-[13px]/5"
                >
                    <label
                        class="flex cursor-pointer items-center gap-2 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox
                            :model-value="allSelected"
                            @update:model-value="pickAll"
                        />
                        {{ $t('Select all') + ' ' }}
                    </label>
                    <button
                        v-if="security.length && security.length < total"
                        type="button"
                        class="cursor-pointer rounded-sm font-medium text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                        @click="pickSecurity"
                    >
                        {{ ' ' + $t('Security only') + ' ' }}
                    </button>
                </div>

                <ul
                    v-if="total"
                    class="max-h-64 divide-y divide-(--ds-divider) overflow-y-auto rounded-[10px] border border-(--ds-border)"
                    :aria-label="$t('Waiting updates')"
                >
                    <li v-for="pkg in sorted" :key="pkg.name">
                        <label
                            :class="
                                canPick
                                    ? 'cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] hover:bg-(--ds-row-hover)'
                                    : 'grid-cols-[minmax(0,1fr)_auto]'
                            "
                            class="grid items-center gap-3 px-3.5 py-2 text-[13px]/5"
                        >
                            <TickBox
                                v-if="canPick"
                                :model-value="selected.includes(pkg.name)"
                                :aria-label="
                                    $t('Install :name', {
                                        name: String(pkg.name),
                                    })
                                "
                                @update:model-value="
                                    (on: boolean) => pick(pkg.name, on)
                                "
                            />
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate font-ui-mono text-xs/5">{{
                                    pkg.name
                                }}</span>
                                <span
                                    v-if="pkg.security"
                                    class="shrink-0 rounded-[4px] bg-(--ds-amber)/12 px-1.5 text-[11px]/4.5 font-medium text-(--ds-amber)"
                                    >{{ $t('Security') }}</span
                                >
                            </span>
                            <span
                                class="truncate font-ui-mono text-[11px]/5 text-(--ds-text-faint)"
                                :title="`${pkg.from ?? ''} → ${pkg.to ?? ''}`"
                                >{{ pkg.to }}</span
                            >
                        </label>
                    </li>
                </ul>

                <p
                    v-if="held.length"
                    class="text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        $t(
                            'Held at their version, so updates leave them be: :names',
                            {
                                names: held.join(', '),
                            },
                        )
                    }}
                </p>
                <StaleServicesNote
                    v-if="report.restarts"
                    :restarts="report.restarts"
                    :busy="refreshing"
                    @restart="restartServices"
                />
                <p
                    v-else-if="report.rebootRequired"
                    class="text-[13px]/5 text-(--ds-amber)"
                >
                    {{
                        ' ' +
                        $t(
                            'An earlier update is waiting for a restart to finish.',
                        ) +
                        ' '
                    }}
                </p>
                <p
                    v-if="!canPick && total > 1"
                    class="text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'To pick which updates to install, update this machine’s daemon first:',
                        )
                    }}
                    <code class="font-ui-mono text-xs text-(--ds-text)"
                        >sudo serverosd update</code
                    >
                </p>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <AppButton
                        v-if="report.rebootRequired"
                        size="sm"
                        @click="reboot"
                    >
                        <Power
                            class="size-3.5 opacity-60"
                            :stroke-width="1.75"
                        />
                        {{ $t('Restart machine') + ' ' }}
                    </AppButton>
                    <AppButton
                        v-if="total"
                        size="sm"
                        variant="primary"
                        :disabled="selected.length === 0 || refreshing"
                        @click="install"
                    >
                        {{
                            selected.length === 0
                                ? $t('Pick updates to install')
                                : allSelected
                                  ? total === 1
                                      ? $t('Install update')
                                      : $t('Install all :total', {
                                            total: String(total),
                                        })
                                  : $t('Install :selectedCount of :total', {
                                        selectedCount: String(selected.length),
                                        total: String(total),
                                    })
                        }}
                    </AppButton>
                    <AppButton v-else size="sm" @click="open = false">
                        {{ ' ' + $t('Done') + ' ' }}
                    </AppButton>
                </div>
            </template>

            <template v-else-if="step === 'installing' || step === 'done'">
                <p class="flex items-center gap-2 text-[13px]/5" role="status">
                    <LoaderCircle
                        v-if="step === 'installing'"
                        class="size-3.5 animate-spin text-(--ds-text-muted) motion-reduce:animate-none"
                        :stroke-width="2"
                    />
                    <span
                        v-else
                        class="size-2 rounded-full bg-(--ds-green)"
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{
                        doing === 'restart'
                            ? step === 'installing'
                                ? $t('Restarting services…')
                                : $t('Services restarted')
                            : step === 'installing'
                              ? installedSecurityOnly
                                  ? $t('Installing security updates…')
                                  : 'Installing updates…'
                              : $t('Updates installed')
                    }}</span>
                    <span
                        v-if="step === 'installing'"
                        class="text-(--ds-text-muted)"
                        >{{ $t('You can close this; it keeps going.') }}</span
                    >
                </p>
                <pre
                    ref="logBox"
                    class="max-h-64 min-h-24 overflow-auto rounded-[10px] bg-(--ds-code) px-3.5 py-3 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-secondary)"
                    >{{ log.join('\n') || $t('Starting…') }}</pre>
                <StaleServicesNote
                    v-if="step === 'done' && report?.restarts"
                    :restarts="report.restarts"
                    @restart="restartServices"
                />
                <p
                    v-else-if="step === 'done' && report?.rebootRequired"
                    class="text-[13px]/5 text-(--ds-amber)"
                >
                    {{
                        ' ' +
                        $t(
                            'Some updates finish after a restart, usually the kernel. Restarting takes your services offline for a minute or two.',
                        ) +
                        ' '
                    }}
                </p>
                <div
                    v-if="step === 'done'"
                    class="flex items-center justify-end gap-2"
                >
                    <AppButton
                        v-if="report?.rebootRequired"
                        size="sm"
                        variant="primary"
                        @click="reboot"
                    >
                        <Power
                            class="size-3.5 opacity-70"
                            :stroke-width="1.75"
                        />
                        {{ $t('Restart now') + ' ' }}
                    </AppButton>
                    <AppButton size="sm" @click="open = false">
                        {{ report?.rebootRequired ? $t('Later') : $t('Done') }}
                    </AppButton>
                </div>
            </template>

            <p
                v-else-if="step === 'rebooting'"
                class="py-4 text-[13px]/5 text-(--ds-text-secondary)"
            >
                {{
                    $t(
                        ':machineName is restarting. It shows as online again in a minute or two. Alerts about it are held for 10 minutes.',
                        { machineName },
                    ) + ' '
                }}
            </p>

            <template v-else-if="step === 'failed'">
                <p class="text-[13px]/5 text-(--ds-red)">{{ error }}</p>
                <pre
                    v-if="log.length"
                    class="max-h-48 overflow-auto rounded-[10px] bg-(--ds-code) px-3.5 py-3 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-secondary)"
                    >{{ log.slice(-40).join('\n') }}</pre>
                <div class="flex justify-end">
                    <AppButton size="sm" @click="check">{{
                        $t('Check again')
                    }}</AppButton>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
