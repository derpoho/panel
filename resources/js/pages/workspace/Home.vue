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
import SegmentedTabs from '@/components/workspace/SegmentedTabs.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { onWorkspace } from '@/lib/workspaceRoute';
import workspaceRoutes from '@/routes/workspace';
import backupsRoutes from '@/routes/workspace/backups';
import machinesRoutes from '@/routes/workspace/machines';
import membersRoutes from '@/routes/workspace/members';
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
    /** Up to six machines, by name. */
    machines: Machine[];
    memberCount: number;
    /** When the most recent backup ran, e.g. "5 minutes ago". */
    lastBackup: string | null;
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

// Where to get more (serveros.com: Billing), when a package says.
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

const chipTones: Record<Chip['tone'], string> = {
    green: 'bg-(--ds-green)/15 text-(--ds-green)',
    amber: 'bg-(--ds-amber)/15 text-(--ds-amber)',
    red: 'bg-(--ds-red)/15 text-(--ds-red)',
};

const machineAllowance = computed(() => {
    const limit = props.workspace.billing.machineLimit;

    return limit === null
        ? trans('no limit on your plan')
        : trans('of :limit on your plan', { limit: String(limit) });
});

type TileBars =
    | { kind: 'segments'; total: number; lit: number; title: string }
    | { kind: 'history'; values: (number | null)[]; title: string };

/** Up to twelve segments, one each when they fit, otherwise in proportion. */
function segments(lit: number, total: number, title: string): TileBars {
    return total <= 12
        ? { kind: 'segments', total, lit, title }
        : {
              kind: 'segments',
              total: 12,
              lit: Math.round((lit / total) * 12),
              title,
          };
}

const statTiles = computed<
    {
        label: string;
        icon: Component;
        value: string;
        detail: string;
        chip: Chip | null;
        compact?: boolean;
        muted?: boolean;
        /** The little chart at the bottom right: a count of segments, or the last hour. */
        bars?: TileBars;
        /** The whole tile opens this page. */
        href?: string;
    }[]
>(() => {
    const { machines, averageCpu, busy, backedUp, cpuHistory } = props.stats;
    const limit = props.workspace.billing.machineLimit;
    const attention = props.issues.filter(
        (issue) => issue.severity !== 'notice',
    ).length;
    const unprotected = machines - backedUp;

    return [
        {
            label: trans('Machines'),
            icon: Server,
            value: String(machines),
            detail: machineAllowance.value,
            chip: !hasMachines.value
                ? null
                : attention > 0
                  ? {
                        text: `${attention} ${attention === 1 ? trans('issue') : trans('issues')}`,
                        tone: 'amber',
                    }
                  : { text: trans('Healthy'), tone: 'green' },
            bars:
                limit === null || limit < 1
                    ? undefined
                    : segments(
                          Math.min(machines, limit),
                          limit,
                          trans(':count of :limit machines your plan allows', {
                              count: String(machines),
                              limit: String(limit),
                          }),
                      ),
        },
        {
            label: trans('Average CPU'),
            icon: Cpu,
            value: hasMachines.value ? `${averageCpu}%` : '—',
            detail: hasMachines.value
                ? trans(
                      machines === 1
                          ? 'across :count machine'
                          : 'across :count machines',
                      { count: String(machines) },
                  )
                : trans('no machines yet'),
            chip:
                busy > 0
                    ? {
                          text: trans(':count busy', { count: String(busy) }),
                          tone: 'amber',
                      }
                    : null,
            muted: !hasMachines.value,
            bars: hasMachines.value
                ? {
                      kind: 'history',
                      values: cpuHistory,
                      title: trans('Average CPU over the last hour'),
                  }
                : undefined,
        },
        {
            label: trans('Last backup'),
            icon: Archive,
            value: props.lastBackup ?? trans('None yet'),
            detail: hasMachines.value
                ? trans(':backedUp of :machines covered', {
                      backedUp: String(backedUp),
                      machines: String(machines),
                  })
                : trans('nothing to back up'),
            chip:
                hasMachines.value && unprotected > 0
                    ? {
                          text: trans(':count unprotected', {
                              count: String(unprotected),
                          }),
                          tone: 'red',
                      }
                    : null,
            compact: true,
            muted: props.lastBackup === null,
            href: onWorkspace(backupsRoutes.index(props.workspace.slug)).url,
            bars: hasMachines.value
                ? segments(
                      backedUp,
                      machines,
                      trans(
                          ':count of :total machines backed up in the last day',
                          {
                              count: String(backedUp),
                              total: String(machines),
                          },
                      ),
                  )
                : undefined,
        },
    ];
});

