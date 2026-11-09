<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Check, Copy, LockKeyhole } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import { shake } from '@/lib/shake';
import { toast } from '@/lib/toast';
import PolicySends from '@/components/machines/PolicySends.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { updateTheme } from '@/composables/useAppearance';
import { track } from '@/lib/analytics';
import { appButtonClasses } from '@/lib/appButton';
import { bloom, grain } from '@/lib/brand';
import { docsUrl } from '@/lib/docs';
import type { PolicySettings } from '@/lib/machinePolicies';
import {
    SolarBriefcase,
    SolarCode,
    SolarEye,
    SolarServer,
} from '@/lib/solarIcons';
import { trialCardsCopy } from '@/lib/trialCards';
import { onWorkspace } from '@/lib/workspaceRoute';
import welcomeRoutes from '@/routes/workspace/welcome';
import type { Workspace } from '@/types';

/*
 * The welcome flow a new account goes through before its dashboard, on
 * the sign-up pages' design: what they run; what their servers collect
 * and send (the default machine policy, preset from that); a plan
 * recommended from it (the trial runs on it); then connecting the first
 * server. Each step saves as it goes, and every one can be skipped.
 */
type Segment = 'own' | 'agency' | 'hosting' | 'exploring';
type Servers = '1' | '2-5' | '6-20' | '20+';
type PlanKey = 'starter' | 'pro' | 'business';
type Interval = 'monthly' | 'annual';
type Step = 'about' | 'control' | 'plan' | 'connect';

const props = defineProps<{
    workspace: Workspace;
    answers: {
        segment: Segment | null;
        servers: Servers | null;
        team: boolean | null;
        plan: PlanKey | null;
        interval: Interval;
        /** What they chose their servers do, once they have. */
        control: PolicySettings | null;
    };
    /** Where step two starts, for each answer to "What are you running?". */
    presets: Record<Segment, PolicySettings>;
    recommended: PlanKey;
    plans: {
        key: PlanKey;
        label: string;
        monthly: number;
        annual: number;
        machines: number | null;
    }[];
    trialDays: number;
    /**
     * The card-upfront test's card half: step three asks for a card,
     * charged when the trial ends, and the flow can't be skipped until then.
     */
    cardRequired: boolean;
    /** The free days left, which the card's checkout gives before charging. */
    cardTrialDays: number;
    /** Where the card is confirmed when checkout sends them back. */
    cardVerifyUrl: string | null;
    /** What the plan prices are in: GBP, USD or EUR. */
    currency: string;
    canConnect: boolean;
    apiUrl: string;
    /** False on a self-hosted install, which has no plans to choose. */
    choosePlan?: boolean;
}>();

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const choosePlan = props.choosePlan ?? true;
const steps: Step[] = choosePlan
    ? ['about', 'control', 'plan', 'connect']
    : ['about', 'control', 'connect'];
const step = ref<Step>(
    props.answers.segment === null
        ? 'about'
        : props.answers.control === null
          ? 'control'
          : choosePlan && (props.answers.plan === null || props.cardRequired)
            ? 'plan'
            : 'connect',
);
const stepNumber = computed(() => steps.indexOf(step.value) + 1);

/* Step one: about them */

const segments: {
    key: Segment;
    icon: Component;
    title: string;
    body: string;
}[] = [
    {
        key: 'own',
        icon: SolarCode,
        title: trans('My own apps and sites'),
        body: trans('A product, side projects or a company’s servers'),
    },
    {
        key: 'agency',
        icon: SolarBriefcase,
        title: trans('Client sites'),
        body: trans('An agency or freelancer looking after clients'),
    },
    {
        key: 'hosting',
        icon: SolarServer,
        title: trans('Hosting for customers'),
        body: trans('VPS, game servers or web hosting you sell'),
    },
    {
        key: 'exploring',
        icon: SolarEye,
        title: trans('Just looking around'),
        body: trans('Trying it out on a test server'),
    },
];
const serverBands: Servers[] = ['1', '2-5', '6-20', '20+'];

const segment = ref<Segment | null>(props.answers.segment);
const servers = ref<Servers | null>(props.answers.servers);
const team = ref<boolean | null>(props.answers.team);
const aboutReady = computed(
    () =>
        segment.value !== null && servers.value !== null && team.value !== null,
);

const busy = ref(false);

/* The three questions, so Continue can shake whichever is unanswered. */
const segmentGroup = ref<HTMLElement | null>(null);
const serversGroup = ref<HTMLElement | null>(null);
const teamGroup = ref<HTMLElement | null>(null);

/** Pressed Continue with questions left: those go red until answered. */
const aboutTried = ref(false);

/** Continue: on to step two, or the questions left in red, the first shaken. */
function continueAbout(): void {
    if (busy.value) return;
    if (!aboutReady.value) {
        aboutTried.value = true;
        shake(
            segment.value === null
                ? segmentGroup.value
                : servers.value === null
                  ? serversGroup.value
                  : teamGroup.value,
        );
        return;
    }
    saveAbout();
}

