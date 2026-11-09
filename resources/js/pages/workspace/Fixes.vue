<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    ChevronDown,
    Minus,
    Plus,
    X,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FixShockwave from '@/components/fixes/FixShockwave.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { appButtonClasses } from '@/lib/appButton';
import { renderMarkdown } from '@/lib/markdown';

type State =
    | 'investigating'
    | 'needs_approval'
    | 'nothing_to_do'
    | 'running'
    | 'fixed'
    | 'failed'
    | 'declined'
    | 'error';

type FixRow = {
    id: number;
    title: string;
    state: State;
    machine: string;
    service: string | null;
    createdAt: string;
};

type Step = {
    type: string;
    service: string;
    service_name: string;
    command?: string;
    why: string;
    label: string;
    result: { state: string; message?: string; log?: string[] } | null;
};

type Detail = {
    id: number;
    title: string | null;
    state: State;
    machine: string;
    service: string | null;
    note: string | null;
    cause: string | null;
    explanation: string | null;
    confidence: 'low' | 'medium' | 'high' | null;
    model: string | null;
    error: string | null;
    steps: Step[];
    logs: string[];
    errors: string[];
    by: string | null;
    decidedBy: string | null;
    createdAt: string;
    decidedAt: string | null;
    finishedAt: string | null;
};

/** This hour's investigations: how many, of how many, and when one frees up. */
type Allowance = {
    used: number;
    limit: number;
    ownKey: boolean;
    ownKeyLimit: number;
    resetsAt: string | null;
};

type MachineOption = {
    id: number;
    name: string;
    services: { key: string; name: string; status: string | null }[];
};

const props = defineProps<{
    fixes: FixRow[];
    selected: Detail | null;
    machines: MachineOption[];
    can: { start: boolean; approve: boolean };
    twoFactor: boolean;
    planAllows: boolean;
    allowed: string[];
    allowance: Allowance;
}>();

/** Workspace pages live on the workspace's own host, so paths are enough. */
const base = computed(() => '/fixes');

/* The list: what needs you, what's going on, then the past by day. */
const WAITING: State[] = ['needs_approval'];
const ACTIVE: State[] = ['investigating', 'running'];
const waiting = computed(() =>
    props.fixes.filter((f) => WAITING.includes(f.state)),
);
const active = computed(() =>
    props.fixes.filter((f) => ACTIVE.includes(f.state)),
);
const past = computed(() => {
    const groups: { label: string; items: FixRow[] }[] = [];
    for (const f of props.fixes.filter(
        (f) => !WAITING.includes(f.state) && !ACTIVE.includes(f.state),
    )) {
        const label = dayLabel(f.createdAt);
        const group = groups.find((g) => g.label === label);
        if (group) group.items.push(f);
        else groups.push({ label, items: [f] });
    }

    return groups;
});
const ordered = computed(() => [
    ...waiting.value,
    ...active.value,
    ...past.value.flatMap((g) => g.items),
]);

function dayLabel(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const days = Math.round(
        (new Date(today.toDateString()).getTime() -
            new Date(d.toDateString()).getTime()) /
            86_400_000,
    );
    if (days === 0) return trans('Today');
    if (days === 1) return trans('Yesterday');

    return d.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
    });
}
function shortDate(iso: string): string {
    const d = new Date(iso);
    const today = new Date();

    return d.toDateString() === today.toDateString()
        ? d.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
        : d
              .toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
              .toLowerCase();
}
function longDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function open(id: number): void {
    router.get(
        base.value,
        { fix: id },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['selected', 'fixes'],
        },
    );
}
const index = computed(() =>
    ordered.value.findIndex((f) => f.id === props.selected?.id),
);
function step(by: 1 | -1): void {
    const next = ordered.value[index.value + by];
    if (next) open(next.id);
}