/* The CPU tile's last hour: each bar a track filled against the hour's own
   peak (so a quiet hour still shows its shape), the latest strongest,
   amber once machines are busy, and an empty track where nothing reported. */
const BUSY_CPU = 80;

function historyHeight(value: number, values: (number | null)[]): number {
    const peak = Math.max(10, ...values.map((v) => v ?? 0));

    return Math.max(15, Math.round((value / peak) * 100));
}

function historyTone(value: number, index: number, count: number): string {
    if (value >= BUSY_CPU) return 'bg-(--ds-amber)';

    return index === count - 1 ? 'bg-(--ds-green)' : 'bg-(--ds-green)/55';
}

const environments = computed(() => {
    const { production, staging, development } = props.stats.environments;

    return [
        { label: trans('Production'), count: production },
        { label: trans('Staging'), count: staging },
        { label: trans('Development'), count: development },
    ];
});

const topProviders = computed(() => props.providers.slice(0, 4));

/** One quiet line under the Machines title: where they run and what's on them. */
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

/** The first few machines, by name. */
const machineList = computed(() => ({
    title: trans('Machines'),
    rows: props.machines.slice(0, 4).map((machine) => ({
        machine,
        text: trans('Backed up :lastBackup', {
            lastBackup: String(machine.lastBackup),
        }),
        tone: 'text-(--ds-text-muted)',
    })),
}));

const issuesOpen = ref(false);
const shownIssues = computed(() =>
    issuesOpen.value ? props.issues : props.issues.slice(0, 4),
);
const expandedIssue = ref<string | null>(null);

/* Marking items as read hides them for the whole workspace until they
 * happen again. Viewers can't, since it would hide them for everyone. */
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

const machinesHref = computed(() =>
    onWorkspace(machinesRoutes.index(props.workspace.slug)),
);

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

