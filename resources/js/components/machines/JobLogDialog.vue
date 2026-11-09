<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import JobStatusBadge from '@/components/machines/JobStatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';

export type DaemonJob = {
    id: string;
    kind: string;
    service: string | null;
    /** What was typed, for console commands. */
    command?: string | null;
    state: string;
    phase: string | null;
    progress: number | null;
    log: string[];
    result: Record<string, unknown> | null;
    error: {
        phase?: string;
        message?: string;
        next_step?: string;
        output_tail?: string[];
    } | null;
    /** Why it failed in plain words, when it's a common cause (DeployFailure). */
    explanation?: {
        cause: string;
        fix: string;
        command?: string;
        alternative?: string;
        /** The fix is installing these packages: offer to, then retry. */
        install?: { label: string; packages: string[] };
    } | null;
    createdAt: string | null;
    updatedAt: string | null;
    retryable: boolean;
    stale: boolean;
};

/**
 * A deploy's live log, in the search palette's style. The page polls, so the
 * log grows while it's open. From here a deploy can be retried exactly as it
 * was sent, or a stuck one let go.
 */
const props = defineProps<{
    job: DaemonJob | null;
    machineId: number;
    canManage: boolean;
}>();

const copiedInstall = ref(false);
function copyInstall(): void {
    const command = props.job?.explanation?.command;
    if (!command) return;
    void navigator.clipboard.writeText(command).then(() => {
        copiedInstall.value = true;
        window.setTimeout(() => (copiedInstall.value = false), 1500);
    });
}

const emit = defineEmits<{
    retried: [jobId: string];
    changed: [];
    openLibrary: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const active = computed(
    () => props.job?.state === 'queued' || props.job?.state === 'running',
);
const failed = computed(() =>
    ['failed', 'refused', 'timed_out', 'cancelled'].includes(
        props.job?.state ?? '',
    ),
);

const canRetry = computed(
    () =>
        props.canManage &&
        !!props.job?.retryable &&
        (!active.value || props.job.stale),
);
const canStopWaiting = computed(
    () => props.canManage && active.value && !!props.job?.stale,
);
const retryLabel = computed(() =>
    props.job?.state === 'succeeded'
        ? trans('Redeploy')
        : trans('Retry deploy'),
);

const started = computed(() =>
    props.job?.createdAt
        ? new Date(props.job.createdAt).toLocaleString()
        : null,
);

/* Follow the log as it grows, unless they've scrolled up to read. */
const logBox = useTemplateRef<HTMLElement>('logBox');
let following = true;

function onLogScroll(): void {
    const box = logBox.value;

    if (box) {
        following = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
    }
}

watch(
    () => props.job?.log.length,
    () => {
        if (following) {
            void nextTick(() => {
                if (logBox.value) {
                    logBox.value.scrollTop = logBox.value.scrollHeight;
                }
            });
        }
    },
    { immediate: true },
);

watch(open, (isOpen) => {
    if (isOpen) {
        following = true;
        error.value = null;
        void nextTick(() => {
            if (logBox.value) {
                logBox.value.scrollTop = logBox.value.scrollHeight;
            }
        });
    }
});

const busy = ref<'retry' | 'stop' | null>(null);
const error = ref<string | null>(null);

async function post(action: 'retry' | 'abandon'): Promise<string | null> {
    const response = await fetch(
        `/machines/${props.machineId}/jobs/${props.job?.id}/${action}`,
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
        },
    );
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        error.value = body.error ?? trans('That didn’t work. Try again.');

        return null;
    }

    return body.jobId ?? null;
}

async function retry(): Promise<void> {
    if (!canRetry.value || busy.value) {
        return;
    }

    busy.value = 'retry';
    error.value = null;

    try {
        const jobId = await post('retry');

        if (jobId) {
            emit('retried', jobId);
        }
    } finally {
        busy.value = null;
    }
}

/*
 * Install what the deploy was missing (Docker, say) through the daemon,
 * follow it until it's done, then retry the deploy as it was sent.
 */