/* Keep an eye on what's still going: the agent looking, or steps running. */
let timer: ReturnType<typeof setInterval> | null = null;
watch(
    () =>
        [
            props.selected?.state,
            props.fixes.some((f) => ACTIVE.includes(f.state)),
        ] as const,
    ([state, anyActive]) => {
        const busy =
            (state !== undefined && ACTIVE.includes(state)) || anyActive;
        if (busy && !timer) {
            timer = setInterval(
                () => router.reload({ only: ['fixes', 'selected'] }),
                2500,
            );
        } else if (!busy && timer) {
            clearInterval(timer);
            timer = null;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => timer && clearInterval(timer));

/* Approving: the shockwave, then the request. */
const panel = ref<HTMLElement | null>(null);
const wave = ref<InstanceType<typeof FixShockwave> | null>(null);
const deciding = ref(false);
const decideError = ref<string | null>(null);
const content = ref<HTMLElement | null>(null);
function approve(event: MouseEvent): void {
    if (!props.selected || deciding.value) return;
    deciding.value = true;
    decideError.value = null;
    const rect = panel.value?.getBoundingClientRect();
    const x = rect ? event.clientX - rect.left : 0;
    const y = rect ? event.clientY - rect.top : 0;
    const id = props.selected.id;
    const shown = wave.value?.play(x, y) ?? {
        peak: Promise.resolve(),
        done: Promise.resolve(),
    };
    // Sent at the wave's peak, so the page doesn't re-render under it as it starts.
    void shown.peak.then(() =>
        router.post(
            `${base.value}/${id}/approve`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) =>
                    (decideError.value =
                        errors.approve ?? trans('It couldn’t be approved.')),
                onFinish: () =>
                    void shown.done.then(() => (deciding.value = false)),
            },
        ),
    );
}
function decline(): void {
    if (!props.selected) return;
    router.post(
        `${base.value}/${props.selected.id}/decline`,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/* The hour's allowance: shown as it's used, and clearly once it's gone. */
const outOfAllowance = computed(
    () => props.allowance.used >= props.allowance.limit,
);
const backAt = computed(() =>
    props.allowance.resetsAt
        ? new Date(props.allowance.resetsAt).toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);
const usedUp = computed(() =>
    outOfAllowance.value
        ? trans('You’ve used this hour’s :limit investigations.', {
              limit: String(props.allowance.limit),
          }) +
          ' ' +
          (backAt.value
              ? trans('You can ask again at :time.', { time: backAt.value })
              : trans('You can ask again soon.'))
        : null,
);
// The moment one frees up, ask the server again rather than counting here.
let freeTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => props.allowance.resetsAt,
    (at) => {
        if (freeTimer) clearTimeout(freeTimer);
        freeTimer = null;
        if (!at) return;
        const wait = new Date(at).getTime() - Date.now() + 1500;
        freeTimer = setTimeout(
            () => router.reload({ only: ['allowance'] }),
            Math.max(1000, Math.min(wait, 3_600_000)),
        );
    },
    { immediate: true },
);
onBeforeUnmount(() => freeTimer && clearTimeout(freeTimer));

const asking = ref(false);
const form = useForm({ machine: '' as string | number, service: '', note: '' });
/** Refusals that aren't about a field: the hour's allowance, or too many at once. */
const limitError = computed(
    () => (form.errors as Record<string, string | undefined>).limit ?? null,
);
const chosenMachine = computed(() =>
    props.machines.find((m) => String(m.id) === String(form.machine)),
);
function startAsking(): void {
    form.reset();
    form.clearErrors();
    if (props.machines.length === 1) form.machine = props.machines[0].id;
    asking.value = true;
}
/* Arrived from a service's Look into it: ask straight away about it. */
onMounted(() => {
    const query = new URLSearchParams(window.location.search);
    const machine = props.machines.find(
        (m) => String(m.id) === query.get('machine'),
    );
    if (!props.can.start || !machine) return;
    startAsking();
    form.machine = machine.id;
    const service = query.get('service');
    if (service && machine.services.some((s) => s.key === service))
        form.service = service;
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname,
    );
});

function submit(): void {
    form.post(base.value, {
        preserveScroll: true,
        onSuccess: () => (asking.value = false),
    });
}