function saveAbout(): void {
    if (!aboutReady.value || busy.value) return;
    busy.value = true;
    router.post(
        onWorkspace(welcomeRoutes.about(props.workspace.slug)).url,
        { segment: segment.value, servers: servers.value, team: team.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                track('welcome_step_completed', {
                    step: 'about',
                    segment: segment.value,
                    servers: servers.value,
                    team: team.value,
                });
                // New answers, new recommendation: pick it, whatever was picked before.
                plan.value = props.recommended;
                // Until they've chosen, the switches follow what they run.
                if (!controlChosen.value && segment.value) {
                    control.value = { ...props.presets[segment.value] };
                }
                step.value = 'control';
            },
            onFinish: () => (busy.value = false),
        },
    );
}

/* Step two: what their servers collect and send */

/**
 * The four that matter on day one. The rest (IP masking in logs, status
 * pages, updates) keep the preset from step one, and are in Settings ›
 * Machine policies with everything else.
 */
const quickSwitches: {
    key: 'discovery' | 'logs' | 'security_watch' | 'error_reports';
    title: string;
    body: string;
}[] = [
    {
        key: 'discovery',
        title: trans('Find what’s running'),
        body: trans('Apps, sites and databases already on your servers.'),
    },
    {
        key: 'logs',
        title: trans('Collect logs'),
        body: trans('Your services’ logs, searchable in one place.'),
    },
    {
        key: 'security_watch',
        title: trans('Security checks'),
        body: trans('SSH settings, failed sign-ins and security updates.'),
    },
    {
        key: 'error_reports',
        title: trans('Error reports'),
        body: trans('Daemon crashes, sent to us with secrets removed.'),
    },
];

function setControl(
    key: (typeof quickSwitches)[number]['key'],
    value: boolean,
): void {
    control.value = { ...control.value, [key]: value };
}

const controlChosen = ref(props.answers.control !== null);
const control = ref<PolicySettings>({
    ...(props.answers.control ??
        props.presets[props.answers.segment ?? 'own'] ??
        props.presets.own),
});

function saveControl(): void {
    if (busy.value) return;
    busy.value = true;
    router.post(
        onWorkspace(welcomeRoutes.control(props.workspace.slug)).url,
        { ...control.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                track('welcome_step_completed', {
                    step: 'control',
                    segment: segment.value,
                    ...control.value,
                });
                controlChosen.value = true;
                step.value = choosePlan ? 'plan' : 'connect';
            },
            onFinish: () => (busy.value = false),
        },
    );
}

/* Step three: the plan the trial runs on */

const plan = ref<PlanKey>(props.answers.plan ?? props.recommended);
const interval = ref<Interval>(props.answers.interval);

function price(entry: (typeof props.plans)[number]): string {
    const minor = interval.value === 'annual' ? entry.annual : entry.monthly;

    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: props.currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

function machines(entry: (typeof props.plans)[number]): string {
    if (entry.machines === null) return trans('Unlimited machines');
    if (entry.machines === 1) return trans('1 machine');

    return trans('Up to :count machines', { count: String(entry.machines) });
}

const chosenLabel = computed(
    () => props.plans.find((entry) => entry.key === plan.value)?.label ?? '',
);

function savePlan(): void {
    if (busy.value) return;
    busy.value = true;
    router.post(
        onWorkspace(welcomeRoutes.plan(props.workspace.slug)).url,
        { plan: plan.value, interval: interval.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                track('welcome_step_completed', {
                    step: 'plan',
                    plan: plan.value,
                    recommended: props.recommended,
                    interval: interval.value,
                });
                step.value = 'connect';
            },
            onFinish: () => (busy.value = false),
        },
    );
}

/* Step three, for the card half of the card-upfront test: the card */

const cardError = ref<string | null>(null);
const cardMessage = ref<string | null>(null);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function postJson(
    url: string,
    body: Record<string, string>,
): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(
            typeof data.message === 'string'
                ? data.message
                : trans('Something went wrong. Please try again.'),
        );
    }

    return data;
}

/** Saves the plan, then off to checkout to add the card. */
async function addCard(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    cardError.value = null;
    track('welcome_card_started', {
        plan: plan.value,
        interval: interval.value,
    });
    try {
        const data = await postJson(
            onWorkspace(welcomeRoutes.card(props.workspace.slug)).url,
            { plan: plan.value, interval: interval.value },
        );
        if (typeof data.checkoutUrl !== 'string') {
            throw new Error(trans('Something went wrong. Please try again.'));
        }
        window.location.href = data.checkoutUrl;
    } catch (error) {
        cardError.value =
            error instanceof Error
                ? error.message
                : trans('Something went wrong. Please try again.');
        busy.value = false;
    }
}

/** Back from checkout: confirm the card, then on to step four. */
async function checkCardReturn(): Promise<void> {
    const params = new URLSearchParams(window.location.search);
    if (!props.cardRequired || !props.cardVerifyUrl || !params.has('checkout'))
        return;
    const subscriptionId = params.get('subscription_id');
    window.history.replaceState({}, '', window.location.pathname);
    if (!subscriptionId) return;

    cardMessage.value = trans(trialCardsCopy.checking);
    try {
        const data = await postJson(props.cardVerifyUrl, {
            subscription_id: subscriptionId,
        });
        if (data.verified === true) {
            track('welcome_step_completed', { step: 'plan', card: true });
            router.reload({
                onFinish: () => {
                    cardMessage.value = null;
                    step.value = 'connect';
                },
            });
        } else {
            cardMessage.value = trans(trialCardsCopy.pending);
        }
    } catch (error) {
        cardMessage.value = null;
        cardError.value =
            error instanceof Error
                ? error.message
                : trans('Something went wrong. Please try again.');
    }
}