/** The setup guide shows until there's a machine, unless it was skipped. */
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
    /** Opens something on the page instead of following the link. */
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

        <div class="flex flex-col gap-5">
            <!-- The agent's fixes, waiting for someone to approve them -->
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

            <ul class="grid gap-3 md:grid-cols-3" :aria-label="$t('Status')">
                <li
                    v-for="tile in statTiles"
                    :key="tile.label"
                    class="ds-tile relative flex min-h-30 min-w-0 flex-col gap-4 px-4 py-3.5"
                    :class="{
                        'transition-colors hover:bg-(--ds-row-hover)':
                            tile.href,
                    }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h2
                            class="text-[13.5px]/5 font-medium text-(--ds-text-muted)"
                        >
                            <Link
                                v-if="tile.href"
                                :href="tile.href"
                                class="after:absolute after:inset-0 after:rounded-[inherit] focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-(--ds-ring)"
                                >{{ tile.label }}</Link
                            >
                            <template v-else>{{ tile.label }}</template>
                        </h2>
                        <span class="text-(--ds-text-faint)" aria-hidden="true">
                            <component
                                :is="tile.icon"
                                class="size-4"
                                :stroke-width="1.75"
                            />
                        </span>
                    </div>
                    <div class="flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="truncate font-semibold tracking-[-0.035em] tabular-nums"
                                :class="[
                                    tile.compact
                                        ? 'text-[22px]/8'
                                        : 'text-[28px]/8',
                                    tile.muted
                                        ? 'text-(--ds-text-muted)'
                                        : 'text-(--ds-text)',
                                ]"
                            >
                                {{ tile.value }}
                            </p>
                            <p
                                class="mt-1 truncate text-[13px]/5 text-(--ds-text-faint)"
                            >
                                {{ tile.detail }}
                            </p>
                        </div>
                        <div
                            v-if="tile.bars"
                            class="relative z-10 hidden h-7 shrink-0 items-end gap-1 pb-1 sm:flex"
                            :title="tile.bars.title"
                            :aria-label="tile.bars.title"
                            role="img"
                        >
                            <template v-if="tile.bars.kind === 'history'">
                                <span
                                    v-for="(value, index) in tile.bars.values"
                                    :key="index"
                                    class="relative h-5 w-1.5 overflow-hidden rounded-sm bg-(--ds-border-strong)"
                                >
                                    <span
                                        v-if="value !== null"
                                        class="absolute inset-x-0 bottom-0 rounded-sm"
                                        :class="
                                            historyTone(
                                                value,
                                                index,
                                                tile.bars.values.length,
                                            )
                                        "
                                        :style="{
                                            height: `${historyHeight(value, tile.bars.values)}%`,
                                        }"
                                    />
                                </span>
                            </template>
                            <template v-else>
                                <span
                                    v-for="segment in tile.bars.total"
                                    :key="segment"
                                    class="h-5 w-1.5 rounded-sm"
                                    :class="
                                        segment <= tile.bars.lit
                                            ? 'bg-(--ds-green)'
                                            : 'bg-(--ds-border-strong)'
                                    "
                                />
                            </template>
                        </div>
                    </div>
                    <span
                        v-if="tile.chip"
                        class="w-fit rounded-full px-2 py-0.5 text-xs/4 font-semibold tabular-nums"
                        :class="chipTones[tile.chip.tone]"
                        >{{ tile.chip.text }}</span
                    >
                </li>
            </ul>

            <!-- Machines: a title outside, then one quiet row per machine -->
            <section
                v-if="hasMachines"
                class="flex flex-col gap-3"
                aria-labelledby="list-heading"
            >
                <div class="flex items-end justify-between gap-4">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <h2
                            id="list-heading"
                            class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                        >
                            {{ machineList.title }}
                        </h2>
                        <p
                            class="truncate text-[13px]/5 text-(--ds-text-muted) tabular-nums"
                        >
                            <template
                                v-for="(fact, index) in fleetFacts"
                                :key="fact"
                            >
                                <span v-if="index > 0" aria-hidden="true">
                                    ·
                                </span>
                                {{ fact }}
                            </template>
                        </p>
                    </div>
                    <Link
                        :href="machinesHref.url"
                        class="group flex shrink-0 items-center gap-1 rounded-md text-[13px]/5 font-medium text-(--ds-text-muted) hover:text-(--ds-text)"
                        :class="focusRing"
                    >
                        {{ $t('All machines') }}
                        <ArrowRight
                            class="mi-arrow size-3.5"
                            :stroke-width="1.75"
                        />
                    </Link>
                </div>

                <ul class="ds-tile ds-tile-rows flex flex-col">
                    <li
                        v-for="row in machineList.rows"
                        :key="row.machine.id ?? row.machine.name"
                        class="ds-tile-row"
                    >
                        <component
                            :is="row.machine.id ? Link : 'div'"
                            :href="
                                row.machine.id
                                    ? `/machines/${row.machine.id}`
                                    : undefined
                            "
                            class="flex h-11 items-center gap-3 px-4 text-[14px]/5 transition-colors"
                            :class="
                                row.machine.id && [
                                    'hover:bg-(--ds-row-hover)',
                                    focusRing,
                                ]
                            "
                        >
                            <!-- The provider's logo where we know it, else a server -->
                            <IconTip
                                v-if="providerBrand(row.machine.provider)"
                                :label="row.machine.provider ?? ''"
                            >
                                <span class="flex size-4 shrink-0 items-center">
                                    <ProviderName
                                        :name="row.machine.provider"
                                        logo-only
                                    />
                                </span>
                            </IconTip>
                            <Server
                                v-else
                                class="size-4 shrink-0 text-(--ds-text-faint)"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 truncate font-medium">
                                {{ row.machine.name }}
                            </span>
                            <span
                                class="hidden min-w-0 items-center gap-1.5 truncate text-[13px] text-(--ds-text-faint) md:flex"
                            >
                                <ProviderName
                                    v-if="!providerBrand(row.machine.provider)"
                                    :name="row.machine.provider"
                                />
                                <template v-if="row.machine.ip">
                                    <span
                                        v-if="
                                            !providerBrand(row.machine.provider)
                                        "
                                        aria-hidden="true"
                                        >·</span
                                    >
                                    <span class="tabular-nums">{{
                                        row.machine.ip
                                    }}</span>
                                </template>
                            </span>
                            <span
                                class="ml-auto hidden shrink-0 items-center gap-4 text-[13px] text-(--ds-text-muted) tabular-nums sm:flex"
                            >
                                <span :title="$t('CPU')">
                                    {{ row.machine.cpu }}% {{ $t('CPU') }}
                                </span>
                                <span>
                                    {{ row.machine.services }}
                                    {{
                                        row.machine.services === 1
                                            ? $t('service')
                                            : $t('services')
                                    }}
                                </span>
                                <span class="hidden lg:inline">{{
                                    row.machine.memory
                                }}</span>
                                <span
                                    class="hidden w-32 text-right lg:inline"
                                    :class="row.tone"
                                    >{{ row.text }}</span
                                >
                            </span>
                            <InstallingBadge
                                v-if="row.machine.installing"
                                class="max-sm:ml-auto"
                                :cycle="false"
                                themed
                            />
                            <EnvironmentBadge
                                v-else
                                class="max-sm:ml-auto"
                                :environment="row.machine.environment"
                                themed
                            />
                        </component>
                    </li>
                </ul>
            </section>

            <!-- No machines yet: setup steps and ways to connect, as rows -->
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

                    <!-- Setup guide: until the first machine, unless skipped -->
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

                    <!-- Empty: no machines and the guide is skipped -->
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
