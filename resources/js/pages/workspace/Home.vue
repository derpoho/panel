<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import ProviderName from '@/components/machines/ProviderName.vue';
import IconTip from '@/components/workspace/IconTip.vue';
import { providerBrand } from '@/lib/providerBrand';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, ArrowUpRight, Check, CheckCheck } from '@lucide/vue';
import {
    SolarArchive as Archive,
    SolarCloud as Cloud,
    SolarCopy as Copy,
    SolarCpu as Cpu,
    SolarImport as Import,
    SolarKey as Key,
    SolarKeyRound as KeyRound,
    SolarLayers as Layers,
    SolarMemoryStick as MemoryStick,
    SolarPlug as Plug,
    SolarRocket as Rocket,
    SolarServer as Server,
    SolarSparkles as Sparkles,
    SolarTerminal as Terminal,
    SolarAskAi as AskAi,
    SolarWorkflows as Workflows,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { toast } from '@/lib/toast';
import EnvironmentBadge from '@/components/machines/EnvironmentBadge.vue';
import InstallingBadge from '@/components/machines/InstallingBadge.vue';
import type { Machine } from '@/components/machines/machines';
import AppButton from '@/components/workspace/AppButton.vue';
import AskAiButton from '@/components/workspace/AskAiButton.vue';
import type { HealthIssue } from '@/lib/healthIssues';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import McpDialog from '@/components/workspace/McpDialog.vue';
import DotMeter from '@/components/charts/DotMeter.vue';
import RidgeChart from '@/components/charts/RidgeChart.vue';
import SegmentedTabs from '@/components/workspace/SegmentedTabs.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { useAssistant } from '@/composables/useAssistant';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { onWorkspace } from '@/lib/workspaceRoute';
import workspaceRoutes from '@/routes/workspace';
import backupsRoutes from '@/routes/workspace/backups';
import machinesRoutes from '@/routes/workspace/machines';
import membersRoutes from '@/routes/workspace/members';
import servicesRoutes from '@/routes/workspace/services';
import workflowsRoutes from '@/routes/workspace/workflows';
import onboardingRoutes from '@/routes/workspace/onboarding';
import type {
    OnboardingStep,
    OnboardingStepKey,
    Workspace,
    WorkspaceAccess,
    WorkspaceStats,
} from '@/types';

type Props = {
    workspace: Workspace;
    fixesWaiting: { id: number; title: string | null }[];
    onboarding: OnboardingStep[];
    onboardingDismissed: boolean;
    stats: WorkspaceStats;
    providers: { provider: string; machines: number }[];
    issues: HealthIssue[];
    machines: Machine[];
    memberCount: number;
    lastBackup: string | null;
    panelUpdate: { version: string; current: string } | null;
    licenseMachines?: {
        allowed: number;
        connected: number;
        over: number;
    } | null;
};

const props = defineProps<Props>();
const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();

const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/)[0] ?? '',
);

const url = computed(() => `https://${props.workspace.host}`);

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copyUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(url.value);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 1600);
        toast.success(trans('Copied'));
    } catch {
        toast.error(trans('Couldn’t copy the URL'));
    }
}

const hasMachines = computed(() => props.stats.machines > 0);

const machinesHref = computed(() =>
    onWorkspace(machinesRoutes.index(props.workspace.slug)),
);

const upgrade = useUpgradeLink();

const tabs = computed(() => [
    {
        label: trans('Overview'),
        href: onWorkspace(workspaceRoutes.home(props.workspace.slug)).url,
        current: true,
    },
    {
        label: trans('Machines'),
        href: onWorkspace(machinesRoutes.index(props.workspace.slug)).url,
        current: false,
    },
    {
        label: trans('Members'),
        href: onWorkspace(membersRoutes.index(props.workspace.slug)).url,
        current: false,
    },
    ...(upgrade.value
        ? [
              {
                  label: upgrade.value.label,
                  href: upgrade.value.href,
                  current: false,
              },
          ]
        : []),
]);

type Chip = { text: string; tone: 'green' | 'amber' | 'red' };

const machineAllowance = computed(() => {
    const limit = props.workspace.billing.machineLimit;
    const license = props.licenseMachines;

    if (license) {
        return license.over > 0
            ? trans('of :limit in your license, over by :count', {
                  limit: String(license.allowed),
                  count: String(license.over),
              })
            : trans('of :limit in your license', {
                  limit: String(license.allowed),
              });
    }
    if (props.workspace.billing.selfHosted) {
        return limit === null
            ? trans('no limit')
            : trans('of :limit on this install', { limit: String(limit) });
    }

    return limit === null
        ? trans('no limit on your plan')
        : trans('of :limit on your plan', { limit: String(limit) });
});

const BUSY_CPU = 80;

type Kpi = {
    label: string;
    value: string;
    note: string;
    noteTone: string;
    href?: string;
};