onMounted(checkCardReturn);

/* Step four: connect the first server */

type ConnectState =
    | 'waiting'
    | 'expired'
    | 'enrolled'
    | 'connected'
    | 'discovered';

// Hosting companies and big fleets add servers from their own scripts.
const method = ref<'command' | 'api'>(
    props.answers.segment === 'hosting' || props.answers.servers === '20+'
        ? 'api'
        : 'command',
);
const command = ref('');
const commandError = ref('');
const enrolmentId = ref<number | null>(null);
const expiresAt = ref<number | null>(null);
const connectState = ref<ConnectState>('waiting');
const machineName = ref<string | null>(null);
const serviceCount = ref(0);
/** Off in their policy: connected is done, no scan is coming. */
const discovery = ref(true);
const now = ref(Date.now());
let poller: ReturnType<typeof setInterval> | null = null;
let clock: ReturnType<typeof setInterval> | null = null;

function stopPolling(): void {
    if (poller) clearInterval(poller);
    if (clock) clearInterval(clock);
    poller = null;
    clock = null;
}
onBeforeUnmount(stopPolling);

const connected = computed(() =>
    ['enrolled', 'connected', 'discovered'].includes(connectState.value),
);

const timeLeft = computed(() => {
    if (expiresAt.value === null) return '';
    const seconds = Math.max(
        0,
        Math.round((expiresAt.value - now.value) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

async function poll(): Promise<void> {
    if (enrolmentId.value === null) return;
    try {
        const response = await fetch(
            `/machines/connect/${enrolmentId.value}/status`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            },
        );
        if (!response.ok) return;
        const result = await response.json();
        const before = connectState.value;
        connectState.value = result.state;
        machineName.value = result.machineName;
        serviceCount.value = result.services;
        discovery.value = result.discovery ?? true;
        if (result.state !== before && result.state !== 'waiting') {
            track('welcome_connect_state', { state: result.state });
        }
        if (result.state === 'expired' || result.state === 'discovered') {
            stopPolling();
        }
    } catch {
        // A blip in the network shouldn't stop the polling.
    }
}

async function createCommand(): Promise<void> {
    if (!props.canConnect) return;
    commandError.value = '';
    connectState.value = 'waiting';
    stopPolling();
    try {
        const response = await fetch('/machines/connect', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({ replace: true }),
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(
                result.error ?? trans('Could not create an install command.'),
            );
        }
        command.value = result.command;
        enrolmentId.value = result.enrolmentId;
        expiresAt.value = Date.parse(result.expiresAt);
        poller = setInterval(poll, 4000);
        clock = setInterval(() => (now.value = Date.now()), 1000);
    } catch (cause) {
        commandError.value =
            cause instanceof Error
                ? cause.message
                : trans('Could not create an install command.');
    }
}

watch(
    step,
    (value) => {
        if (value === 'connect' && command.value === '') void createCommand();
    },
    { immediate: true },
);

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied'));
        track('welcome_command_copied', { method: method.value });
    } catch {
        toast.error(trans('Could not copy. Select it and copy it by hand.'));
    }
}

/* The API: a key made here, shown once and dropped into the example */

const apiKey = ref('');
const apiKeyBusy = ref(false);
const apiKeyError = ref('');

async function createApiKey(): Promise<void> {
    if (apiKeyBusy.value) return;
    apiKeyBusy.value = true;
    apiKeyError.value = '';
    try {
        const response = await fetch('/settings/api/keys', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            // Changes, not just reading: adding a machine makes a token.
            body: JSON.stringify({ name: 'Provisioning', access: 'write' }),
        });
        const result = await response.json();
        if (!response.ok) {
            const first = Object.values(result.errors ?? {})[0];
            throw new Error(
                (Array.isArray(first) ? first[0] : null) ??
                    trans('Could not make a key.'),
            );
        }
        apiKey.value = result.secret;
        track('welcome_api_key_created');
    } catch (cause) {
        apiKeyError.value =
            cause instanceof Error
                ? cause.message
                : trans('Could not make a key.');
    } finally {
        apiKeyBusy.value = false;
    }
}

const apiSnippet = computed(
    () =>
        `curl -X POST ${props.apiUrl} \\\n  -H "Authorization: Bearer ${apiKey.value || 'sos_your_key'}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"expiresInMinutes": 60}'`,
);

/** Skipping from the header asks first: it's easy to hit by accident. */
const confirmingSkip = ref(false);

function finish(skipped: boolean): void {
    if (busy.value) return;
    busy.value = true;
    stopPolling();
    track(skipped ? 'welcome_skipped' : 'welcome_finished', {
        step: step.value,
        connected: connected.value,
    });
    router.post(
        onWorkspace(welcomeRoutes.finish(props.workspace.slug)).url,
        { skipped_at: skipped ? step.value : null },
        { onFinish: () => (busy.value = false) },
    );
}