/* How each state looks. */
const tone: Record<State, string> = {
    investigating: 'text-(--ds-blue)',
    needs_approval: 'text-(--ds-amber)',
    nothing_to_do: 'text-(--ds-text-faint)',
    running: 'text-(--ds-blue)',
    fixed: 'text-(--ds-green)',
    failed: 'text-(--ds-red)',
    declined: 'text-(--ds-text-faint)',
    error: 'text-(--ds-red)',
};
const stateLabel: Record<State, string> = {
    investigating: trans('Looking into it'),
    needs_approval: trans('Needs your approval'),
    nothing_to_do: trans('Nothing to run'),
    running: trans('Running the fix'),
    fixed: trans('Fixed'),
    failed: trans('The fix didn’t work'),
    declined: trans('Turned down'),
    error: trans('The agent got stuck'),
};
const glow = computed(() => {
    switch (props.selected?.state) {
        case 'fixed':
            return 'rgb(34 197 94 / 0.16)';
        case 'needs_approval':
            return 'rgb(245 158 11 / 0.12)';
        case 'failed':
        case 'error':
            return 'rgb(239 68 68 / 0.13)';
        case 'running':
        case 'investigating':
            return 'rgb(0 229 214 / 0.13)';
        default:
            return 'transparent';
    }
});

const showDiagnosis = ref(true);
const showEvidence = ref(false);
const explanation = computed(() =>
    props.selected?.explanation
        ? renderMarkdown(props.selected.explanation)
        : '',
);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const field =
    'h-9 w-full rounded-md border border-(--ds-border) bg-transparent px-3 text-[13px]/5';
</script>