const kpis = computed<Kpi[]>(() => {
    const { machines, services, averageCpu, busy, backedUp } = props.stats;
    const attention = props.issues.filter(
        (issue) => issue.severity !== 'notice',
    ).length;
    const unprotected = machines - backedUp;

    return [
        {
            label: trans('Machines'),
            value: String(machines),
            note:
                attention > 0
                    ? trans(':count need attention', {
                          count: String(attention),
                      })
                    : trans('healthy'),
            noteTone:
                attention > 0 ? 'text-(--ds-amber)' : 'text-(--ds-text-faint)',
            href: machinesHref.value.url,
        },
        {
            label: trans('Services'),
            value: String(services),
            note: trans('running'),
            noteTone: 'text-(--ds-text-faint)',
            href: onWorkspace(servicesRoutes.index(props.workspace.slug)).url,
        },
        {
            label: trans('Average CPU'),
            value: `${averageCpu}%`,
            note:
                busy > 0
                    ? trans(':count busy', { count: String(busy) })
                    : trans('normal'),
            noteTone: busy > 0 ? 'text-(--ds-amber)' : 'text-(--ds-text-faint)',
        },
        {
            label: trans('Backups'),
            value: `${backedUp}/${machines}`,
            note:
                unprotected > 0
                    ? trans(':count unprotected', {
                          count: String(unprotected),
                      })
                    : (props.lastBackup ?? ''),
            noteTone:
                unprotected > 0 ? 'text-(--ds-red)' : 'text-(--ds-text-faint)',
            href: onWorkspace(backupsRoutes.index(props.workspace.slug)).url,
        },
    ];
});

type CpuRange = WorkspaceStats['cpuRange'];

const cpuRanges: {
    key: CpuRange;
    label: string;
    step: number;
    span: string;
    ticks: string[];
}[] = [
    {
        key: '1h',
        label: '1h',
        step: 60_000,
        span: trans('last hour'),
        ticks: ['-60m', '-45m', '-30m', '-15m'],
    },
    {
        key: '24h',
        label: '24h',
        step: 900_000,
        span: trans('last 24 hours'),
        ticks: ['-24h', '-18h', '-12h', '-6h'],
    },
    {
        key: '7d',
        label: '7d',
        step: 7_200_000,
        span: trans('last 7 days'),
        ticks: ['-7d', '-5d', '-3.5d', '-2d'],
    },
    {
        key: '30d',
        label: '30d',
        step: 28_800_000,
        span: trans('last 30 days'),
        ticks: ['-30d', '-22d', '-15d', '-7d'],
    },
];

const cpuRange = computed(
    () =>
        cpuRanges.find((range) => range.key === props.stats.cpuRange) ??
        cpuRanges[1],
);

const loadingRange = ref<CpuRange | null>(null);

function pickRange(key: CpuRange): void {
    if (key === props.stats.cpuRange) return;
    loadingRange.value = key;
    router.reload({
        data: { range: key },
        only: ['stats'],
        onFinish: () => (loadingRange.value = null),
    });
}

const cpuLabels = computed(() => {
    const now = Date.now();
    const count = props.stats.cpuTimeline.length;
    const { step } = cpuRange.value;
    const short = step < 3_600_000;

    return props.stats.cpuTimeline.map((_, index) =>
        new Date(now - (count - 1 - index) * step).toLocaleString(
            [],
            short
                ? { hour: '2-digit', minute: '2-digit' }
                : {
                      month: 'short',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                  },
        ),
    );
});

const cpuTicks = computed(() => [
    ...cpuRange.value.ticks.map((label, index) => ({
        at: index / 4,
        label,
    })),
    { at: 1, label: trans('now') },
]);

const connectionTone: Record<NonNullable<Machine['connection']>, string> = {
    online: 'bg-(--ds-green)',
    degraded: 'bg-(--ds-amber)',
    offline: 'bg-(--ds-red)',
    connecting: 'bg-(--ds-blue)',
    demo: 'bg-(--ds-text-faint)',
};

const assistant = useAssistant();

const quickActions = computed(() => [
    {
        label: trans('Deploy a service'),
        icon: Rocket,
        href: onWorkspace(servicesRoutes.index(props.workspace.slug)).url,
    },
    {
        label: trans('Workflows'),
        icon: Workflows,
        href: onWorkspace(workflowsRoutes.index(props.workspace.slug)).url,
    },
    {
        label: trans('Backups'),
        icon: Archive,
        href: onWorkspace(backupsRoutes.index(props.workspace.slug)).url,
    },
]);

const envColors = [
    'bg-[#3b7bff]',
    'bg-(--ds-text-secondary)',
    'bg-(--ds-text-faint)',
];

const environments = computed(() => {
    const { production, staging, development } = props.stats.environments;

    return [
        { label: trans('Production'), count: production },
        { label: trans('Staging'), count: staging },
        { label: trans('Development'), count: development },
    ];
});

const topProviders = computed(() => props.providers.slice(0, 4));

const envMix = computed(() =>
    environments.value.map((env, index) => ({
        ...env,
        color: envColors[index],
        share: share(env.count),
    })),
);

const fleetFacts = computed(() => [
    ...environments.value
        .filter((env) => env.count > 0)
        .map((env) => `${env.count} ${env.label.toLowerCase()}`),
    `${props.stats.services} ${props.stats.services === 1 ? trans('service') : trans('services')}`,
    `${props.memberCount} ${props.memberCount === 1 ? trans('member') : trans('members')}`,
]);