const installing = ref<'installing' | 'retrying' | null>(null);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function installAndRetry(): Promise<void> {
    const install = props.job?.explanation?.install;
    if (!install || installing.value || busy.value) {
        return;
    }

    installing.value = 'installing';
    error.value = null;

    try {
        const response = await fetch(`/machines/${props.machineId}/updates`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                security_only: false,
                packages: install.packages,
            }),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok || !body.jobId) {
            error.value = body.error ?? trans('That didn’t work. Try again.');

            return;
        }

        // Package installs take a minute or two.
        for (let checks = 0; checks < 300; checks++) {
            await new Promise((resolve) => window.setTimeout(resolve, 2000));
            const status = await fetch(
                `/machines/${props.machineId}/updates/${body.jobId}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            )
                .then((r) => r.json())
                .catch(() => null);

            if (status?.state === 'succeeded') {
                break;
            }
            if (
                [
                    'failed',
                    'refused',
                    'timed_out',
                    'cancelled',
                    'stale',
                ].includes(status?.state)
            ) {
                error.value = status?.error?.message
                    ? trans('The install didn’t work: :message', {
                          message: String(status.error.message),
                      })
                    : trans(
                          'The install didn’t work. Try the command above on the machine instead.',
                      );

                return;
            }
        }

        installing.value = 'retrying';
        const jobId = await post('retry');
        if (jobId) {
            emit('retried', jobId);
        }
    } finally {
        installing.value = null;
    }
}

async function stopWaiting(): Promise<void> {
    if (!canStopWaiting.value || busy.value) {
        return;
    }

    busy.value = 'stop';
    error.value = null;

    try {
        if (await post('abandon')) {
            emit('changed');
        }
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="job"
            class="workspace-panel ds-modal top-[10%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text) sm:max-w-2xl"
            :show-close-button="false"
        >
            <div
                class="flex h-12 items-center gap-2.5 border-b border-(--ds-border) px-4"
            >
                <DialogTitle class="truncate text-sm font-medium">
                    {{ job.service ?? $t('Deploy') }}
                </DialogTitle>
                <JobStatusBadge
                    :state="job.state"
                    :phase="job.phase"
                    :progress="job.progress"
                />
                <span
                    v-if="started"
                    class="ml-auto hidden text-xs text-(--ds-text-faint) sm:inline"
                >
                    {{ started }}
                </span>
                <kbd
                    class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px text-[11px]/3.5 text-(--ds-text-muted)"
                    :class="{ 'ml-auto sm:ml-0': true }"
                    aria-hidden="true"
                >
                    Esc
                </kbd>
            </div>
            <DialogDescription class="sr-only">
                {{
                    ' ' +
                    $t(
                        'The deploy’s log, its error if it failed, and actions to retry it.',
                    ) +
                    ' '
                }}
            </DialogDescription>

            <div class="flex max-h-[70vh] flex-col gap-4 overflow-y-auto p-4">
                <div
                    v-if="active && job.progress !== null"
                    class="h-1 overflow-hidden rounded-full bg-(--ds-hover)"
                    role="progressbar"
                    :aria-valuenow="job.progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-(--ds-amber) transition-[width] duration-500 ease-out"
                        :style="{ width: `${job.progress}%` }"
                    />
                </div>

                <p
                    v-if="active && job.stale"
                    class="text-[13px]/5 text-(--ds-text-secondary)"
                >
                    {{
                        ' ' +
                        $t(
                            'No news from the daemon for over 10 minutes. It may have restarted, or never received this job.',
                        )
                    }}
                    <template v-if="job.retryable">
                        {{
                            ' ' + $t('You can retry it, or stop waiting.') + ' '
                        }}
                    </template>
                    <template v-else>{{
                        $t('You can stop waiting on it.')
                    }}</template>
                </p>

                <div
                    ref="logBox"
                    class="max-h-[45vh] min-h-32 overflow-y-auto rounded-[10px] border border-(--ds-border) bg-(--ds-code) py-2.5 font-ui-mono text-xs/5"
                    role="log"
                    :aria-label="$t('Deploy log')"
                    aria-live="polite"
                    @scroll="onLogScroll"
                >
                    <p
                        v-if="job.log.length === 0"
                        class="px-3.5 text-(--ds-text-faint)"
                    >
                        {{
                            active
                                ? $t('Waiting for the daemon to start…')
                                : $t('This job didn’t log anything.')
                        }}
                    </p>
                    <div
                        v-for="(line, index) in job.log"
                        :key="index"
                        class="flex gap-3 px-3.5 hover:bg-white/3"
                    >
                        <span
                            class="w-6 shrink-0 text-right text-(--ds-text-faint) select-none"
                            >{{ index + 1 }}</span
                        >
                        <span
                            class="min-w-0 break-words whitespace-pre-wrap text-(--ds-text-secondary)"
                            >{{ line }}</span
                        >
                    </div>
                </div>

                <div
                    v-if="job.error"
                    class="flex flex-col gap-2 rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 p-3.5"
                >
                    <p class="text-[13px]/5 font-medium text-(--ds-red)">
                        {{
                            job.explanation?.cause ??
                            job.error.message ??
                            $t('The deploy failed.')
                        }}
                    </p>
                    <div
                        v-if="job.explanation"
                        class="flex flex-col gap-2 text-[13px]/5 text-(--ds-text-secondary)"
                    >
                        <p>{{ job.explanation.fix }}</p>
                        <div
                            v-if="job.explanation.install && canManage"
                            class="flex items-center gap-2"
                        >
                            <SubmitButton
                                size="sm"
                                :processing="installing !== null"
                                @click="installAndRetry"
                            >
                                {{
                                    installing === 'installing'
                                        ? $t('Installing…')
                                        : installing === 'retrying'
                                          ? $t('Retrying the deploy…')
                                          : $t(':label and retry', {
                                                label: String(
                                                    job.explanation.install
                                                        .label,
                                                ),
                                            })
                                }}
                            </SubmitButton>
                            <span
                                v-if="installing === 'installing'"
                                class="text-xs/4 text-(--ds-text-muted)"
                            >
                                {{ ' ' + $t('Usually a minute or two.') + ' ' }}
                            </span>
                        </div>
                        <div
                            v-if="job.explanation.command"
                            class="flex items-center gap-2 rounded-lg bg-(--ds-code) py-1.5 pr-1.5 pl-3"
                        >
                            <code
                                class="min-w-0 grow truncate font-ui-mono text-xs text-(--ds-text)"
                                >{{ job.explanation.command }}</code
                            >
                            <AppButton size="sm" @click="copyInstall">
                                {{ copiedInstall ? $t('Copied') : $t('Copy') }}
                            </AppButton>
                        </div>
                        <p
                            v-if="job.explanation.alternative"
                            class="text-xs/4 text-(--ds-text-muted)"
                        >
                            {{ job.explanation.alternative }}
                        </p>
                        <!-- What the machine said, for anyone who wants it -->
                        <p
                            v-if="job.error.message"
                            class="font-ui-mono text-[11px]/4.5 text-(--ds-text-muted)"
                        >
                            {{ job.error.message }}
                        </p>
                    </div>
                    <p
                        v-else-if="job.error.next_step"
                        class="text-[13px]/5 text-(--ds-text-secondary)"
                    >
                        {{ job.error.next_step }}
                    </p>
                    <pre
                        v-if="job.error.output_tail?.length"
                        class="max-h-48 overflow-auto rounded-lg bg-(--ds-code) px-3 py-2 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-muted)"
                        >{{ job.error.output_tail.join('\n') }}</pre>
                </div>

                <p
                    v-if="job.state === 'succeeded' && job.result"
                    class="text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('Running as') }}
                    <span class="font-ui-mono text-(--ds-text)">{{
                        job.result.container
                    }}</span>
                    <template v-if="job.result.port">
                        {{ ' ' + $t('on port') }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            job.result.port
                        }}</span>
                    </template>
                </p>

                <p
                    v-if="failed && !job.retryable && canManage"
                    class="text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'This deploy was sent before ServerOS kept deploy settings, so it can’t be retried from here.',
                        )
                    }}
                    <button
                        type="button"
                        class="cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                        @click="emit('openLibrary')"
                    >
                        {{ ' ' + $t('Deploy it again from the library') + ' ' }}
                    </button>
                </p>

                <InputError :message="error ?? undefined" />
            </div>

            <form
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                @submit.prevent="retry"
            >
                <span
                    class="mr-auto hidden font-ui-mono text-[11px] text-(--ds-text-faint) sm:inline"
                    :title="job.id"
                >
                    {{ job.id.slice(0, 8) }}
                </span>
                <AppButton
                    v-if="canStopWaiting"
                    size="sm"
                    variant="quiet"
                    :disabled="busy !== null"
                    @click="stopWaiting"
                >
                    {{ busy === 'stop' ? $t('Stopping…') : $t('Stop waiting') }}
                </AppButton>
                <AppButton size="sm" variant="quiet" @click="open = false">
                    {{ ' ' + $t('Close') + ' ' }}
                </AppButton>
                <SubmitButton
                    v-if="canRetry"
                    :label="retryLabel"
                    busy-label="Queuing…"
                    :processing="busy === 'retry'"
                    :disabled="busy === 'stop'"
                />
            </form>
        </DialogContent>
    </Dialog>
</template>