<template>
    <Head :title="$t('Fixes')" />
    <WorkspacePage :title="$t('Fixes')">
        <template #description>
            {{
                $t(
                    'The agent reads a machine’s logs and state, explains what’s wrong and proposes a fix. Nothing runs until you approve it.',
                )
            }}
        </template>
        <template #actions>
            <span
                v-if="can.start && outOfAllowance"
                class="text-[12.5px]/5 text-(--ds-text-muted)"
            >
                {{
                    backAt
                        ? $t(':used of :limit used · back at :time', {
                              used: String(allowance.used),
                              limit: String(allowance.limit),
                              time: backAt,
                          })
                        : $t(':used of :limit used', {
                              used: String(allowance.used),
                              limit: String(allowance.limit),
                          })
                }}
            </span>
            <button
                v-if="can.start"
                type="button"
                :class="appButtonClasses('primary', 'md', focusRing)"
                :disabled="outOfAllowance"
                :title="usedUp ?? undefined"
                @click="startAsking"
            >
                <Plus class="size-4" />{{ $t('Look into something') }}
            </button>
        </template>

        <div
            class="grid h-[calc(100dvh-17.5rem)] min-h-[560px] overflow-hidden rounded-xl border border-(--ds-border) bg-(--ds-surface,transparent) md:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)]"
        >
            <nav
                class="flex flex-col gap-0.5 overflow-y-auto border-b border-(--ds-border) p-3 md:border-r md:border-b-0"
                :aria-label="$t('Fixes')"
            >
                <p
                    v-if="!fixes.length"
                    class="px-3 py-10 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        can.start
                            ? $t(
                                  'Nothing yet. When something looks wrong on a machine, ask the agent to look into it.',
                              )
                            : planAllows
                              ? $t('Nothing yet.')
                              : $t('The server agent comes with Pro.')
                    }}
                </p>

                <template v-if="waiting.length || active.length">
                    <p
                        class="flex items-center gap-2 px-3 pt-2 pb-1.5 text-[13px]/5 font-medium text-(--ds-amber)"
                    >
                        <span
                            class="h-3.5 w-[3px] rounded-full bg-(--ds-amber)"
                            aria-hidden="true"
                        />
                        {{
                            waiting.length
                                ? $t('Needs you')
                                : $t('Going on now')
                        }}
                    </p>
                    <button
                        v-for="f in [...waiting, ...active]"
                        :key="f.id"
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-(--ds-row-hover)"
                        :class="[
                            focusRing,
                            selected?.id === f.id ? 'bg-(--ds-row-hover)' : '',
                        ]"
                        @click="open(f.id)"
                    >
                        <span
                            class="fix-state-icon shrink-0"
                            :class="[
                                tone[f.state],
                                ACTIVE.includes(f.state) ? 'is-busy' : '',
                            ]"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate text-[14px]/5">{{
                            f.title
                        }}</span>
                        <span
                            class="shrink-0 text-[12.5px]/5 text-(--ds-text-faint)"
                            >{{ shortDate(f.createdAt) }}</span
                        >
                    </button>
                </template>

                <template v-if="past.length">
                    <p
                        class="fix-past my-3 flex items-center gap-3 px-1 text-[12.5px]/5 text-(--ds-text-faint)"
                        :class="waiting.length || active.length ? '' : 'mt-1'"
                    >
                        <span class="fix-wave flex-1" aria-hidden="true" />{{
                            $t('Past')
                        }}<span class="fix-wave flex-1" aria-hidden="true" />
                    </p>
                    <div
                        v-for="g in past"
                        :key="g.label"
                        class="mb-3 flex flex-col gap-0.5"
                    >
                        <p
                            class="flex items-center justify-between px-3 pt-1 pb-1 text-[12.5px]/5 text-(--ds-text-muted)"
                        >
                            <span>{{ g.label }}</span
                            ><span class="text-(--ds-text-faint)">{{
                                g.items.length
                            }}</span>
                        </p>
                        <button
                            v-for="f in g.items"
                            :key="f.id"
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-(--ds-row-hover)"
                            :class="[
                                focusRing,
                                selected?.id === f.id
                                    ? 'bg-(--ds-row-hover)'
                                    : '',
                            ]"
                            @click="open(f.id)"
                        >
                            <span
                                class="grid size-[18px] shrink-0 place-items-center rounded-full"
                                :class="tone[f.state]"
                                aria-hidden="true"
                            >
                                <Check
                                    v-if="f.state === 'fixed'"
                                    class="size-3.5"
                                    :stroke-width="3"
                                />
                                <X
                                    v-else-if="
                                        f.state === 'failed' ||
                                        f.state === 'error'
                                    "
                                    class="size-3.5"
                                    :stroke-width="3"
                                />
                                <Minus
                                    v-else
                                    class="size-3.5"
                                    :stroke-width="3"
                                />
                            </span>
                            <span
                                class="min-w-0 flex-1 truncate text-[14px]/5 text-(--ds-text-secondary)"
                                >{{ f.title }}</span
                            >
                            <span
                                class="shrink-0 text-[12.5px]/5 text-(--ds-text-faint)"
                                >{{ shortDate(f.createdAt) }}</span
                            >
                        </button>
                    </div>
                </template>
            </nav>

            <section
                ref="panel"
                class="relative isolate flex min-w-0 flex-col overflow-hidden"
            >
                <div
                    class="fix-glow pointer-events-none absolute inset-0 -z-10"
                    :style="{ '--fix-glow': glow }"
                    aria-hidden="true"
                />
                <FixShockwave ref="wave" :target="content" />

                <div
                    v-if="!selected"
                    class="grid flex-1 place-items-center p-10 text-[13px]/5 text-(--ds-text-faint)"
                >
                    {{ $t('Choose a fix to see what the agent found.') }}
                </div>

                <template v-else>
                    <div class="flex items-center gap-1 px-4 pt-3">
                        <button
                            type="button"
                            :class="appButtonClasses('quiet', 'sm', focusRing)"
                            :disabled="index <= 0"
                            :aria-label="$t('Newer')"
                            @click="step(-1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            :class="appButtonClasses('quiet', 'sm', focusRing)"
                            :disabled="index >= ordered.length - 1"
                            :aria-label="$t('Older')"
                            @click="step(1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                        <span
                            class="ml-auto text-[12.5px]/5"
                            :class="tone[selected.state]"
                            >{{ stateLabel[selected.state] }}</span
                        >
                    </div>

                    <div
                        ref="content"
                        class="flex-1 overflow-y-auto px-6 pt-6 pb-8 sm:px-12"
                    >
                        <div class="flex items-start justify-between gap-6">
                            <span
                                class="grid size-14 place-items-center rounded-2xl bg-(--ds-hover)"
                                aria-hidden="true"
                            >
                                <span
                                    class="fix-state-icon is-large"
                                    :class="[
                                        tone[selected.state],
                                        ACTIVE.includes(selected.state)
                                            ? 'is-busy'
                                            : '',
                                    ]"
                                />
                            </span>
                            <span
                                class="pt-2 text-[13px]/5 text-(--ds-text-muted)"
                                >{{ longDate(selected.createdAt) }}</span
                            >
                        </div>
                        <h2
                            class="mt-4 text-[24px]/8 font-semibold tracking-[-0.01em]"
                        >
                            <span v-if="selected.title">{{
                                selected.title
                            }}</span>
                            <span v-else class="fix-shimmer">{{
                                $t('Looking into :what', {
                                    what: selected.service ?? selected.machine,
                                })
                            }}</span>
                        </h2>

                        <button
                            type="button"
                            class="mt-7 flex cursor-pointer items-center gap-1 text-[13px]/5 font-medium text-(--ds-text-secondary)"
                            :class="focusRing"
                            :aria-expanded="showDiagnosis"
                            @click="showDiagnosis = !showDiagnosis"
                        >
                            {{ $t('Diagnosis')
                            }}<ChevronDown
                                class="size-3.5 transition-transform"
                                :class="showDiagnosis ? '' : '-rotate-90'"
                            />
                        </button>
                        <dl
                            v-if="showDiagnosis"
                            class="mt-3 grid grid-cols-[8.5rem_minmax(0,1fr)] gap-x-6 gap-y-2.5 text-[14px]/6"
                        >
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Machine') }}
                            </dt>
                            <dd>{{ selected.machine }}</dd>
                            <template v-if="selected.service">
                                <dt class="text-(--ds-text-muted)">
                                    {{ $t('Service') }}
                                </dt>
                                <dd>{{ selected.service }}</dd>
                            </template>
                            <template v-if="selected.cause">
                                <dt class="text-(--ds-text-muted)">
                                    {{ $t('Cause') }}
                                </dt>
                                <dd>{{ selected.cause }}</dd>
                            </template>
                            <template v-if="selected.confidence">
                                <dt class="text-(--ds-text-muted)">
                                    {{ $t('How sure') }}
                                </dt>
                                <dd class="flex items-center gap-2">
                                    <span
                                        class="flex gap-0.5"
                                        aria-hidden="true"
                                    >
                                        <span
                                            v-for="n in 3"
                                            :key="n"
                                            class="h-2 w-4 rounded-full"
                                            :class="
                                                n <=
                                                { low: 1, medium: 2, high: 3 }[
                                                    selected.confidence
                                                ]
                                                    ? 'bg-(--ds-text)'
                                                    : 'bg-(--ds-border)'
                                            "
                                        />
                                    </span>
                                    {{
                                        {
                                            low: $t('Not very'),
                                            medium: $t('Fairly'),
                                            high: $t('Very'),
                                        }[selected.confidence]
                                    }}
                                </dd>
                            </template>
                            <template v-if="selected.note">
                                <dt class="text-(--ds-text-muted)">
                                    {{ $t('You said') }}
                                </dt>
                                <dd class="text-(--ds-text-secondary)">
                                    {{ selected.note }}
                                </dd>
                            </template>
                            <dt class="text-(--ds-text-muted)">
                                {{ $t('Agent') }}
                            </dt>
                            <dd class="text-(--ds-text-secondary)">
                                ServerOS<span v-if="selected.model">
                                    ·
                                    <code class="font-ui-mono text-[12.5px]">{{
                                        selected.model
                                    }}</code></span
                                >
                            </dd>
                        </dl>

                        <p
                            v-if="selected.state === 'investigating'"
                            class="fix-shimmer mt-8 text-[15px]/6"
                        >
                            {{ $t('Reading the logs…') }}
                        </p>
                        <p
                            v-else-if="selected.state === 'error'"
                            class="mt-8 text-[14px]/6 text-(--ds-red)"
                        >
                            {{ selected.error }}
                        </p>
                        <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown sanitises -->
                        <div
                            v-if="explanation"
                            class="fix-prose mt-8 text-[15px]/7 text-(--ds-text-secondary)"
                            v-html="explanation"
                        />

                        <template v-if="selected.steps.length">
                            <h3
                                class="mt-9 text-[13px]/5 font-medium text-(--ds-text-secondary)"
                            >
                                {{
                                    selected.state === 'needs_approval'
                                        ? $t('What it wants to do')
                                        : $t('What it did')
                                }}
                            </h3>
                            <ol class="mt-3 flex flex-col gap-2">
                                <li
                                    v-for="(s, i) in selected.steps"
                                    :key="i"
                                    class="rounded-xl border border-(--ds-border) px-4 py-3"
                                >
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="grid size-6 shrink-0 place-items-center rounded-full text-[12px]/4 font-semibold"
                                            :class="
                                                s.result?.state === 'succeeded'
                                                    ? 'bg-(--ds-green)/15 text-(--ds-green)'
                                                    : s.result?.state ===
                                                        'failed'
                                                      ? 'bg-(--ds-red)/15 text-(--ds-red)'
                                                      : s.result?.state ===
                                                          'running'
                                                        ? 'fix-step-busy bg-(--ds-blue)/15 text-(--ds-blue)'
                                                        : 'bg-(--ds-hover) text-(--ds-text-muted)'
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    s.result?.state ===
                                                    'succeeded'
                                                "
                                                class="size-3.5"
                                                :stroke-width="3"
                                            />
                                            <X
                                                v-else-if="
                                                    s.result?.state === 'failed'
                                                "
                                                class="size-3.5"
                                                :stroke-width="3"
                                            />
                                            <template v-else>{{
                                                i + 1
                                            }}</template>
                                        </span>
                                        <span
                                            class="min-w-0 flex-1 text-[14px]/5"
                                        >
                                            <span class="font-medium">{{
                                                s.label
                                            }}</span>
                                            <span
                                                class="text-(--ds-text-muted)"
                                            >
                                                · {{ s.service_name }}</span
                                            >
                                        </span>
                                    </div>
                                    <code
                                        v-if="s.command"
                                        class="mt-2.5 block overflow-x-auto rounded-lg bg-(--ds-hover) px-3 py-2 font-ui-mono text-[12.5px]/5 whitespace-pre"
                                        >$ {{ s.command }}</code
                                    >
                                    <p
                                        v-if="s.why"
                                        class="mt-2 pl-9 text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{ s.why }}
                                    </p>
                                    <p
                                        v-if="
                                            s.result?.state === 'failed' &&
                                            s.result.message
                                        "
                                        class="mt-2 pl-9 text-[13px]/5 text-(--ds-red)"
                                    >
                                        {{ s.result.message }}
                                    </p>
                                </li>
                            </ol>
                        </template>

                        <template
                            v-if="
                                selected.logs.length || selected.errors.length
                            "
                        >
                            <button
                                type="button"
                                class="mt-8 flex cursor-pointer items-center gap-1 text-[13px]/5 font-medium text-(--ds-text-secondary)"
                                :class="focusRing"
                                :aria-expanded="showEvidence"
                                @click="showEvidence = !showEvidence"
                            >
                                {{ $t('What it read')
                                }}<ChevronDown
                                    class="size-3.5 transition-transform"
                                    :class="showEvidence ? '' : '-rotate-90'"
                                />
                            </button>
                            <pre
                                v-if="showEvidence"
                                class="mt-3 max-h-80 overflow-auto rounded-xl bg-(--ds-hover) p-3 font-ui-mono text-[12px]/5 text-(--ds-text-secondary)"
                                >{{
                                    [
                                        ...selected.logs,
                                        ...(selected.errors.length &&
                                        selected.logs.length
                                            ? [
                                                  '',
                                                  '— elsewhere on the machine —',
                                              ]
                                            : []),
                                        ...selected.errors,
                                    ].join('\n')
                                }}</pre>
                        </template>

                        <p
                            v-if="selected.decidedBy && selected.decidedAt"
                            class="mt-8 text-[12.5px]/5 text-(--ds-text-faint)"
                        >
                            {{
                                (selected.state === 'declined'
                                    ? $t('Turned down by :name', {
                                          name: selected.decidedBy,
                                      })
                                    : $t('Approved by :name', {
                                          name: selected.decidedBy,
                                      })) +
                                ' · ' +
                                longDate(selected.decidedAt)
                            }}
                        </p>
                    </div>

                    <div
                        v-if="
                            selected.state === 'needs_approval' && can.approve
                        "
                        class="flex flex-wrap items-center gap-3 border-t border-(--ds-border) bg-(--ds-surface,transparent) px-6 py-3.5 backdrop-blur sm:px-12"
                    >
                        <button
                            type="button"
                            :class="
                                appButtonClasses('primary', 'md', focusRing)
                            "
                            :disabled="deciding || !twoFactor"
                            @click="approve"
                        >
                            <Check class="size-4" />{{
                                selected.steps.length === 1
                                    ? $t('Approve and run it')
                                    : $t('Approve and run :count steps', {
                                          count: String(selected.steps.length),
                                      })
                            }}
                        </button>
                        <button
                            type="button"
                            :class="
                                appButtonClasses('secondary', 'md', focusRing)
                            "
                            :disabled="deciding"
                            @click="decline"
                        >
                            {{ $t('Turn down') }}
                        </button>
                        <span
                            class="text-[12.5px]/5"
                            :class="
                                decideError || !twoFactor
                                    ? 'text-(--ds-red)'
                                    : 'text-(--ds-text-faint)'
                            "
                        >
                            {{
                                decideError ??
                                (twoFactor
                                    ? $t(
                                          'Runs as you, one step at a time. It stops if a step fails.',
                                      )
                                    : $t(
                                          'Turn on two-factor authentication to approve fixes.',
                                      ))
                            }}
                        </span>
                    </div>
                </template>
            </section>
        </div>

        <Dialog v-model:open="asking">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
            >
                <form class="flex flex-col gap-4 p-5" @submit.prevent="submit">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Look into something')
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'The agent reads the last day of logs and the machine’s state, then explains what it finds. It can propose:',
                                )
                            }}
                            <span class="mt-1 block text-(--ds-text-faint)">{{
                                allowed.join(' · ')
                            }}</span>
                        </DialogDescription>
                    </div>
                    <label
                        class="flex flex-col gap-1.5 text-[12px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('Machine') }}
                        <select
                            v-model="form.machine"
                            :class="[field, 'ds-select cursor-pointer']"
                            required
                            @change="form.service = ''"
                        >
                            <option value="" disabled>
                                {{ $t('Choose a machine…') }}
                            </option>
                            <option
                                v-for="m in machines"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.name }}
                            </option>
                        </select>
                        <span
                            v-if="form.errors.machine"
                            class="text-(--ds-red)"
                            >{{ form.errors.machine }}</span
                        >
                    </label>
                    <label
                        class="flex flex-col gap-1.5 text-[12px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('Service (optional)') }}
                        <select
                            v-model="form.service"
                            :class="[field, 'ds-select cursor-pointer']"
                            :disabled="!chosenMachine"
                        >
                            <option value="">
                                {{ $t('The whole machine') }}
                            </option>
                            <option
                                v-for="s in chosenMachine?.services ?? []"
                                :key="s.key"
                                :value="s.key"
                            >
                                {{ s.name
                                }}{{
                                    s.status && s.status !== 'running'
                                        ? ` (${s.status})`
                                        : ''
                                }}
                            </option>
                        </select>
                        <span
                            v-if="form.errors.service"
                            class="text-(--ds-red)"
                            >{{ form.errors.service }}</span
                        >
                    </label>
                    <label
                        class="flex flex-col gap-1.5 text-[12px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('What’s wrong? (optional)') }}
                        <textarea
                            v-model="form.note"
                            class="min-h-20 w-full rounded-md border border-(--ds-border) bg-transparent px-3 py-2 text-[13px]/5"
                            :placeholder="
                                $t('The site has been slow since this morning.')
                            "
                            maxlength="1000"
                        />
                    </label>
                    <p
                        v-if="usedUp || limitError"
                        role="alert"
                        class="rounded-lg border border-(--ds-amber)/30 bg-(--ds-amber)/8 px-3 py-2.5 text-[13px]/5 text-(--ds-text)"
                    >
                        {{ limitError ?? usedUp }}
                        <a
                            v-if="outOfAllowance && !allowance.ownKey"
                            href="/settings/mcp"
                            class="mt-1 block text-(--ds-text-muted) underline underline-offset-2"
                            >{{
                                $t(
                                    'Your own AI key gives you :count an hour.',
                                    { count: String(allowance.ownKeyLimit) },
                                )
                            }}</a
                        >
                    </p>
                    <div class="flex items-center justify-end gap-2 pt-1">
                        <span
                            class="mr-auto flex items-center gap-2 text-[12px]/4 text-(--ds-text-faint)"
                            :title="
                                allowance.ownKey
                                    ? $t('On your own AI key')
                                    : $t('On ServerOS’s AI')
                            "
                        >
                            <span
                                class="h-1 w-14 overflow-hidden rounded-full bg-(--ds-border)"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full"
                                    :class="
                                        outOfAllowance
                                            ? 'bg-(--ds-amber)'
                                            : 'bg-(--ds-text-muted)'
                                    "
                                    :style="{
                                        width: `${Math.min(100, (allowance.used / allowance.limit) * 100)}%`,
                                    }"
                                />
                            </span>
                            {{
                                $t(':used of :limit this hour', {
                                    used: String(allowance.used),
                                    limit: String(allowance.limit),
                                })
                            }}
                        </span>
                        <button
                            type="button"
                            :class="
                                appButtonClasses('secondary', 'md', focusRing)
                            "
                            @click="asking = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="
                                appButtonClasses('primary', 'md', focusRing)
                            "
                            :disabled="
                                form.processing ||
                                !form.machine ||
                                outOfAllowance
                            "
                        >
                            {{ $t('Look into it') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>

<style scoped>
/* The state ring: hollow while it needs you or is going on, spinning when busy. */
.fix-state-icon {
    display: inline-block;
    width: 18px;
    height: 18px;
    border-radius: 9999px;
    border: 2px solid currentColor;
}
.fix-state-icon.is-large {
    width: 26px;
    height: 26px;
    border-width: 3px;
}
.fix-state-icon.is-busy {
    border-right-color: transparent;
    animation: fix-spin 900ms linear infinite;
}
@keyframes fix-spin {
    to {
        transform: rotate(360deg);
    }
}
.fix-step-busy {
    animation: fix-breathe 1.4s ease-in-out infinite;
}
@keyframes fix-breathe {
    50% {
        opacity: 0.55;
    }
}

/* The wavy rule either side of Past. */
.fix-wave {
    height: 8px;
    background: repeating-radial-gradient(
            circle at 6px -2px,
            transparent 0 5px,
            var(--ds-border) 5px 6.4px,
            transparent 6.4px 12px
        )
        0 0 / 12px 8px repeat-x;
    -webkit-mask: linear-gradient(
        90deg,
        transparent,
        #000 15%,
        #000 85%,
        transparent
    );
    mask: linear-gradient(90deg, transparent, #000 15%, #000 85%, transparent);
}

/* The panel's light, from the bottom, coloured by how it's going. */
.fix-glow {
    background: radial-gradient(
        120% 60% at 80% 115%,
        var(--fix-glow),
        transparent 70%
    );
    transition: background 600ms ease;
}

.fix-shimmer {
    background: linear-gradient(
            90deg,
            var(--ds-text-faint) 30%,
            var(--ds-text) 50%,
            var(--ds-text-faint) 70%
        )
        0 0 / 300% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: fix-shimmer 2.2s linear infinite;
}
@keyframes fix-shimmer {
    from {
        background-position: 100% 0;
    }
    to {
        background-position: -200% 0;
    }
}

.fix-prose :deep(p + p) {
    margin-top: 0.75rem;
}
.fix-prose :deep(code) {
    font-family: var(--font-ui-mono, ui-monospace, monospace);
    font-size: 0.85em;
    padding: 0.1em 0.35em;
    border-radius: 5px;
    background: var(--ds-hover);
    color: var(--ds-text);
}
.fix-prose :deep(ul) {
    margin-top: 0.5rem;
    padding-left: 1.2rem;
    list-style: disc;
}

@media (prefers-reduced-motion: reduce) {
    .fix-state-icon.is-busy,
    .fix-shimmer,
    .fix-step-busy {
        animation: none;
    }
}
</style>