function share(count: number): string {
    return `${Math.round((count / Math.max(props.stats.machines, 1)) * 100)}%`;
}

const machineGroups = computed(() => {
    const shown = props.machines.slice(0, 14);
    const keys = ['production', 'staging', 'development'];

    return environments.value
        .map((env, index) => ({
            key: keys[index],
            label: env.label,
            rows: shown.filter(
                (machine) => machine.environment === keys[index],
            ),
        }))
        .filter((group) => group.rows.length > 0);
});

const issuesOpen = ref(false);
const shownIssues = computed(() =>
    issuesOpen.value ? props.issues : props.issues.slice(0, 4),
);
const expandedIssue = ref<string | null>(null);

const canDismiss = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const dismissing = ref<string[]>([]);

function dismiss(ids: string[]): void {
    dismissing.value = ids;
    router.post(
        '/issues/dismiss',
        { ids },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['issues'],
            onFinish: () => (dismissing.value = []),
        },
    );
}

const issueTone: Record<HealthIssue['severity'], { dot: string; bar: string }> =
    {
        critical: {
            dot: 'bg-(--ds-red)',
            bar: 'shadow-[inset_3px_0_0_var(--ds-red)]',
        },
        warning: {
            dot: 'bg-(--ds-amber)',
            bar: 'shadow-[inset_3px_0_0_var(--ds-amber)]',
        },
        notice: { dot: 'bg-(--ds-text-faint)', bar: '' },
    };