track('welcome_viewed', { step: step.value });

// Always light, like the sign-up pages and the marketing site; the
// panel's own theme comes back on the dashboard.
onMounted(() => updateTheme('light'));
onBeforeUnmount(() =>
    updateTheme(
        (localStorage.getItem('appearance') as Parameters<
            typeof updateTheme
        >[0]) ?? 'system',
    ),
);
</script>

<template>
    <Head :title="$t('Welcome')" />
    <div
        class="marketing-page ds-root relative isolate flex min-h-svh flex-col overflow-hidden bg-(--ds-frame) font-ui text-(--ds-text) antialiased"
    >
        <!-- The sign-up pages' frame (AuthPanelLayout): the landing hero's
             cobalt bloom fading into the page, our mark over the card. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[78svh] min-h-[560px] mask-[linear-gradient(black_0%,black_55%,rgb(0_0_0/0.55)_78%,transparent)]"
            :style="{ backgroundImage: bloom }"
        >
            <div
                class="absolute inset-0 opacity-[0.05]"
                :style="{ backgroundImage: grain }"
            />
        </div>

        <main class="flex grow items-center justify-center px-4 py-12">
            <div class="cs-rise w-full max-w-[460px]">
                <span
                    class="mx-auto mb-7 flex w-fit items-center gap-2.5 text-white"
                >
                    <img
                        src="/images/landing/serveros-logo.svg"
                        alt=""
                        class="size-[22px] object-contain"
                    />
                    <span
                        class="text-[19px]/6 font-semibold tracking-[-0.025em]"
                        >ServerOS</span
                    >
                </span>
                <div
                    class="ds-modal workspace-panel overflow-hidden rounded-[12px]! p-8 shadow-[0_0_0_1px_rgb(0_0_0/0.06),0_2px_4px_rgb(0_0_0/0.04),0_24px_48px_-16px_rgb(2_16_56/0.35)]!"
                >
                    <div class="mb-6 flex items-center gap-1.5">
                        <span
                            v-for="(name, index) in steps"
                            :key="name"
                            class="h-[3px] flex-1 rounded-full transition-colors duration-300"
                            :class="
                                index < stepNumber
                                    ? 'bg-(--ds-blue)'
                                    : 'bg-(--ds-border-strong)'
                            "
                        />
                        <span
                            class="pl-1.5 text-[12.5px]/4 whitespace-nowrap text-(--ds-text-muted)"
                        >
                            {{
                                $t('Step :step of :total', {
                                    step: String(stepNumber),
                                    total: String(steps.length),
                                })
                            }}
                        </span>
                    </div>

                    <div v-if="step === 'about'" class="flex flex-col gap-6">
                        <div class="flex flex-col gap-1.5">
                            <h1
                                class="text-[22px]/7 font-medium tracking-[-0.02em]"
                            >
                                {{ $t('What are you running?') }}
                            </h1>
                            <p class="text-[14px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'We’ll set ServerOS up to match. You can change this later.',
                                    )
                                }}
                            </p>
                        </div>

                        <!-- The panel's segmented tabs, stacked: the pick sits raised. -->
                        <div
                            ref="segmentGroup"
                            class="ds-segments w-full! flex-col! gap-0.5! p-0.5!"
                            :data-invalid="
                                aboutTried && segment === null
                                    ? 'true'
                                    : undefined
                            "
                            role="group"
                            :aria-label="$t('What are you running?')"
                        >
                            <button
                                v-for="option in segments"
                                :key="option.key"
                                type="button"
                                class="cursor-pointer gap-3 py-2! pr-3.5! pl-2! text-left"
                                :class="focusRing"
                                :aria-pressed="segment === option.key"
                                @click="segment = option.key"
                            >
                                <span
                                    class="ds-icon-chip size-9! rounded-[10px]!"
                                >
                                    <component
                                        :is="option.icon"
                                        class="size-5"
                                        :class="
                                            segment === option.key
                                                ? 'text-(--ds-blue)'
                                                : 'text-(--ds-text-muted)'
                                        "
                                    />
                                </span>
                                <span class="flex grow flex-col">
                                    <span class="text-[14px]/5">
                                        {{ option.title }}
                                    </span>
                                    <span
                                        class="text-[13px]/4.5 font-normal tracking-normal text-(--ds-text-muted)"
                                    >
                                        {{ option.body }}
                                    </span>
                                </span>
                            </button>
                        </div>
                        <p
                            v-if="aboutTried && segment === null"
                            role="alert"
                            class="so-field-error -mt-4"
                        >
                            {{ $t('Choose one.') }}
                        </p>

                        <div class="flex gap-3">
                            <div class="flex flex-[1.4] flex-col gap-1.5">
                                <span
                                    class="text-[13px]/4.5 font-medium text-(--ds-text-secondary)"
                                >
                                    {{ $t('Servers') }}
                                </span>
                                <div
                                    ref="serversGroup"
                                    class="ds-segments w-full!"
                                    :data-invalid="
                                        aboutTried && servers === null
                                            ? 'true'
                                            : undefined
                                    "
                                    role="group"
                                    :aria-label="$t('Servers')"
                                >
                                    <button
                                        v-for="band in serverBands"
                                        :key="band"
                                        type="button"
                                        class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                        :class="focusRing"
                                        :aria-pressed="servers === band"
                                        @click="servers = band"
                                    >
                                        {{ band }}
                                    </button>
                                </div>
                                <p
                                    v-if="aboutTried && servers === null"
                                    role="alert"
                                    class="so-field-error"
                                >
                                    {{ $t('Choose one.') }}
                                </p>
                            </div>
                            <div class="flex flex-1 flex-col gap-1.5">
                                <span
                                    class="text-[13px]/4.5 font-medium text-(--ds-text-secondary)"
                                >
                                    {{ $t('Who’ll use it') }}
                                </span>
                                <div
                                    ref="teamGroup"
                                    class="ds-segments w-full!"
                                    :data-invalid="
                                        aboutTried && team === null
                                            ? 'true'
                                            : undefined
                                    "
                                    role="group"
                                    :aria-label="$t('Who’ll use it')"
                                >
                                    <button
                                        type="button"
                                        class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                        :class="focusRing"
                                        :aria-pressed="team === false"
                                        @click="team = false"
                                    >
                                        {{ $t('Just me') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                        :class="focusRing"
                                        :aria-pressed="team === true"
                                        @click="team = true"
                                    >
                                        {{ $t('A team') }}
                                    </button>
                                </div>
                                <p
                                    v-if="aboutTried && team === null"
                                    role="alert"
                                    class="so-field-error"
                                >
                                    {{ $t('Choose one.') }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            :class="
                                appButtonClasses(
                                    'primary',
                                    'md',
                                    // Looks disabled until every question has an
                                    // answer, but still takes the press, to shake.
                                    'h-9 w-full justify-center aria-disabled:pointer-events-auto! aria-disabled:cursor-default! aria-disabled:active:scale-100!',
                                )
                            "
                            :aria-disabled="!aboutReady || undefined"
                            :disabled="busy"
                            @click="continueAbout"
                        >
                            {{ $t('Continue') }}
                        </button>
                    </div>

                    <div
                        v-else-if="step === 'control'"
                        class="flex flex-col gap-6"
                    >
                        <div class="flex flex-col gap-1.5">
                            <h1
                                class="text-[22px]/7 font-medium tracking-[-0.02em]"
                            >
                                {{ $t('What should ServerOS do?') }}
                            </h1>
                            <p class="text-[14px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Every server you connect follows these. You can change them, and more, in Settings any time.',
                                    )
                                }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div
                                class="divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-panel)"
                            >
                                <div
                                    v-for="item in quickSwitches"
                                    :key="item.key"
                                    class="flex items-center gap-4 px-3.5 py-3"
                                >
                                    <div class="flex grow flex-col">
                                        <span
                                            class="text-[14px]/5 font-medium text-(--ds-text)"
                                        >
                                            {{ item.title }}
                                        </span>
                                        <span
                                            class="text-[13px]/4.5 text-(--ds-text-muted)"
                                        >
                                            {{ item.body }}
                                        </span>
                                    </div>
                                    <ToggleSwitch
                                        :model-value="control[item.key]"
                                        :label="item.title"
                                        @update:model-value="
                                            setControl(item.key, $event)
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                class="flex items-center gap-2 text-[13px]/4.5 text-(--ds-text-muted)"
                            >
                                <LockKeyhole
                                    class="size-3.5 shrink-0"
                                    :stroke-width="2"
                                />
                                {{
                                    $t(
                                        'Health checks are always on. Files and database contents never leave your server.',
                                    )
                                }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                :class="
                                    appButtonClasses('secondary', 'md', 'h-9')
                                "
                                @click="step = 'about'"
                            >
                                {{ $t('Back') }}
                            </button>
                            <button
                                type="button"
                                :class="
                                    appButtonClasses(
                                        'primary',
                                        'md',
                                        'h-9 grow justify-center',
                                    )
                                "
                                :disabled="busy"
                                @click="saveControl"
                            >
                                {{ $t('Continue') }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-else-if="step === 'plan'"
                        class="flex flex-col gap-6"
                    >
                        <div class="flex flex-col gap-1.5">
                            <h1
                                class="text-[22px]/7 font-medium tracking-[-0.02em]"
                            >
                                {{ $t('Your plan') }}
                            </h1>
                            <p class="text-[14px]/5 text-(--ds-text-muted)">
                                <template v-if="cardRequired">
                                    {{
                                        $t(
                                            'We’d start you on :plan. Add a card to try it free for :days days. We’ll email you before it’s charged, and you can cancel any time before then.',
                                            {
                                                plan:
                                                    plans.find(
                                                        (entry) =>
                                                            entry.key ===
                                                            recommended,
                                                    )?.label ?? '',
                                                days: String(cardTrialDays),
                                            },
                                        )
                                    }}
                                </template>
                                <template v-else>
                                    {{
                                        $t(
                                            'We’d start you on :plan. Try it free for :days days, no card needed.',
                                            {
                                                plan:
                                                    plans.find(
                                                        (entry) =>
                                                            entry.key ===
                                                            recommended,
                                                    )?.label ?? '',
                                                days: String(trialDays),
                                            },
                                        )
                                    }}
                                </template>
                            </p>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div
                                class="ds-segments w-full!"
                                role="group"
                                :aria-label="$t('Billing period')"
                            >
                                <button
                                    type="button"
                                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                    :class="focusRing"
                                    :aria-pressed="interval === 'monthly'"
                                    @click="interval = 'monthly'"
                                >
                                    {{ $t('Monthly') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 cursor-pointer justify-center gap-1.5 px-0! text-[13px]!"
                                    :class="focusRing"
                                    :aria-pressed="interval === 'annual'"
                                    @click="interval = 'annual'"
                                >
                                    {{ $t('Yearly') }}
                                    <span class="text-(--ds-green)">
                                        {{ $t('2 months free') }}
                                    </span>
                                </button>
                            </div>

                            <div
                                class="ds-segments w-full! flex-col! gap-0.5! p-0.5!"
                                role="group"
                                :aria-label="$t('Your plan')"
                            >
                                <button
                                    v-for="entry in plans"
                                    :key="entry.key"
                                    type="button"
                                    class="cursor-pointer gap-3 px-3.5! py-2.5! text-left"
                                    :class="focusRing"
                                    :aria-pressed="plan === entry.key"
                                    @click="plan = entry.key"
                                >
                                    <span class="flex grow flex-col">
                                        <span class="flex items-center gap-2">
                                            <span class="text-[14px]/5">
                                                {{ entry.label }}
                                            </span>
                                            <span
                                                v-if="entry.key === recommended"
                                                class="rounded-full bg-(--ds-blue-bg) px-1.75 py-px text-[11.5px]/4 font-medium tracking-normal text-(--ds-blue) ring-1 ring-(--ds-blue-border)"
                                            >
                                                {{ $t('Recommended') }}
                                            </span>
                                        </span>
                                        <span
                                            class="text-[13px]/4.5 font-normal tracking-normal text-(--ds-text-muted)"
                                        >
                                            {{ machines(entry) }}
                                        </span>
                                    </span>
                                    <span
                                        class="flex shrink-0 items-baseline gap-0.5 whitespace-nowrap"
                                    >
                                        <span
                                            class="text-[16px]/5 font-semibold text-(--ds-text) tabular-nums"
                                        >
                                            {{ price(entry) }}
                                        </span>
                                        <span
                                            class="text-[12.5px]/4 font-normal text-(--ds-text-faint)"
                                        >
                                            {{
                                                interval === 'annual'
                                                    ? $t('/yr')
                                                    : $t('/mo')
                                            }}
                                        </span>
                                    </span>
                                </button>
                            </div>

                            <p
                                v-if="!cardRequired"
                                class="flex flex-wrap items-center gap-1.5 rounded-lg border border-dashed border-(--ds-border-strong) bg-(--ds-subtle) px-3 py-2 text-[13px]/4.5 text-(--ds-text-secondary)"
                            >
                                {{ $t('Use') }}
                                <code
                                    class="rounded-[5px] border border-(--ds-border) bg-(--ds-panel) px-1.5 font-ui-mono text-[12px]/4 font-medium text-(--ds-text)"
                                    >LAUNCH</code
                                >
                                {{
                                    $t(
                                        'for 10% off your first plan when you pay.',
                                    )
                                }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                :class="
                                    appButtonClasses('secondary', 'md', 'h-9')
                                "
                                @click="step = 'control'"
                            >
                                {{ $t('Back') }}
                            </button>
                            <button
                                type="button"
                                :class="
                                    appButtonClasses(
                                        'primary',
                                        'md',
                                        'h-9 grow justify-center',
                                    )
                                "
                                :disabled="busy"
                                @click="cardRequired ? addCard() : savePlan()"
                            >
                                {{
                                    cardRequired
                                        ? $t(
                                              'Add a card and start my free trial',
                                          )
                                        : $t('Start my free :plan trial', {
                                              plan: chosenLabel,
                                          })
                                }}
                            </button>
                        </div>
                        <p
                            v-if="cardMessage"
                            role="status"
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ cardMessage }}
                        </p>
                        <p
                            v-if="cardError"
                            role="alert"
                            class="text-[13px]/5 text-(--ds-red)"
                        >
                            {{ cardError }}
                        </p>
                    </div>

                    <div v-else class="flex flex-col gap-6">
                        <div class="flex flex-col gap-1.5">
                            <h1
                                class="text-[22px]/7 font-medium tracking-[-0.02em]"
                            >
                                {{ $t('Connect your first server') }}
                            </h1>
                            <p class="text-[14px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Run this on the server as root. The first scan only reads, so nothing restarts and no config changes.',
                                    )
                                }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div
                                class="ds-segments w-full!"
                                role="group"
                                :aria-label="$t('How to connect')"
                            >
                                <button
                                    type="button"
                                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                    :class="focusRing"
                                    :aria-pressed="method === 'command'"
                                    @click="method = 'command'"
                                >
                                    {{ $t('One command') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                                    :class="focusRing"
                                    :aria-pressed="method === 'api'"
                                    @click="method = 'api'"
                                >
                                    {{ $t('Provisioning API') }}
                                </button>
                            </div>

                            <template v-if="method === 'command'">
                                <p
                                    v-if="!canConnect || commandError"
                                    class="rounded-[10px] border border-(--ds-amber-border) bg-(--ds-amber-bg) px-3.5 py-3 text-[13px]/5 text-(--ds-amber-strong)"
                                >
                                    {{
                                        commandError ||
                                        $t(
                                            'Connecting servers isn’t available just yet. You can do it from Machines later.',
                                        )
                                    }}
                                </p>
                                <div
                                    v-else
                                    class="flex flex-col overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-subtle)"
                                >
                                    <div
                                        class="flex items-start gap-3 py-3 pr-3 pl-3.5"
                                    >
                                        <code
                                            class="grow font-ui-mono text-[12.5px]/5 break-all text-(--ds-text)"
                                        >
                                            {{
                                                command ||
                                                $t('Making your command…')
                                            }}
                                        </code>
                                        <button
                                            type="button"
                                            :class="
                                                appButtonClasses(
                                                    'secondary',
                                                    'sm',
                                                    'shrink-0',
                                                )
                                            "
                                            :disabled="!command"
                                            @click="copy(command)"
                                        >
                                            <Copy class="size-3.5" />
                                            {{ $t('Copy') }}
                                        </button>
                                    </div>
                                    <p
                                        class="border-t border-(--ds-divider) px-3.5 py-2 text-[12px]/4 text-(--ds-text-faint)"
                                    >
                                        {{ $t('Works once, for 15 minutes.') }}
                                    </p>
                                </div>

                                <div
                                    v-if="canConnect && !commandError"
                                    class="flex items-center gap-2.5 rounded-[10px] border px-3.5 py-2.5"
                                    :class="
                                        connected
                                            ? 'border-(--ds-green-border) bg-(--ds-green-bg)'
                                            : connectState === 'expired'
                                              ? 'border-(--ds-border) bg-(--ds-subtle)'
                                              : 'border-(--ds-blue-border) bg-(--ds-blue-bg)'
                                    "
                                    aria-live="polite"
                                >
                                    <template v-if="connected">
                                        <Check
                                            class="size-4 shrink-0 text-(--ds-green)"
                                            :stroke-width="2.4"
                                        />
                                        <span
                                            class="grow text-[13px]/4.5 font-medium text-(--ds-green-strong)"
                                        >
                                            {{
                                                connectState === 'discovered' &&
                                                !discovery
                                                    ? $t(
                                                          ':name is connected.',
                                                          {
                                                              name:
                                                                  machineName ??
                                                                  $t(
                                                                      'Your server',
                                                                  ),
                                                          },
                                                      )
                                                    : connectState ===
                                                        'discovered'
                                                      ? $t(
                                                            ':name is connected. Found :count services.',
                                                            {
                                                                name:
                                                                    machineName ??
                                                                    $t(
                                                                        'Your server',
                                                                    ),
                                                                count: String(
                                                                    serviceCount,
                                                                ),
                                                            },
                                                        )
                                                      : $t(
                                                            ':name is connected. Finding what’s running…',
                                                            {
                                                                name:
                                                                    machineName ??
                                                                    $t(
                                                                        'Your server',
                                                                    ),
                                                            },
                                                        )
                                            }}
                                        </span>
                                    </template>
                                    <template
                                        v-else-if="connectState === 'expired'"
                                    >
                                        <span
                                            class="grow text-[13px]/4.5 text-(--ds-text-muted)"
                                        >
                                            {{
                                                $t('That command has expired.')
                                            }}
                                        </span>
                                        <button
                                            type="button"
                                            :class="
                                                appButtonClasses(
                                                    'secondary',
                                                    'sm',
                                                )
                                            "
                                            @click="createCommand"
                                        >
                                            {{ $t('Get a new one') }}
                                        </button>
                                    </template>
                                    <template v-else>
                                        <span
                                            class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-(--ds-blue-border) border-t-(--ds-blue) motion-reduce:animate-none"
                                        />
                                        <span
                                            class="grow text-[13px]/4.5 font-medium text-(--ds-blue)"
                                        >
                                            {{
                                                $t(
                                                    'Waiting for your server to connect…',
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="timeLeft"
                                            class="text-[12px]/4 text-(--ds-text-muted) tabular-nums"
                                        >
                                            {{
                                                $t(':time left', {
                                                    time: timeLeft,
                                                })
                                            }}
                                        </span>
                                    </template>
                                </div>
                            </template>

                            <template v-else>
                                <div
                                    v-if="!apiKey"
                                    class="flex items-center gap-3 rounded-[10px] border border-(--ds-border) bg-(--ds-panel) px-3.5 py-3"
                                >
                                    <span class="flex grow flex-col">
                                        <span
                                            class="text-[13.5px]/5 font-medium"
                                        >
                                            {{ $t('First, an API key') }}
                                        </span>
                                        <span
                                            class="text-[12.5px]/[17px] text-(--ds-text-muted)"
                                        >
                                            {{
                                                apiKeyError ||
                                                $t(
                                                    'Your provisioning script uses it to add servers.',
                                                )
                                            }}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        :class="
                                            appButtonClasses(
                                                'primary',
                                                'sm',
                                                'shrink-0',
                                            )
                                        "
                                        :disabled="apiKeyBusy"
                                        @click="createApiKey"
                                    >
                                        {{ $t('Create a key') }}
                                    </button>
                                </div>
                                <p
                                    v-else
                                    class="flex items-start gap-2 rounded-[10px] border border-(--ds-green-border) bg-(--ds-green-bg) px-3.5 py-2.5 text-[13px]/[18px] text-(--ds-green-strong)"
                                >
                                    <Check
                                        class="mt-0.5 size-3.5 shrink-0"
                                        :stroke-width="2.4"
                                    />
                                    {{
                                        $t(
                                            'Key made and added below. Copy it now: it won’t be shown again.',
                                        )
                                    }}
                                </p>

                                <div
                                    class="flex flex-col overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-subtle)"
                                >
                                    <div
                                        class="flex items-start gap-3 py-3 pr-3 pl-3.5"
                                    >
                                        <pre
                                            class="grow font-ui-mono text-[12px]/5 [overflow-wrap:anywhere] whitespace-pre-wrap text-(--ds-text)"
                                            >{{ apiSnippet }}</pre>
                                        <button
                                            type="button"
                                            :class="
                                                appButtonClasses(
                                                    'secondary',
                                                    'sm',
                                                    'shrink-0',
                                                )
                                            "
                                            @click="copy(apiSnippet)"
                                        >
                                            <Copy class="size-3.5" />
                                            {{ $t('Copy') }}
                                        </button>
                                    </div>
                                    <p
                                        class="border-t border-(--ds-divider) px-3.5 py-2 text-[12px]/4.5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t(
                                                'Each call returns a one-time token for one new server.',
                                            )
                                        }}
                                        <a
                                            href="/settings/api"
                                            class="font-medium text-(--ds-text) underline decoration-(--ds-border-hover) underline-offset-2"
                                            >{{ $t('Your keys') }}</a
                                        >
                                        ·
                                        <a
                                            :href="
                                                docsUrl(
                                                    $page.props.docsUrl,
                                                    '/guides/api',
                                                )
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            class="font-medium text-(--ds-text) underline decoration-(--ds-border-hover) underline-offset-2"
                                            >{{ $t('Read the guide') }}</a
                                        >
                                    </p>
                                </div>
                            </template>

                            <details
                                class="rounded-[10px] border border-(--ds-border) bg-(--ds-subtle)"
                            >
                                <summary
                                    class="cursor-pointer px-3.5 py-2.5 text-[13px]/5 font-medium text-(--ds-text-secondary)"
                                >
                                    {{ $t('What this server will send') }}
                                </summary>
                                <div class="px-3 pb-3">
                                    <PolicySends :settings="control" />
                                </div>
                            </details>
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                :class="
                                    appButtonClasses('secondary', 'md', 'h-9')
                                "
                                @click="step = choosePlan ? 'plan' : 'control'"
                            >
                                {{ $t('Back') }}
                            </button>
                            <button
                                type="button"
                                :class="
                                    appButtonClasses(
                                        connected ? 'primary' : 'secondary',
                                        'md',
                                        'h-9 grow justify-center',
                                    )
                                "
                                :disabled="busy"
                                @click="finish(!connected)"
                            >
                                {{
                                    connected
                                        ? $t('Open dashboard')
                                        : $t('I’ll do this later')
                                }}
                            </button>
                        </div>
                    </div>
                </div>
                <button
                    v-if="!cardRequired"
                    type="button"
                    class="group mx-auto mt-5 flex cursor-pointer items-center gap-1.5 rounded-md text-[12.5px]/5 text-(--ds-text-muted) transition-colors hover:text-(--ds-text)"
                    :class="focusRing"
                    @click="confirmingSkip = true"
                >
                    {{ $t('Skip for now') }}
                    <ArrowRight
                        class="size-3.5 transition-transform group-hover:translate-x-0.5"
                        :stroke-width="2"
                    />
                </button>
            </div>
        </main>

        <footer
            v-if="$page.props.brand.company"
            class="py-6 text-center text-[12px]/4 text-(--ds-text-faint)"
        >
            {{ `© ${new Date().getFullYear()} ${$page.props.brand.company}.` }}
        </footer>

        <Dialog v-model:open="confirmingSkip">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-(--ds-text) sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('Skip setting up?') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'You can connect a server and choose a plan from your dashboard whenever you like. This takes about a minute.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <button
                        type="button"
                        :class="appButtonClasses('secondary', 'md', 'h-9')"
                        :disabled="busy"
                        @click="finish(true)"
                    >
                        {{ $t('Skip') }}
                    </button>
                    <button
                        type="button"
                        :class="
                            appButtonClasses(
                                'primary',
                                'md',
                                'h-9 justify-center',
                            )
                        "
                        @click="confirmingSkip = false"
                    >
                        {{ $t('Keep going') }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