function since(iso: string | null): string | null {
    if (!iso) return null;
    const seconds = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (seconds < 60) return trans('just now');
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h`;

    return `${Math.floor(seconds / 86400)} d`;
}

const stepTitles: Record<OnboardingStepKey, string> = {
    workspace: trans('Create your workspace'),
    machine: trans('Connect your first machine'),
    service: trans('Deploy a service'),
    backups: trans('Turn on backups'),
    plan: trans('Choose a plan'),
};

const stepDescriptions: Record<OnboardingStepKey, string> = {
    workspace: trans('Your team’s home on ServerOS.'),
    machine: trans(
        'Bring a VPS from any provider. ServerOS installs its daemon and leaves what’s already running alone.',
    ),
    service: trans('Ship an app, database or worker to one of your machines.'),
    backups: trans('Schedule backups so you can roll back in one click.'),
    plan: trans('Pick the plan that fits your fleet.'),
};

const completedSteps = computed(
    () => props.onboarding.filter((step) => step.done).length,
);

const currentStep = computed(
    () => props.onboarding.find((step) => !step.done)?.key ?? null,
);

const showGuide = computed(
    () => props.stats.machines === 0 && !props.onboardingDismissed,
);

const canResumeGuide = computed(
    () =>
        props.stats.machines === 0 &&
        props.onboardingDismissed &&
        completedSteps.value < props.onboarding.length,
);

function skipGuide(): void {
    router.post(
        onWorkspace(onboardingRoutes.dismiss(props.workspace.slug)).url,
        {},
        { preserveScroll: true },
    );
}

function resumeGuide(): void {
    router.delete(
        onWorkspace(onboardingRoutes.resume(props.workspace.slug)).url,
        { preserveScroll: true },
    );
}

const mcpOpen = ref(false);

const connectOptions: {
    title: string;
    description: string;
    icon: Component;
    action?: () => void;
}[] = [
    {
        title: trans('Install daemon'),
        description: trans('One-line install'),
        icon: Terminal,
    },
    {
        title: trans('SSH'),
        description: trans('Connect with a key'),
        icon: KeyRound,
    },
    {
        title: trans('Import'),
        description: trans('Bring existing machines'),
        icon: Import,
    },
    {
        title: trans('Cloud providers'),
        description: trans('Hetzner, DigitalOcean…'),
        icon: Cloud,
    },
    {
        title: trans('API keys'),
        description: trans('Automate ServerOS'),
        icon: Key,
    },
    {
        title: trans('MCP'),
        description: trans('Connect your agent'),
        icon: Sparkles,
        action: () => (mcpOpen.value = true),
    },
];

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Home')" />

    <WorkspacePage
        :title="$t('Hey :firstName 👋', { firstName: String(firstName) })"
        toolbar
    >
        <template #description>
            {{
                ' ' +
                $t('Here’s how :name is running right now.', {
                    name: workspace.name,
                }) +
                ' '
            }}
        </template>

        <template #tabs>
            <SegmentedTabs :label="$t('Workspace')" :tabs="tabs" />
        </template>

        <template #actions>
            <button
                type="button"
                class="ds-raised-button max-w-64 cursor-pointer"
                :class="focusRing"
                :aria-label="$t('Copy :url', { url: String(url) })"
                :title="$t('Copy URL')"
                @click="copyUrl"
            >
                <span class="truncate">{{ workspace.host }}</span>
                <Check
                    v-if="copied"
                    key="check"
                    class="mi-pop size-3.5 shrink-0 text-(--ds-green)"
                    :stroke-width="2.25"
                />
                <Copy
                    v-else
                    key="copy"
                    class="mi-copy size-3.5 shrink-0 opacity-60"
                    :stroke-width="1.75"
                />
            </button>
            <ConnectMachineButton size="sm" />
        </template>

        <div class="flex min-w-0 flex-col gap-5">
            <Link
                v-if="panelUpdate"
                href="/settings/general#updates"
                class="ds-tile flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-(--ds-row-hover)"
                :class="focusRing"
            >
                <span
                    class="size-[18px] shrink-0 rounded-full border-2 border-(--ds-blue)"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1 truncate text-[14px]/5">
                    <span class="font-medium">{{
                        $t('Update available')
                    }}</span>
                    <span class="text-(--ds-text-muted)">
                        ·
                        {{
                            $t(
                                'ServerOS :version is available. You’re on :current.',
                                {
                                    version: panelUpdate.version,
                                    current: panelUpdate.current,
                                },
                            )
                        }}</span
                    >
                </span>
                <span class="shrink-0 text-[13px]/5 text-(--ds-text-muted)">{{
                    $t('Update')
                }}</span>
            </Link>

            <Link
                v-if="fixesWaiting.length"
                :href="`/fixes?fix=${fixesWaiting[0].id}`"
                class="ds-tile flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-(--ds-row-hover)"
                :class="focusRing"
            >
                <span
                    class="size-[18px] shrink-0 rounded-full border-2 border-(--ds-amber)"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1 truncate text-[14px]/5">
                    <span class="font-medium">{{
                        fixesWaiting.length === 1
                            ? $t('A fix needs your approval')
                            : $t(':count fixes need your approval', {
                                  count: String(fixesWaiting.length),
                              })
                    }}</span>
                    <span
                        v-if="fixesWaiting[0].title"
                        class="text-(--ds-text-muted)"
                    >
                        · {{ fixesWaiting[0].title }}</span
                    >
                </span>
                <span class="shrink-0 text-[13px]/5 text-(--ds-text-muted)">{{
                    $t('Review')
                }}</span>
            </Link>

            <section
                v-if="issues.length"
                class="ds-tile overflow-hidden"
                aria-labelledby="issues-heading"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-(--ds-divider) px-5 py-3"
                >
                    <h2 id="issues-heading" class="text-[13px]/5 font-semibold">
                        {{ ' ' + $t('Needs attention') }}
                        <span
                            class="ml-1 font-normal text-(--ds-text-faint) tabular-nums"
                            >{{ issues.length }}</span
                        >
                    </h2>
                    <button
                        v-if="canDismiss && issues.length > 1"
                        type="button"
                        class="flex cursor-pointer items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[13px]/5 font-medium text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) disabled:opacity-50"
                        :class="focusRing"
                        :disabled="dismissing.length > 0"
                        @click="dismiss(issues.map((issue) => issue.id))"
                    >
                        <CheckCheck class="size-3.5" :stroke-width="1.75" />
                        {{ $t('Mark all as read') + ' ' }}
                    </button>
                </div>
                <ul>
                    <li
                        v-for="issue in shownIssues"
                        :key="issue.id"
                        class="ds-tile-row"
                        :class="issueTone[issue.severity].bar"
                    >
                        <div
                            class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 px-5 py-3"
                        >
                            <span
                                class="mt-1.75 size-2 rounded-full"
                                :class="issueTone[issue.severity].dot"
                                :aria-label="issue.severity"
                            />
                            <button
                                type="button"
                                class="flex min-w-0 cursor-pointer flex-col items-start gap-0.5 text-left"
                                :class="focusRing"
                                :aria-expanded="expandedIssue === issue.id"
                                @click="
                                    expandedIssue =
                                        expandedIssue === issue.id
                                            ? null
                                            : issue.id
                                "
                            >
                                <span class="text-[13px]/5 font-medium">
                                    {{ issue.title }}
                                    <span
                                        v-if="since(issue.since)"
                                        class="ml-1 font-normal text-(--ds-text-faint) tabular-nums"
                                        >{{ since(issue.since) }}</span
                                    >
                                </span>
                                <span
                                    class="text-[13px]/5 text-pretty text-(--ds-text-secondary)"
                                    :class="{
                                        'line-clamp-1':
                                            expandedIssue !== issue.id,
                                    }"
                                >
                                    {{ issue.detail }}
                                </span>
                            </button>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <AskAiButton
                                    v-if="issue.ask"
                                    :prompt="issue.ask"
                                    class="max-sm:hidden!"
                                />
                                <Link
                                    :href="issue.href"
                                    class="ds-raised-button shrink-0 text-[13px]!"
                                    :class="focusRing"
                                >
                                    {{ issue.action }}
                                </Link>
                                <button
                                    v-if="canDismiss"
                                    type="button"
                                    class="ds-raised-button flex size-8 shrink-0 cursor-pointer items-center justify-center p-0! disabled:opacity-50"
                                    :class="focusRing"
                                    :disabled="dismissing.includes(issue.id)"
                                    :title="$t('Mark as read')"
                                    :aria-label="
                                        $t('Mark as read: :title', {
                                            title: String(issue.title),
                                        })
                                    "
                                    @click="dismiss([issue.id])"
                                >
                                    <Check
                                        class="size-3.5"
                                        :stroke-width="1.9"
                                    />
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
                <button
                    v-if="issues.length > 4"
                    type="button"
                    class="w-full cursor-pointer border-t border-(--ds-divider) px-5 py-2.5 text-left text-[13px]/5 font-medium text-(--ds-text-secondary) hover:bg-(--ds-row-hover) hover:text-(--ds-text)"
                    :class="focusRing"
                    @click="issuesOpen = !issuesOpen"
                >
                    {{
                        issuesOpen
                            ? $t('Show fewer')
                            : $t('Show :value more', {
                                  value: String(issues.length - 4),
                              })
                    }}
                </button>
            </section>

            <section
                v-if="hasMachines"
                class="ds-tile overflow-hidden"
                :aria-label="$t('Status')"
            >
                <dl
                    class="grid border-b border-(--ds-divider) sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div
                        v-for="kpi in kpis"
                        :key="kpi.label"
                        class="relative flex min-w-0 flex-col gap-1.5 border-(--ds-divider) px-5 py-4 not-first:max-sm:border-t sm:even:border-l xl:not-first:border-l sm:[&:nth-child(n+3)]:max-xl:border-t"
                    >
                        <dt
                            class="font-ui-mono text-[10.5px]/4 tracking-[0.08em] text-(--ds-text-faint) uppercase"
                        >
                            <Link
                                v-if="kpi.href"
                                :href="kpi.href"
                                class="after:absolute after:inset-0 hover:text-(--ds-text) focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-(--ds-ring)"
                                >{{ kpi.label }}</Link
                            >
                            <template v-else>{{ kpi.label }}</template>
                        </dt>
                        <dd class="flex items-baseline gap-2 font-ui-mono">
                            <span
                                class="text-[22px]/7 font-medium tracking-[-0.03em] tabular-nums"
                                >{{ kpi.value }}</span
                            >
                            <span
                                class="truncate text-[11px]/4 uppercase"
                                :class="kpi.noteTone"
                                >{{ kpi.note }}</span
                            >
                        </dd>
                    </div>
                </dl>
                <div class="px-5 pt-4 pb-3">
                    <div class="flex items-center justify-between gap-3">
                        <p
                            class="font-ui-mono text-[10.5px]/4 tracking-[0.08em] text-(--ds-text-faint) uppercase"
                        >
                            {{
                                $t('CPU · :count machines · :span', {
                                    count: String(stats.machines),
                                    span: cpuRange.span,
                                })
                            }}
                        </p>
                        <div
                            class="flex items-center gap-0.5 font-ui-mono text-[10.5px]/4 tracking-[0.06em] uppercase"
                            role="group"
                            :aria-label="$t('Time range')"
                        >
                            <button
                                v-for="range in cpuRanges"
                                :key="range.key"
                                type="button"
                                class="cursor-pointer rounded-[6px] px-2 py-1 transition-colors"
                                :class="[
                                    range.key === stats.cpuRange
                                        ? 'bg-(--ds-active) text-(--ds-text)'
                                        : 'text-(--ds-text-faint) hover:text-(--ds-text)',
                                    loadingRange === range.key &&
                                        'animate-pulse',
                                    focusRing,
                                ]"
                                :aria-pressed="range.key === stats.cpuRange"
                                @click="pickRange(range.key)"
                            >
                                {{ range.label }}
                            </button>
                        </div>
                    </div>
                    <div class="mt-3 h-64">
                        <RidgeChart
                            :values="stats.cpuTimeline"
                            :labels="cpuLabels"
                            :ticks="cpuTicks"
                            :threshold="{
                                value: BUSY_CPU,
                                label: `${BUSY_CPU}%`,
                                note: $t('busy line'),
                            }"
                            :label="
                                $t('Average CPU over the :span', {
                                    span: cpuRange.span,
                                })
                            "
                        />
                    </div>
                </div>
            </section>

            <div v-if="hasMachines" class="grid items-start gap-5">
                <section class="ds-tray" aria-labelledby="list-heading">
                    <div class="ds-tray-head">
                        <h2
                            id="list-heading"
                            class="font-medium text-(--ds-text)"
                        >
                            {{ $t('Machines') }}
                        </h2>
                        <span class="font-ui-mono text-(--ds-text-faint)">{{
                            stats.machines
                        }}</span>
                        <span
                            class="hidden truncate text-(--ds-text-faint) md:inline"
                            >· {{ fleetFacts.join(' · ') }}</span
                        >
                        <Link
                            :href="machinesHref.url"
                            class="group ml-auto flex shrink-0 items-center gap-1 rounded-md hover:text-(--ds-text)"
                            :class="focusRing"
                        >
                            {{ $t('All machines') }}
                            <ArrowRight
                                class="mi-arrow size-3.5"
                                :stroke-width="1.75"
                            />
                        </Link>
                    </div>
                    <div class="ds-tile overflow-x-auto">
                        <table
                            class="w-full min-w-[720px] border-collapse text-left text-[13px]/5"
                        >
                            <thead>
                                <tr
                                    class="text-[12px]/4 text-(--ds-text-faint)"
                                >
                                    <th class="py-2.5 pr-3 pl-4 font-normal">
                                        {{ $t('Machine') }}
                                    </th>
                                    <th
                                        class="border-l border-(--ds-divider) px-3 font-normal"
                                    >
                                        {{ $t('Provider') }}
                                    </th>
                                    <th
                                        class="w-40 border-l border-(--ds-divider) px-3 font-normal"
                                    >
                                        {{ $t('CPU') }}
                                    </th>
                                    <th
                                        class="w-40 border-l border-(--ds-divider) px-3 font-normal"
                                    >
                                        {{ $t('Disk') }}
                                    </th>
                                    <th
                                        class="w-24 border-l border-(--ds-divider) px-3 text-right font-normal"
                                    >
                                        {{ $t('Memory') }}
                                    </th>
                                    <th
                                        class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal"
                                    >
                                        {{ $t('Services') }}
                                    </th>
                                    <th
                                        class="w-28 border-l border-(--ds-divider) pr-4 pl-3 text-right font-normal"
                                    >
                                        {{ $t('Backup') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                v-for="group in machineGroups"
                                :key="group.key"
                            >
                                <tr
                                    class="border-t border-(--ds-divider) bg-(--ds-subtle)"
                                >
                                    <th
                                        colspan="7"
                                        class="py-2 pr-3 pl-4 text-left font-medium"
                                    >
                                        {{ group.label }}
                                        <span
                                            class="ml-1 font-ui-mono font-normal text-(--ds-text-faint)"
                                            >{{ group.rows.length }}</span
                                        >
                                    </th>
                                </tr>
                                <tr
                                    v-for="machine in group.rows"
                                    :key="machine.id ?? machine.name"
                                    class="group/row relative border-t border-(--ds-divider) transition-colors hover:bg-(--ds-row-hover)"
                                >
                                    <td class="py-2.5 pr-3 pl-4">
                                        <component
                                            :is="machine.id ? Link : 'div'"
                                            :href="
                                                machine.id
                                                    ? `/machines/${machine.id}`
                                                    : undefined
                                            "
                                            class="flex min-w-0 items-center gap-2.5 after:absolute after:inset-0"
                                            :class="machine.id && focusRing"
                                        >
                                            <span
                                                class="size-1.5 shrink-0 rounded-full"
                                                :class="
                                                    connectionTone[
                                                        machine.connection ??
                                                            'demo'
                                                    ]
                                                "
                                                :title="machine.connection"
                                            />
                                            <span
                                                class="truncate font-medium"
                                                >{{ machine.name }}</span
                                            >
                                            <span
                                                class="truncate font-ui-mono text-[11.5px] text-(--ds-text-faint)"
                                                >{{ machine.ip }}</span
                                            >
                                        </component>
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) px-3"
                                    >
                                        <ProviderName
                                            :name="machine.provider"
                                            class="text-(--ds-text-secondary)"
                                        />
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) px-3"
                                    >
                                        <span
                                            class="flex items-center gap-2.5 font-ui-mono text-[12px] tabular-nums"
                                        >
                                            <DotMeter
                                                :value="machine.cpu"
                                                :warn-at="BUSY_CPU"
                                                :dots="12"
                                            />
                                            <span
                                                class="w-9 text-right"
                                                :class="
                                                    machine.cpu >= BUSY_CPU
                                                        ? 'text-(--ds-amber)'
                                                        : 'text-(--ds-text-secondary)'
                                                "
                                                >{{ machine.cpu }}%</span
                                            >
                                        </span>
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) px-3"
                                    >
                                        <span
                                            v-if="
                                                machine.diskPercent !== null &&
                                                machine.diskPercent !==
                                                    undefined
                                            "
                                            class="flex items-center gap-2.5 font-ui-mono text-[12px] tabular-nums"
                                        >
                                            <DotMeter
                                                :value="machine.diskPercent"
                                                :warn-at="BUSY_CPU"
                                                :dots="12"
                                            />
                                            <span
                                                class="w-9 text-right"
                                                :class="
                                                    machine.diskPercent >=
                                                    BUSY_CPU
                                                        ? 'text-(--ds-amber)'
                                                        : 'text-(--ds-text-secondary)'
                                                "
                                                >{{
                                                    machine.diskPercent
                                                }}%</span
                                            >
                                        </span>
                                        <span
                                            v-else
                                            class="font-ui-mono text-(--ds-text-faint)"
                                            >—</span
                                        >
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) px-3 text-right font-ui-mono text-[12px] whitespace-nowrap text-(--ds-text-secondary) tabular-nums"
                                    >
                                        {{ machine.memory }}
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) px-3 text-right font-ui-mono text-[12px] whitespace-nowrap text-(--ds-text-secondary) tabular-nums"
                                    >
                                        {{ machine.services }}
                                    </td>
                                    <td
                                        class="border-l border-(--ds-divider) pr-4 pl-3 text-right font-ui-mono text-[12px] whitespace-nowrap text-(--ds-text-muted)"
                                    >
                                        {{ machine.lastBackup }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside
                    class="grid items-start gap-5 md:grid-cols-2"
                    :aria-label="$t('Shortcuts')"
                >
                    <section class="ds-tray" aria-labelledby="mix-heading">
                        <div class="ds-tray-head">
                            <h2
                                id="mix-heading"
                                class="font-medium text-(--ds-text)"
                            >
                                {{ $t('Fleet') }}
                            </h2>
                            <span class="ml-auto font-ui-mono">{{
                                machineAllowance
                            }}</span>
                        </div>
                        <div class="ds-tile flex flex-col gap-4 p-4">
                            <div class="flex h-2 gap-1" aria-hidden="true">
                                <span
                                    v-for="env in envMix.filter(
                                        (env) => env.count > 0,
                                    )"
                                    :key="env.label"
                                    class="h-full rounded-[2px]"
                                    :class="env.color"
                                    :style="{ flexGrow: env.count }"
                                />
                            </div>
                            <ul
                                class="flex flex-col gap-2 font-ui-mono text-[12px]/4"
                            >
                                <li
                                    v-for="env in envMix"
                                    :key="env.label"
                                    class="flex items-center gap-2"
                                >
                                    <span
                                        class="size-2 rounded-[2px]"
                                        :class="env.color"
                                        aria-hidden="true"
                                    />
                                    <span class="text-(--ds-text-secondary)">{{
                                        env.label.toLowerCase()
                                    }}</span>
                                    <span
                                        class="ml-auto text-(--ds-text) tabular-nums"
                                        >{{ env.count }}</span
                                    >
                                    <span
                                        class="w-10 text-right text-(--ds-text-faint) tabular-nums"
                                        >{{ env.share }}</span
                                    >
                                </li>
                            </ul>
                            <ul
                                v-if="topProviders.length"
                                class="flex flex-col gap-2 border-t border-(--ds-divider) pt-3 text-[12.5px]/4"
                            >
                                <li
                                    v-for="provider in topProviders"
                                    :key="provider.provider"
                                    class="flex items-center gap-2"
                                >
                                    <ProviderName
                                        :name="provider.provider"
                                        class="text-(--ds-text-secondary)"
                                    />
                                    <span
                                        class="ml-auto font-ui-mono text-[12px] text-(--ds-text) tabular-nums"
                                        >{{ provider.machines }}</span
                                    >
                                    <span
                                        class="w-10 text-right font-ui-mono text-[12px] text-(--ds-text-faint) tabular-nums"
                                        >{{ share(provider.machines) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section class="ds-tray" aria-labelledby="quick-heading">
                        <div class="ds-tray-head">
                            <h2
                                id="quick-heading"
                                class="font-medium text-(--ds-text)"
                            >
                                {{ $t('Shortcuts') }}
                            </h2>
                        </div>
                        <ul class="ds-tile flex flex-col py-1">
                            <li
                                v-for="action in quickActions"
                                :key="action.label"
                            >
                                <Link
                                    :href="action.href"
                                    class="group flex h-9 items-center gap-2.5 px-3.5 text-[13px]/5 text-(--ds-text-secondary) transition-colors hover:bg-(--ds-row-hover) hover:text-(--ds-text)"
                                    :class="focusRing"
                                >
                                    <component
                                        :is="action.icon"
                                        class="size-3.5 text-(--ds-text-faint)"
                                        :stroke-width="1.75"
                                    />
                                    {{ action.label }}
                                    <ArrowRight
                                        class="mi-arrow ml-auto size-3.5 text-(--ds-text-faint)"
                                        :stroke-width="1.75"
                                    />
                                </Link>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    class="flex h-9 w-full cursor-pointer items-center gap-2.5 px-3.5 text-[13px]/5 text-(--ds-text-secondary) transition-colors hover:bg-(--ds-row-hover) hover:text-(--ds-text)"
                                    :class="focusRing"
                                    aria-controls="assistant-panel"
                                    :aria-expanded="assistant.open.value"
                                    @click="assistant.toggle()"
                                >
                                    <AskAi
                                        class="size-3.5 text-(--ds-text-faint)"
                                        :stroke-width="1.75"
                                    />
                                    {{ $t('Ask AI') }}
                                    <kbd
                                        class="ml-auto font-ui-mono text-[11px] text-(--ds-text-faint)"
                                        >⌘J</kbd
                                    >
                                </button>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>

            <div v-else class="grid items-start gap-x-6 gap-y-8 lg:grid-cols-2">
                <section
                    class="flex flex-col gap-3"
                    aria-labelledby="setup-heading"
                >
                    <div class="flex h-6 items-center gap-3">
                        <h2
                            id="setup-heading"
                            class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                        >
                            {{ showGuide ? $t('Get set up') : $t('Machines') }}
                        </h2>
                        <template v-if="showGuide">
                            <span
                                class="text-[13px]/5 text-(--ds-text-muted) tabular-nums"
                            >
                                {{
                                    $t(':completedSteps of :length', {
                                        completedSteps: String(
                                            completedSteps ?? '',
                                        ),
                                        length: String(onboarding.length ?? ''),
                                    })
                                }}
                            </span>
                            <button
                                type="button"
                                class="ml-auto cursor-pointer rounded-md text-[13px]/5 font-medium text-(--ds-text-muted) hover:text-(--ds-text)"
                                :class="focusRing"
                                @click="skipGuide"
                            >
                                {{ $t('Skip for now') }}
                            </button>
                        </template>
                        <button
                            v-else-if="canResumeGuide"
                            type="button"
                            class="ml-auto cursor-pointer rounded-md text-[13px]/5 font-medium text-(--ds-text-muted) hover:text-(--ds-text)"
                            :class="focusRing"
                            @click="resumeGuide"
                        >
                            {{
                                $t('Resume setup · :completedSteps/:length', {
                                    completedSteps: String(
                                        completedSteps ?? '',
                                    ),
                                    length: String(onboarding.length ?? ''),
                                })
                            }}
                        </button>
                    </div>

                    <ol
                        v-if="showGuide"
                        class="ds-tile ds-tile-rows flex flex-col"
                    >
                        <li
                            v-for="(step, index) in onboarding"
                            :key="step.key"
                            class="ds-tile-row flex gap-3 px-4"
                            :class="
                                step.key === currentStep
                                    ? 'py-3.5'
                                    : 'h-11 items-center'
                            "
                        >
                            <span
                                class="flex size-4.5 shrink-0 items-center justify-center rounded-full text-[10px]/none font-semibold"
                                :class="[
                                    step.key === currentStep ? 'mt-px' : '',
                                    step.done
                                        ? 'bg-(--ds-hover) text-(--ds-text-muted)'
                                        : step.key === currentStep
                                          ? 'bg-(--ds-text) text-(--ds-panel)'
                                          : 'border border-(--ds-border-strong) text-(--ds-text-faint)',
                                ]"
                            >
                                <Check
                                    v-if="step.done"
                                    class="size-3"
                                    :stroke-width="2.4"
                                />
                                <span v-else class="ds-cap-trim">{{
                                    index + 1
                                }}</span>
                            </span>
                            <div class="flex min-w-0 grow flex-col gap-1">
                                <span
                                    class="text-[14px]/5"
                                    :class="
                                        step.done
                                            ? 'text-(--ds-text-faint)'
                                            : step.key === currentStep
                                              ? 'font-medium text-(--ds-text)'
                                              : 'text-(--ds-text-muted)'
                                    "
                                >
                                    {{ stepTitles[step.key] }}
                                </span>
                                <template v-if="step.key === currentStep">
                                    <p
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{ stepDescriptions[step.key] }}
                                    </p>
                                    <ConnectMachineButton
                                        v-if="step.key === 'machine'"
                                        size="xs"
                                        class="mt-2 self-start"
                                    >
                                        {{ $t('Connect your first machine') }}
                                    </ConnectMachineButton>
                                </template>
                            </div>
                            <span
                                v-if="step.done"
                                class="ml-auto shrink-0 text-[13px]/5 text-(--ds-text-faint)"
                            >
                                {{ $t('Done') }}
                            </span>
                        </li>
                    </ol>

                    <div
                        v-else
                        class="ds-tile flex flex-col items-start gap-3 px-4 py-4"
                    >
                        <div class="flex flex-col gap-1">
                            <h3 class="text-[14px]/5 font-medium">
                                {{ $t('No machines yet') }}
                            </h3>
                            <p class="text-[13px]/5 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Connect a VPS from any provider. Its health, CPU and backups show up here.',
                                    )
                                }}
                            </p>
                        </div>
                        <ConnectMachineButton size="xs" />
                    </div>
                </section>

                <section
                    class="flex flex-col gap-3"
                    aria-labelledby="list-heading"
                >
                    <div class="flex h-6 items-center">
                        <h2
                            id="list-heading"
                            class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                        >
                            {{ $t('Get connected') }}
                        </h2>
                    </div>
                    <ul class="ds-tile ds-tile-rows flex flex-col">
                        <li
                            v-for="option in connectOptions"
                            :key="option.title"
                            class="ds-tile-row"
                        >
                            <a
                                href="#"
                                class="group flex h-11 items-center gap-3 px-4 transition-colors hover:bg-(--ds-row-hover)"
                                :class="focusRing"
                                :aria-haspopup="
                                    option.action ? 'dialog' : undefined
                                "
                                @click="
                                    option.action &&
                                    ($event.preventDefault(), option.action())
                                "
                            >
                                <component
                                    :is="option.icon"
                                    class="size-4 shrink-0 text-(--ds-text-faint)"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <span
                                    class="shrink-0 text-[14px]/5 font-medium"
                                >
                                    {{ option.title }}
                                </span>
                                <span
                                    class="truncate text-[13px]/5 text-(--ds-text-faint)"
                                >
                                    {{ option.description }}
                                </span>
                                <ArrowRight
                                    class="mi-arrow ml-auto size-3.5 shrink-0 text-(--ds-text-faint) transition-colors group-hover:text-(--ds-text)"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                            </a>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
        <McpDialog v-model:open="mcpOpen" />
    </WorkspacePage>
</template>
