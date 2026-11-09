<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowUpRight, Check, Plus, X } from '@lucide/vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ClearDataDialog from '@/components/machines/ClearDataDialog.vue';
import type { DataKind } from '@/components/machines/ClearDataDialog.vue';
import type { Machine } from '@/components/machines/machines';
import PolicySends from '@/components/machines/PolicySends.vue';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import EmbedOptions from '@/components/status/EmbedOptions.vue';
import StatusPill from '@/components/status/StatusPill.vue';
import type { ComponentStatus } from '@/components/status/status';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { usePathTab } from '@/composables/usePathTab';
import { appButtonClasses } from '@/lib/appButton';
import { fieldLabel } from '@/lib/machinePolicies';
import type {
    MachinePolicy,
    PolicyChoice,
    PolicyRule,
} from '@/lib/machinePolicies';
import { onWorkspace } from '@/lib/workspaceRoute';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

type StatusService = {
    key: string;
    name: string;
    kind: string;
    image: string | null;
    status: string;
    shown: boolean;
    /** Shown only while it isn't operational. */
    quiet: boolean;
    label: string | null;
    suggested: string;
};

type StatusMonitor = {
    key: string;
    id: number;
    name: string;
    target: string;
    status: 'pending' | 'up' | 'down';
    shown: boolean;
    quiet: boolean;
    label: string | null;
};

type StatusPageSettings = {
    exists: boolean;
    enabled: boolean;
    public: boolean;
    slug: string;
    title: string | null;
    /** The browser tab's, when not the name. */
    pageTitle: string | null;
    /** Beside the name; the workspace logo when there's none. */
    iconUrl: string | null;
    faviconUrl: string | null;
    description: string | null;
    includeNew: boolean;
    showUptime: boolean;
    notice: string | null;
    noticeKind: 'info' | 'maintenance' | 'incident';
    url: string;
    badgeUrl: string;
    jsonUrl: string;
    /** People who confirmed email updates. */
    subscribers: number;
    services: StatusService[];
    monitors: StatusMonitor[];
};

type CollectedData = {
    logs: { lines: number | null; since: string | null; unavailable: boolean };
    metrics: { readings: number; since: string | null };
    services: { discovered: number; kept: number; scannedAt: string | null };
    /** The latest clear, kept a day. */
    status: {
        state: 'queued' | 'running' | 'done' | 'failed';
        kinds: DataKind[];
        counts: Partial<Record<DataKind, number>>;
        at: string;
    } | null;
};

const props = defineProps<{
    workspace: Workspace;
    machine: Machine;
    canManage: boolean;
    statusPage: StatusPageSettings;
    /** The machine policy it follows, why, and whether it has it yet. */
    policy: {
        current: MachinePolicy;
        from: 'machine' | 'group' | 'rule' | 'default';
        group: { id: number; name: string } | null;
        /** The rule that picked it, when one did. */
        rule: PolicyRule | null;
        /** Its own pick; null follows its group's, else the default. */
        choice: number | null;
        options: PolicyChoice[];
        pending: boolean;
        supported: boolean;
        since: string;
        daemon: {
            version: string | null;
            available: string | null;
            notesUrl: string | null;
            /** Every change between what it runs and the release, in the source. */
            compareUrl: string | null;
            /** The release its policy keeps it on. */
            pinned: string | null;
            online: boolean;
        };
    };
    /** Services and lines whose logs never leave the machine. */
    logSkip: {
        available: boolean;
        since: string;
        rules: {
            services: string[];
            patterns: { service: string | null; pattern: string }[];
        };
    };
    planAllows: boolean;
    environments: string[];
    /** What it has sent, loaded once the page is open. */
    collectedData?: CollectedData;
}>();

const machineUrl = computed(() => `/machines/${props.machine.id}`);

/* Tabs, remembered in the address so a link can open the status page. */

const tab = usePathTab(`${machineUrl.value}/settings`, [
    'general',
    'status',
] as const);

/* Its policy: what it collects and sends, and how it updates. */
const policyBusy = ref(false);
const policyError = ref<string | null>(null);
const updateBusy = ref(false);
const updateError = ref<string | null>(null);
const policiesUrl = computed(
    () => onWorkspace(settingsRoutes.machinePolicies(props.workspace.slug)).url,
);
const defaultName = computed(
    () => props.policy.options.find((option) => option.isDefault)?.name ?? '',
);
/** What following (no pick of its own) means for this machine. */
const inheritLabel = computed(() =>
    props.machine.groupId && props.policy.group
        ? trans('Follow its group, :group', { group: props.policy.group.name })
        : trans('Follow the default, :name', { name: defaultName.value }),
);
const policyFollows = computed(() => {
    const name = props.policy.current.name;
    if (props.policy.from === 'machine') {
        return trans('Follows :name, picked for this machine.', { name });
    }
    if (props.policy.from === 'rule' && props.policy.rule) {
        return trans('Follows :name, because its :field is :value.', {
            name,
            field: fieldLabel(props.policy.rule.field).toLowerCase(),
            value: props.policy.rule.value,
        });
    }
    if (props.policy.from === 'group') {
        return trans('Follows :name, its group :group’s policy.', {
            name,
            group: props.policy.group?.name ?? '',
        });
    }

    return trans('Follows :name, the default policy.', { name });
});

function choosePolicy(value: string): void {
    router.put(
        `${machineUrl.value}/policy`,
        { policy: value === '' ? null : Number(value) },
        {
            preserveScroll: true,
            onStart: () => {
                policyBusy.value = true;
                policyError.value = null;
            },
            onFinish: () => (policyBusy.value = false),
            onError: (bag) => (policyError.value = bag.policy ?? null),
        },
    );
}

function installUpdate(): void {
    router.post(
        `${machineUrl.value}/daemon-update`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                updateBusy.value = true;
                updateError.value = null;
            },
            onFinish: () => (updateBusy.value = false),
            onError: (bag) => (updateError.value = bag.update ?? null),
        },
    );
}

/* Log skip rules: never sent, checked on the machine. */
const skipForm = useForm({
    services: props.logSkip.rules.services.join('\n'),
    patterns: props.logSkip.rules.patterns.map((rule) => ({
        service: rule.service ?? '',
        pattern: rule.pattern,
    })),
});
const skipErrors = computed(
    () => skipForm.errors as Record<string, string | undefined>,
);

function addSkipPattern(): void {
    skipForm.patterns.push({ service: '', pattern: '' });
}

function saveSkip(): void {
    skipForm
        .transform((data) => ({
            services: data.services.split('\n'),
            patterns: data.patterns,
        }))
        .put(`/machines/${props.machine.id}/log-skip`, {
            preserveScroll: true,
            onStart: () => (skipOffer.value = null),
            onFlash: (flash) => {
                skipOffer.value =
                    (flash.logSkipOffer as SkipOffer | undefined) ?? null;
            },
            onSuccess: () => skipForm.defaults(),
        });
}

/*
 * After narrowing what's sent, offer to delete what already went: lines
 * from services that are now skipped. (Turning logs off or masking on is
 * the policy's, which offers the same.) The machine stays connected;
 * nothing is sent to it.
 */
type SkipOffer = {
    services: { service: string; lines: number }[];
    lines: number;
    /** New line patterns: past matches stay, so say so. */
    patterns: boolean;
};
const skipOffer = ref<SkipOffer | null>(null);
const offerBusy = ref(false);
const offerError = ref<string | null>(null);

function deleteSkipped(): void {
    const offer = skipOffer.value;
    if (!offer || offerBusy.value) return;
    router.post(
        `/machines/${props.machine.id}/collected-data/skipped-services`,
        { services: offer.services.map((s) => s.service) },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                offerBusy.value = true;
                offerError.value = null;
            },
            onFinish: () => (offerBusy.value = false),
            onError: (bag) => (offerError.value = bag.services ?? null),
            onSuccess: () => {
                skipOffer.value = null;
                loadCollected();
            },
        },
    );
}

/*
 * Its details. A provider or region typed here stays; left empty,
 * detection fills it in. Policies can apply by any of them.
 */
const machineForm = useForm({
    name: props.machine.name,
    environment: props.machine.environment as string,
    provider: props.machine.providerManual ? props.machine.provider : '',
    region: props.machine.regionManual ? (props.machine.region ?? '') : '',
    tier: props.machine.tier ?? '',
    labels: Object.entries(props.machine.labels ?? {}).map(([key, value]) => ({
        key,
        value,
    })),
});
const detectedProvider = computed(() =>
    props.machine.providerManual
        ? (props.machine.facts?.hosting?.provider ?? null)
        : props.machine.provider,
);
const detectedRegion = computed(
    () => props.machine.facts?.hosting?.region ?? null,
);
const labelErrors = computed(
    () =>
        Object.entries(machineForm.errors).find(([key]) =>
            key.startsWith('labels'),
        )?.[1],
);

function addLabel(): void {
    machineForm.labels.push({ key: '', value: '' });
}

function saveMachine(): void {
    machineForm
        .transform((data) => ({
            ...data,
            labels: data.labels.filter((label) => label.key.trim() !== ''),
        }))
        .patch(machineUrl.value, {
            preserveScroll: true,
            onSuccess: () => machineForm.defaults(),
        });
}

const environmentLabels: Record<string, string> = {
    production: trans('Production'),
    staging: trans('Staging'),
    development: trans('Development'),
};

const statusForm = useForm({
    enabled: props.statusPage.enabled,
    public: props.statusPage.public,
    slug: props.statusPage.slug,
    title: props.statusPage.title ?? '',
    page_title: props.statusPage.pageTitle ?? '',
    description: props.statusPage.description ?? '',
    include_new: props.statusPage.includeNew,
    show_uptime: props.statusPage.showUptime,
    notice: props.statusPage.notice ?? '',
    notice_kind: props.statusPage.noticeKind,
    services: props.statusPage.services.map((service) => ({
        key: service.key,
        shown: service.shown,
        quiet: service.quiet,
        label: service.label ?? '',
    })),
    monitors: props.statusPage.monitors.map((monitor) => ({
        key: monitor.key,
        shown: monitor.shown,
        quiet: monitor.quiet,
        label: monitor.label ?? '',
    })),
});

// The server keeps monitors with the services, keyed monitor:<id>.
statusForm.transform(({ monitors, services, ...data }) => ({
    ...data,
    services: [...services, ...monitors],
}));

const monitorStatus: Record<StatusMonitor['status'], ComponentStatus> = {
    up: 'operational',
    down: 'down',
    pending: 'unknown',
};

const editable = computed(() => props.canManage && props.planAllows);

function saveStatusPage(): void {
    statusForm.put(`${machineUrl.value}/status-page`, {
        preserveScroll: true,
        onSuccess: () => statusForm.defaults(),
    });
}

const live = computed(
    () => props.statusPage.exists && props.statusPage.enabled,
);

const shownCount = computed(
    () => statusForm.services.filter((service) => service.shown).length,
);

function setAll(shown: boolean): void {
    statusForm.services.forEach((service) => (service.shown = shown));
}

/* Off, always on the page, or only while there's a problem */
type Visibility = 'off' | 'on' | 'problems';

const visibilities: { value: Visibility; label: string }[] = [
    { value: 'off', label: trans('Off') },
    { value: 'on', label: trans('On') },
    { value: 'problems', label: trans('Problems') },
];

function visibilityOf(item: { shown: boolean; quiet: boolean }): Visibility {
    return !item.shown ? 'off' : item.quiet ? 'problems' : 'on';
}

function setVisibility(
    item: { shown: boolean; quiet: boolean },
    value: Visibility,
): void {
    item.shown = value !== 'off';
    item.quiet = value === 'problems';
}

const host = computed(() => {
    try {
        return new URL(props.statusPage.url).host;
    } catch {
        return '';
    }
});

const liveUrl = computed(() =>
    props.statusPage.url.replace(/[^/]+$/, statusForm.slug),
);

function tidySlug(): void {
    statusForm.slug = statusForm.slug
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);

function componentStatus(status: string): ComponentStatus {
    if (!online.value) return 'unknown';
    if (status === 'running') return 'operational';
    if (status === 'restarting') return 'degraded';

    return 'down';
}

const copied = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) copied.value = null;
        }, 1600);
    } catch {
        // Clipboard blocked; the text is still selectable.
    }
}

const noticeKinds = [
    { value: 'info', label: trans('Notice') },
    { value: 'maintenance', label: trans('Maintenance') },
    { value: 'incident', label: trans('Incident') },
] as const;

/* Collected data: how much it has sent, and clearing it. */

const dataKinds: DataKind[] = ['logs', 'metrics', 'services'];
const dataLabels = computed<Record<DataKind, string>>(() => ({
    logs: trans('Logs'),
    metrics: trans('Usage history'),
    services: trans('Discovered services'),
}));

/** 980, 14,200, 1.2M. */
function compact(n: number): string {
    return n >= 1_000_000
        ? `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`
        : n.toLocaleString();
}

function days(since: string | null): number {
    return since
        ? Math.max(1, Math.ceil((Date.now() - Date.parse(since)) / 86_400_000))
        : 1;
}

const dataSizes = computed<Record<DataKind, string> | null>(() => {
    const data = props.collectedData;
    if (!data) return null;
    const over = (count: string, since: string | null, words: string) =>
        trans(words, { count, days: String(days(since)) });

    return {
        logs: data.logs.unavailable
            ? trans('Your log database isn’t answering right now')
            : !data.logs.lines
              ? trans('No log lines')
              : over(
                    compact(data.logs.lines),
                    data.logs.since,
                    days(data.logs.since) === 1
                        ? ':count log lines from the last day'
                        : ':count log lines over :days days',
                ),
        metrics: !data.metrics.readings
            ? trans('No usage history')
            : over(
                  compact(data.metrics.readings),
                  data.metrics.since,
                  days(data.metrics.since) === 1
                      ? ':count readings from the last day'
                      : ':count readings over :days days',
              ),
        services:
            (data.services.discovered === 1
                ? trans('1 discovered service')
                : trans(':count discovered services', {
                      count: String(data.services.discovered),
                  })) +
            (data.services.kept
                ? ' · ' +
                  trans(':count adopted or deployed, kept', {
                      count: String(data.services.kept),
                  })
                : ''),
    };
});

function isEmpty(kind: DataKind): boolean {
    const data = props.collectedData;
    if (!data) return true;
    if (kind === 'logs') return !data.logs.unavailable && !data.logs.lines;
    if (kind === 'metrics') return !data.metrics.readings;

    return !data.services.discovered;
}

const clearing = computed(() =>
    ['queued', 'running'].includes(props.collectedData?.status?.state ?? ''),
);

/** How the latest clear went, for an hour after. */
const clearStatus = computed<string | null>(() => {
    const status = props.collectedData?.status;
    if (!status) return null;
    if (status.state === 'queued' || status.state === 'running') {
        return trans(
            'Clearing in the background. You can leave this page; it carries on.',
        );
    }
    if (status.state === 'failed') {
        return trans('The last clear didn’t finish. Try again.');
    }
    if (Date.now() - Date.parse(status.at) > 3_600_000) return null;
    const parts = dataKinds
        .filter((kind) => status.kinds.includes(kind))
        .map((kind) => {
            const count = compact(status.counts[kind] ?? 0);

            return kind === 'logs'
                ? trans(':count log lines', { count })
                : kind === 'metrics'
                  ? trans(':count usage readings', { count })
                  : trans(':count discovered services', { count });
        });

    return trans('Cleared :what. Still connected.', {
        what: parts.join(', '),
    });
});

const clearOpen = ref(false);
const clearKinds = ref<DataKind[]>([]);

function askClear(kinds: DataKind[]): void {
    clearKinds.value = kinds;
    clearOpen.value = true;
}

let collectedTimer: ReturnType<typeof setTimeout> | undefined;

/** Count what it has sent, and keep counting while a clear runs. */
function loadCollected(): void {
    clearTimeout(collectedTimer);
    router.reload({
        only: ['collectedData'],
        onFinish: () => {
            if (clearing.value) {
                collectedTimer = setTimeout(loadCollected, 3000);
            }
        },
    });
}

onMounted(() => {
    if (props.canManage && props.machine.id) loadCollected();
});
onBeforeUnmount(() => clearTimeout(collectedTimer));

const confirmingDelete = ref(false);
const deleteConfirmation = ref('');
const deleting = ref(false);

function deleteMachine(): void {
    router.delete(machineUrl.value, {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });
}

watch(confirmingDelete, (open) => {
    if (!open) deleteConfirmation.value = '';
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const serviceColumns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.2fr)_8rem_auto]';
</script>

<template>
    <Head :title="`${machine.name} settings`" />

    <WorkspacePage
        :title="$t('Settings')"
        :description="
            $t(
                'Rename :name, set its environment and publish a status page for its services.',
                { name: String(machine.name) },
            )
        "
    >
        <template #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Settings')"
                >
                    <button
                        type="button"
                        class="cursor-pointer"
                        :class="focusRing"
                        :aria-pressed="tab === 'general'"
                        @click="tab = 'general'"
                    >
                        {{ ' ' + $t('General') + ' ' }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="tab === 'status'"
                        @click="tab = 'status'"
                    >
                        {{ ' ' + $t('Status page') }}
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                live
                                    ? 'bg-(--ds-green)'
                                    : 'bg-(--ds-text-faint)'
                            "
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </div>
        </template>

        <template #actions>
            <template v-if="tab === 'status'">
                <a
                    v-if="live"
                    :href="statusPage.url"
                    target="_blank"
                    rel="noopener"
                    :class="appButtonClasses('secondary', 'sm')"
                >
                    {{ ' ' + $t('View page') }}
                    <ArrowUpRight class="size-3.5" :stroke-width="1.75" />
                </a>
                <SubmitButton
                    v-if="canManage"
                    form="status-form"
                    :processing="statusForm.processing"
                    :done="statusForm.recentlySuccessful"
                    :disabled="!editable || !statusForm.isDirty"
                />
            </template>
            <SubmitButton
                v-else-if="canManage"
                form="machine-form"
                :processing="machineForm.processing"
                :done="machineForm.recentlySuccessful"
                :disabled="!machineForm.isDirty"
            />
        </template>

        <template v-if="tab === 'general'">
            <form
                id="machine-form"
                class="ds-tile"
                @submit.prevent="saveMachine"
            >
                <div class="ds-tile-rows">
                    <SettingsLine
                        :label="$t('Name')"
                        :hint="$t('What it’s called everywhere in ServerOS.')"
                        input-id="machine-name"
                    >
                        <Input
                            id="machine-name"
                            v-model="machineForm.name"
                            maxlength="255"
                            required
                            class="max-w-md"
                            :disabled="!canManage"
                        />
                        <InputError :message="machineForm.errors.name" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Environment')"
                        :hint="
                            $t('Shown on its badge, and a filter on Machines.')
                        "
                    >
                        <div
                            class="ds-segments w-fit"
                            role="group"
                            :aria-label="$t('Environment')"
                        >
                            <button
                                v-for="environment in environments"
                                :key="environment"
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="
                                    machineForm.environment === environment
                                "
                                :disabled="!canManage"
                                @click="machineForm.environment = environment"
                            >
                                {{
                                    environmentLabels[environment] ??
                                    environment
                                }}
                            </button>
                        </div>
                        <InputError :message="machineForm.errors.environment" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Details')"
                        :hint="
                            $t(
                                'Filter and group Machines by them, and give policies by them. Left empty, provider and region come from what the daemon detects.',
                            )
                        "
                    >
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Provider') }}</span
                                >
                                <Input
                                    v-model="machineForm.provider"
                                    maxlength="60"
                                    :placeholder="
                                        detectedProvider ?? $t('Other')
                                    "
                                    :disabled="!canManage"
                                />
                            </label>
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Region') }}</span
                                >
                                <Input
                                    v-model="machineForm.region"
                                    maxlength="60"
                                    :placeholder="detectedRegion ?? $t('lon1')"
                                    :disabled="!canManage"
                                />
                            </label>
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Tier') }}</span
                                >
                                <Input
                                    v-model="machineForm.tier"
                                    maxlength="60"
                                    :placeholder="$t('performance')"
                                    :disabled="!canManage"
                                />
                            </label>
                        </div>
                        <InputError
                            :message="
                                machineForm.errors.provider ??
                                machineForm.errors.region ??
                                machineForm.errors.tier
                            "
                        />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Labels')"
                        :hint="
                            $t(
                                'Your own, like customer: acme or rack: b2. Scripts can set them when a machine connects.',
                            )
                        "
                    >
                        <div
                            v-for="(label, index) in machineForm.labels"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="label.key"
                                class="w-40 shrink-0"
                                maxlength="40"
                                :placeholder="$t('customer')"
                                :aria-label="$t('Label name')"
                                :disabled="!canManage"
                            />
                            <Input
                                v-model="label.value"
                                class="grow"
                                maxlength="100"
                                :placeholder="$t('acme')"
                                :aria-label="$t('Label value')"
                                :disabled="!canManage"
                            />
                            <AppButton
                                v-if="canManage"
                                size="sm"
                                variant="quiet"
                                :aria-label="$t('Remove')"
                                @click="machineForm.labels.splice(index, 1)"
                            >
                                <X class="size-3.5" />
                            </AppButton>
                        </div>
                        <div v-if="canManage">
                            <AppButton size="sm" @click="addLabel">
                                <Plus class="size-3.5" :stroke-width="2" />
                                {{ $t('Add a label') }}
                            </AppButton>
                        </div>
                        <InputError :message="labelErrors" />
                    </SettingsLine>

                    <SettingsLine :label="$t('Address')">
                        <span
                            class="font-ui-mono text-[13px]/9 text-(--ds-text-secondary)"
                            >{{ machine.ip }}</span
                        >
                    </SettingsLine>

                    <SettingsLine :label="$t('Daemon')">
                        <span
                            class="font-ui-mono text-[13px]/9 text-(--ds-text-secondary)"
                        >
                            {{ machine.daemonVersion ?? $t('Not connected')
                            }}<template v-if="machine.lastSeen">
                                {{
                                    ' ' +
                                    $t('· seen :lastSeen', {
                                        lastSeen: machine.lastSeen,
                                    })
                                }}</template
                            >
                        </span>
                    </SettingsLine>
                </div>
            </form>

            <section
                v-if="machine.id"
                class="ds-tile flex flex-col"
                :aria-label="$t('Policy')"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-4 px-5 py-4"
                >
                    <div class="flex max-w-xl flex-col gap-0.5">
                        <h2 class="text-[13px]/5 font-medium">
                            {{ $t('Policy') }}
                        </h2>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{ policyFollows }}
                        </p>
                        <p
                            v-if="policy.pending"
                            class="text-[13px]/5 text-(--ds-amber)"
                        >
                            {{
                                $t(
                                    'It hasn’t reached the machine yet: it’s sent when the machine is next online.',
                                )
                            }}
                        </p>
                        <p
                            v-if="!policy.supported"
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Its daemon is older than :version, so it can’t turn discovery or logs off at the source, or wait for update approval, yet. It updates itself. Logs the policy turns off are still dropped as they arrive.',
                                    { version: policy.since },
                                )
                            }}
                        </p>
                        <InputError :message="policyError ?? undefined" />
                    </div>
                    <select
                        v-if="canManage"
                        :value="policy.choice ?? ''"
                        class="ds-select ds-raised-button h-9 w-full cursor-pointer appearance-none pr-8! text-[13px] sm:w-60"
                        :aria-label="$t('Policy')"
                        :disabled="policyBusy"
                        @change="
                            choosePolicy(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">{{ inheritLabel }}</option>
                        <option
                            v-for="option in policy.options"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.name }}
                        </option>
                    </select>
                </div>
                <div
                    class="grid gap-5 border-t border-(--ds-divider) px-5 py-4 md:grid-cols-2"
                >
                    <PolicySends :settings="policy.current" />
                    <div class="flex flex-col gap-3 text-[13px]/5">
                        <div class="flex flex-col gap-0.5">
                            <span class="font-medium">{{
                                $t('Daemon updates')
                            }}</span>
                            <span class="text-(--ds-text-muted)">
                                {{
                                    policy.current.updates === 'pinned'
                                        ? $t(
                                              'Stay on :version, even when a newer one is out.',
                                              {
                                                  version:
                                                      policy.daemon.pinned ??
                                                      '',
                                              },
                                          )
                                        : policy.current.updates === 'approve'
                                          ? $t(
                                                'Wait for you: nothing installs until someone approves it.',
                                            )
                                          : $t(
                                                'Install on their own, once nothing is running.',
                                            )
                                }}
                            </span>
                        </div>
                        <div
                            v-if="policy.daemon.available"
                            class="flex flex-col gap-2 rounded-[10px] border border-(--ds-border) bg-(--ds-subtle) px-3.5 py-3"
                        >
                            <span>
                                {{
                                    $t(
                                        policy.daemon.pinned
                                            ? 'Its policy keeps it on :version. This machine runs :current.'
                                            : ':version is available. This machine runs :current.',
                                        {
                                            version: policy.daemon.available,
                                            current:
                                                policy.daemon.version ??
                                                $t('an unknown version'),
                                        },
                                    )
                                }}
                                <a
                                    v-if="policy.daemon.notesUrl"
                                    :href="policy.daemon.notesUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-medium text-(--ds-text) underline decoration-(--ds-border-hover) underline-offset-2"
                                    >{{ $t('What’s in it') }}</a
                                >
                                <template v-if="policy.daemon.compareUrl">
                                    ·
                                    <a
                                        :href="policy.daemon.compareUrl"
                                        target="_blank"
                                        rel="noopener"
                                        class="font-medium text-(--ds-text) underline decoration-(--ds-border-hover) underline-offset-2"
                                        >{{
                                            $t('Every change in the source')
                                        }}</a
                                    >
                                </template>
                            </span>
                            <div
                                v-if="canManage"
                                class="flex items-center gap-2"
                            >
                                <AppButton
                                    size="sm"
                                    :disabled="
                                        updateBusy || !policy.daemon.online
                                    "
                                    @click="installUpdate"
                                >
                                    {{
                                        updateBusy
                                            ? $t('Sending…')
                                            : $t('Install :version', {
                                                  version:
                                                      policy.daemon.available,
                                              })
                                    }}
                                </AppButton>
                                <span
                                    v-if="!policy.daemon.online"
                                    class="text-(--ds-text-muted)"
                                    >{{ $t('Once it’s online') }}</span
                                >
                            </div>
                            <InputError :message="updateError ?? undefined" />
                        </div>
                        <Link
                            :href="policiesUrl"
                            class="mt-auto text-(--ds-text-muted) underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >
                            {{ $t('Edit policies') }}
                        </Link>
                    </div>
                </div>
            </section>

            <form
                v-if="canManage && machine.id"
                class="ds-tile flex flex-col gap-4 px-5 py-4"
                :aria-label="$t('Logs that never leave the machine')"
                @submit.prevent="saveSkip"
            >
                <div class="flex max-w-xl flex-col gap-0.5">
                    <h2 class="text-[13px]/5 font-medium">
                        {{ $t('Logs that never leave the machine') }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'The daemon checks every line against these before sending it. Skipped lines never reach ServerOS, so they aren’t stored, shown or tailed.',
                            )
                        }}
                    </p>
                    <p
                        v-if="!logSkip.available"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Needs daemon :version or newer. It updates itself, or run “sudo serverosd update” on the server.',
                                { version: logSkip.since },
                            )
                        }}
                    </p>
                </div>

                <label class="flex max-w-xl flex-col gap-1.5">
                    <span class="text-[13px]/5 font-medium">{{
                        $t('Services')
                    }}</span>
                    <span class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'One container or unit name a line. Use * for any characters, like ptero-* for every game server.',
                            )
                        }}
                    </span>
                    <textarea
                        v-model="skipForm.services"
                        rows="3"
                        spellcheck="false"
                        placeholder="wings&#10;ptero-*"
                        :disabled="!logSkip.available"
                        class="rounded-md border border-input bg-(--ds-field) px-3 py-2 font-ui-mono text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60"
                    />
                    <InputError :message="skipErrors.services" />
                </label>

                <div class="flex max-w-xl flex-col gap-1.5">
                    <span class="text-[13px]/5 font-medium">{{
                        $t('Lines matching')
                    }}</span>
                    <span class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'A regular expression, for one service (with * as above) or every service.',
                            )
                        }}
                    </span>
                    <div
                        v-for="(rule, i) in skipForm.patterns"
                        :key="i"
                        class="flex flex-col gap-1"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                v-model="rule.service"
                                type="text"
                                spellcheck="false"
                                :aria-label="$t('Service')"
                                :placeholder="$t('Every service')"
                                :disabled="!logSkip.available"
                                class="w-36 shrink-0 rounded-md border border-input bg-(--ds-field) px-3 py-2 text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60"
                            />
                            <input
                                v-model="rule.pattern"
                                type="text"
                                spellcheck="false"
                                :aria-label="$t('Pattern')"
                                placeholder="password=\S+"
                                :disabled="!logSkip.available"
                                class="min-w-0 grow rounded-md border border-input bg-(--ds-field) px-3 py-2 font-ui-mono text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60"
                            />
                            <button
                                type="button"
                                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                                :aria-label="$t('Remove')"
                                :disabled="!logSkip.available"
                                @click="skipForm.patterns.splice(i, 1)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                        <InputError
                            :message="skipErrors[`patterns.${i}.pattern`]"
                        />
                    </div>
                    <InputError :message="skipErrors.patterns" />
                    <div>
                        <AppButton
                            size="sm"
                            :disabled="!logSkip.available"
                            @click="addSkipPattern"
                        >
                            <Plus class="size-3.5" />
                            {{ ' ' + $t('Add pattern') + ' ' }}
                        </AppButton>
                    </div>
                </div>

                <div class="flex justify-end">
                    <SubmitButton
                        :processing="skipForm.processing"
                        :done="skipForm.recentlySuccessful"
                        :disabled="!logSkip.available || !skipForm.isDirty"
                    />
                </div>

                <div
                    v-if="skipOffer"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-(--ds-amber)/8 px-4 py-3 text-[13px]/5 shadow-[inset_2px_0_0_var(--ds-amber)]"
                    role="status"
                >
                    <div class="flex max-w-xl min-w-0 flex-col gap-0.5">
                        <template v-if="skipOffer.services.length">
                            <p class="font-medium tabular-nums">
                                {{
                                    skipOffer.services.length === 1
                                        ? $t(
                                              'This service’s logs are no longer sent. Delete the :count lines it already sent?',
                                              {
                                                  count: compact(
                                                      skipOffer.lines,
                                                  ),
                                              },
                                          )
                                        : $t(
                                              'These services’ logs are no longer sent. Delete the :count lines they already sent?',
                                              {
                                                  count: compact(
                                                      skipOffer.lines,
                                                  ),
                                              },
                                          )
                                }}
                            </p>
                            <p
                                class="font-ui-mono break-words text-(--ds-text-muted)"
                            >
                                {{
                                    skipOffer.services
                                        .map((s) => s.service)
                                        .join(', ')
                                }}
                            </p>
                        </template>
                        <p
                            v-if="skipOffer.patterns"
                            class="text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Lines matching your patterns from before aren’t removed. Clear the machine’s logs to remove everything.',
                                )
                            }}
                        </p>
                        <InputError :message="offerError ?? undefined" />
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <AppButton
                            size="sm"
                            :disabled="offerBusy"
                            @click="skipOffer = null"
                        >
                            {{
                                ' ' +
                                (skipOffer.services.length
                                    ? $t('Keep them')
                                    : $t('OK')) +
                                ' '
                            }}
                        </AppButton>
                        <button
                            v-if="skipOffer.services.length"
                            type="button"
                            :disabled="offerBusy"
                            :class="
                                appButtonClasses(
                                    'secondary',
                                    'sm',
                                    'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10 disabled:opacity-50',
                                )
                            "
                            @click="deleteSkipped"
                        >
                            {{ ' ' + $t('Delete them') + ' ' }}
                        </button>
                    </div>
                </div>
            </form>

            <section
                v-if="canManage && machine.id"
                class="ds-tile flex flex-wrap items-center justify-between gap-4 px-5 py-4"
                :aria-label="$t('Icon')"
            >
                <div class="flex max-w-xl flex-col gap-0.5">
                    <h2 class="text-[13px]/5 font-medium">{{ $t('Icon') }}</h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            ' ' +
                            $t(
                                'Shown next to :name in ServerOS, so it’s easy to spot.',
                                { name: machine.name },
                            ) +
                            ' '
                        }}
                    </p>
                </div>
                <ImageUploadField
                    field="icon"
                    max-size="2 MB"
                    :label="$t('Icon')"
                    hide-label
                    :image-url="machine.iconUrl ?? null"
                    :fallback="machine.name.charAt(0).toUpperCase()"
                    :upload-url="`/machines/${machine.id}/icon`"
                    :remove-url="`/machines/${machine.id}/icon`"
                />
            </section>

            <section
                v-if="canManage && machine.id"
                class="ds-tile"
                :aria-label="$t('Collected data')"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-4 px-5 py-4"
                >
                    <div class="flex max-w-xl flex-col gap-0.5">
                        <h2 class="text-[13px]/5 font-medium">
                            {{ $t('Collected data') }}
                        </h2>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'What :name has sent ServerOS. Clearing it keeps the machine connected, and keeps its settings, adopted services, deploys and backups.',
                                    { name: machine.name },
                                ) +
                                ' '
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="!collectedData || clearing"
                        :class="
                            appButtonClasses(
                                'secondary',
                                'sm',
                                'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10 disabled:opacity-50',
                            )
                        "
                        @click="askClear(dataKinds)"
                    >
                        {{ ' ' + $t('Clear everything collected') + ' ' }}
                    </button>
                </div>
                <div class="ds-tile-rows border-t border-(--ds-border)">
                    <div
                        v-for="kind in dataKinds"
                        :key="kind"
                        class="ds-tile-row flex items-center justify-between gap-4 px-5 py-3"
                    >
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-[13px]/5 font-medium">{{
                                dataLabels[kind]
                            }}</span>
                            <span
                                class="text-[13px]/5 text-(--ds-text-muted) tabular-nums"
                            >
                                {{ dataSizes?.[kind] ?? $t('Counting…') }}
                            </span>
                        </div>
                        <AppButton
                            size="sm"
                            :disabled="clearing || isEmpty(kind)"
                            @click="askClear([kind])"
                        >
                            {{ ' ' + $t('Clear') + ' ' }}
                        </AppButton>
                    </div>
                </div>
                <p
                    v-if="clearStatus"
                    class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
                    role="status"
                >
                    {{ clearStatus }}
                </p>
            </section>

            <section
                v-if="canManage"
                class="ds-tile ds-tile-danger flex flex-wrap items-center justify-between gap-4 px-5 py-4"
                :aria-label="$t('Remove machine')"
            >
                <div class="flex max-w-xl flex-col gap-0.5">
                    <h2 class="text-[13px]/5 font-medium text-(--ds-red)">
                        {{ ' ' + $t('Remove :name', { name: machine.name }) }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            ' ' +
                            $t(
                                'It leaves ServerOS with its deploy history and status page. Nothing on the machine is touched.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>
                <button
                    type="button"
                    :class="
                        appButtonClasses(
                            'secondary',
                            'sm',
                            'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10',
                        )
                    "
                    @click="confirmingDelete = true"
                >
                    {{ ' ' + $t('Remove machine') + ' ' }}
                </button>
            </section>
        </template>

        <form
            v-else
            id="status-form"
            class="flex flex-col gap-5"
            @submit.prevent="saveStatusPage"
        >
            <div
                v-if="!planAllows"
                class="ds-tile flex flex-wrap items-center justify-between gap-3 px-5 py-4"
            >
                <p class="text-[13px]/5 text-(--ds-text-secondary)">
                    {{
                        ' ' +
                        $t('Status pages are part of Pro and Business.') +
                        ' '
                    }}
                </p>
                <Link
                    v-if="upgrade"
                    :href="upgrade.href"
                    :class="appButtonClasses('primary', 'sm')"
                >
                    {{ ' ' + $t('See plans') + ' ' }}
                </Link>
            </div>

            <fieldset
                class="ds-tile"
                :disabled="!editable"
                :class="{ 'opacity-60': !editable }"
            >
                <legend class="sr-only">{{ $t('Status page') }}</legend>
                <div class="ds-tile-rows">
                    <SettingsLine
                        :label="$t('Status page')"
                        :hint="
                            live
                                ? $t('Live at :host/status/:slug.', {
                                      host: String(host),
                                      slug: String(statusPage.slug),
                                  })
                                : $t(
                                      'Off. Nobody can open it until you turn it on.',
                                  )
                        "
                    >
                        <div class="flex h-9 items-center gap-3">
                            <ToggleSwitch
                                v-model="statusForm.enabled"
                                :label="$t('Status page on')"
                            />
                            <span class="text-[13px]/5 text-(--ds-text-muted)">
                                {{ statusForm.enabled ? $t('On') : $t('Off') }}
                            </span>
                        </div>
                        <InputError :message="statusForm.errors.enabled" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Visibility')"
                        :hint="
                            statusForm.public
                                ? $t('Anyone with the link, no sign-in.')
                                : $t(
                                      'Only members of this workspace, signed in.',
                                  )
                        "
                    >
                        <div
                            class="ds-segments w-fit"
                            role="group"
                            :aria-label="$t('Visibility')"
                        >
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="statusForm.public"
                                @click="statusForm.public = true"
                            >
                                {{ ' ' + $t('Public') + ' ' }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="!statusForm.public"
                                @click="statusForm.public = false"
                            >
                                {{ ' ' + $t('Workspace only') + ' ' }}
                            </button>
                        </div>
                    </SettingsLine>

                    <SettingsLine :label="$t('Address')" input-id="status-slug">
                        <div class="flex max-w-xl items-center gap-2">
                            <div
                                class="flex h-9 min-w-0 grow items-center overflow-hidden rounded-md border border-input bg-(--ds-field) focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                            >
                                <span
                                    class="shrink-0 pl-3 font-ui-mono text-[13px] text-(--ds-text-faint)"
                                    >{{ $t(':host/status/', { host }) }}</span
                                >
                                <input
                                    id="status-slug"
                                    v-model="statusForm.slug"
                                    class="h-full min-w-0 grow bg-transparent pr-3 font-ui-mono text-[13px] text-(--ds-text) outline-none"
                                    maxlength="60"
                                    spellcheck="false"
                                    autocomplete="off"
                                    @blur="tidySlug"
                                />
                            </div>
                            <AppButton
                                size="sm"
                                variant="quiet"
                                :aria-label="$t('Copy the address')"
                                @click="copy('url', liveUrl)"
                            >
                                <Check
                                    v-if="copied === 'url'"
                                    class="mi-pop size-3.5 text-(--ds-green)"
                                    :stroke-width="2.25"
                                />
                                <Copy
                                    v-else
                                    class="mi-copy size-3.5"
                                    :stroke-width="1.75"
                                />
                            </AppButton>
                        </div>
                        <InputError :message="statusForm.errors.slug" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Name')"
                        :hint="
                            $t('At the top of the page. Defaults to :name.', {
                                name: String(machine.name),
                            })
                        "
                        input-id="status-title"
                    >
                        <Input
                            id="status-title"
                            v-model="statusForm.title"
                            maxlength="80"
                            class="max-w-md"
                            :placeholder="machine.name"
                        />
                        <InputError :message="statusForm.errors.title" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Page title')"
                        :hint="
                            $t(
                                'In the browser tab and link previews. Defaults to the name.',
                            )
                        "
                        input-id="status-page-title"
                    >
                        <Input
                            id="status-page-title"
                            v-model="statusForm.page_title"
                            maxlength="120"
                            class="max-w-md"
                            :placeholder="statusForm.title || machine.name"
                        />
                        <InputError :message="statusForm.errors.page_title" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Icon')"
                        :hint="
                            $t(
                                'Beside the name. Defaults to the workspace logo.',
                            )
                        "
                    >
                        <ImageUploadField
                            field="icon"
                            :label="$t('Icon')"
                            hide-label
                            preserve-state
                            max-size="2 MB"
                            :image-url="statusPage.iconUrl"
                            :fallback="
                                (statusForm.title || machine.name)
                                    .charAt(0)
                                    .toUpperCase()
                            "
                            :upload-url="`${machineUrl}/status-page/icon`"
                            :remove-url="`${machineUrl}/status-page/icon`"
                            :disabled="!editable"
                        />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Favicon')"
                        :hint="$t('The little picture in the browser tab.')"
                    >
                        <ImageUploadField
                            field="favicon"
                            :label="$t('Favicon')"
                            hide-label
                            preserve-state
                            accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/jpeg,image/webp,image/gif"
                            :hint="
                                $t(
                                    'A square PNG or ICO, up to 1 MB. 64 by 64 or bigger looks sharpest.',
                                )
                            "
                            :image-url="statusPage.faviconUrl"
                            fallback=""
                            :upload-url="`${machineUrl}/status-page/favicon`"
                            :remove-url="`${machineUrl}/status-page/favicon`"
                            :disabled="!editable"
                        />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Description')"
                        :hint="$t('A line under the name.')"
                        input-id="status-description"
                    >
                        <Input
                            id="status-description"
                            v-model="statusForm.description"
                            maxlength="280"
                            class="max-w-xl"
                            :placeholder="
                                $t('Everything behind app.example.com')
                            "
                        />
                        <InputError :message="statusForm.errors.description" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Notice')"
                        :hint="
                            $t(
                                'Shown at the top of the page until you clear it.',
                            )
                        "
                        input-id="status-notice"
                    >
                        <div
                            class="ds-segments w-fit"
                            role="group"
                            :aria-label="$t('Kind of notice')"
                        >
                            <button
                                v-for="kind in noticeKinds"
                                :key="kind.value"
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="
                                    statusForm.notice_kind === kind.value
                                "
                                @click="statusForm.notice_kind = kind.value"
                            >
                                {{ kind.label }}
                            </button>
                        </div>
                        <textarea
                            id="status-notice"
                            v-model="statusForm.notice"
                            rows="2"
                            maxlength="500"
                            placeholder="Database upgrade on Sunday 02:00–02:30 UTC."
                            class="max-w-xl rounded-md border border-input bg-(--ds-field) px-3 py-2 text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <InputError :message="statusForm.errors.notice" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('New services')"
                        :hint="
                            $t(
                                'Anything deployed or monitored later joins the page on its own.',
                            )
                        "
                    >
                        <div class="flex h-9 items-center">
                            <ToggleSwitch
                                v-model="statusForm.include_new"
                                :label="$t('Add new services automatically')"
                            />
                        </div>
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Uptime history')"
                        :hint="
                            $t(
                                '90 days of bars under each service, counted while the page is on.',
                            )
                        "
                    >
                        <div class="flex h-9 items-center">
                            <ToggleSwitch
                                v-model="statusForm.show_uptime"
                                :label="$t('Show uptime history')"
                            />
                        </div>
                    </SettingsLine>

                    <SettingsLine
                        v-if="live && statusPage.public"
                        :label="$t('Subscribers')"
                        :hint="
                            $t(
                                'Visitors can ask for an email when something goes wrong and when it’s fixed, from Get updates on the page.',
                            )
                        "
                    >
                        <p class="text-[13px]/5">
                            {{
                                statusPage.subscribers === 1
                                    ? $t('1 person gets email updates')
                                    : $t(':count people get email updates', {
                                          count: String(statusPage.subscribers),
                                      })
                            }}
                        </p>
                    </SettingsLine>

                    <SettingsLine
                        v-if="live && statusPage.public"
                        :label="$t('Embed')"
                        :hint="
                            $t(
                                'Show it on your own site as a widget, put a live badge in a README, or read the same status as JSON.',
                            )
                        "
                    >
                        <EmbedOptions
                            :url="statusPage.url"
                            :title="statusPage.title || machine.name"
                        />
                    </SettingsLine>
                </div>
            </fieldset>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="page-monitors"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 id="page-monitors" class="font-medium">
                        {{ ' ' + $t('Monitors on the page') }}
                        <span
                            v-if="statusForm.monitors.length"
                            class="ml-1 text-[13px] font-normal text-(--ds-text-faint) tabular-nums"
                            >{{
                                $t(':value of :length', {
                                    value: String(
                                        statusForm.monitors.filter(
                                            (m) => m.shown,
                                        ).length ?? '',
                                    ),
                                    length: String(
                                        statusForm.monitors.length ?? '',
                                    ),
                                })
                            }}</span
                        >
                    </h2>
                    <Link
                        href="/monitors"
                        class="text-[13px]/5 font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                    >
                        {{ ' ' + $t('Manage monitors') + ' ' }}
                    </Link>
                </div>

                <div
                    v-if="statusForm.monitors.length"
                    class="ds-tile overflow-x-auto"
                    role="table"
                    :aria-label="$t('Monitors on the page')"
                >
                    <div
                        class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                        :class="serviceColumns"
                        role="row"
                    >
                        <span role="columnheader">{{ $t('Monitor') }}</span>
                        <span role="columnheader">{{
                            $t('Name on the page')
                        }}</span>
                        <span role="columnheader">{{ $t('Now') }}</span>
                        <span role="columnheader" class="text-right">{{
                            $t('Shown')
                        }}</span>
                    </div>
                    <div
                        v-for="(monitor, index) in statusForm.monitors"
                        :key="monitor.key"
                        class="border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                        :class="serviceColumns"
                        role="row"
                    >
                        <span class="flex min-w-0 flex-col gap-0.5" role="cell">
                            <span
                                class="truncate font-medium"
                                :class="{
                                    'text-(--ds-text-faint)': !monitor.shown,
                                }"
                                >{{ statusPage.monitors[index].name }}</span
                            >
                            <span
                                class="truncate font-ui-mono text-xs text-(--ds-text-muted)"
                                :title="statusPage.monitors[index].target"
                                >{{ statusPage.monitors[index].target }}</span
                            >
                        </span>
                        <span class="hidden md:block" role="cell">
                            <Input
                                v-model="monitor.label"
                                maxlength="60"
                                class="h-8 max-w-xs text-[13px]"
                                :placeholder="statusPage.monitors[index].name"
                                :aria-label="
                                    $t('Name on the page for :value', {
                                        value: String(
                                            statusPage.monitors[index].name,
                                        ),
                                    })
                                "
                                :disabled="!editable || !monitor.shown"
                            />
                        </span>
                        <span class="hidden md:block" role="cell">
                            <StatusPill
                                :status="
                                    monitorStatus[
                                        statusPage.monitors[index].status
                                    ]
                                "
                            />
                        </span>
                        <span class="flex justify-end" role="cell">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="
                                    $t('Show :value', {
                                        value: String(
                                            statusPage.monitors[index].name,
                                        ),
                                    })
                                "
                            >
                                <button
                                    v-for="option in visibilities"
                                    :key="option.value"
                                    type="button"
                                    class="cursor-pointer px-2.5! py-1! text-[12.5px]!"
                                    :class="focusRing"
                                    :aria-pressed="
                                        visibilityOf(monitor) === option.value
                                    "
                                    :title="
                                        option.value === 'problems'
                                            ? $t('Only while it isn’t working')
                                            : undefined
                                    "
                                    :disabled="!editable"
                                    @click="
                                        setVisibility(monitor, option.value)
                                    "
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </span>
                    </div>
                </div>
                <p
                    v-else
                    class="ds-tile p-5 text-[13px]/5 text-pretty text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'Monitors checked from outside show real response times and any downtime here. Add one and set “Runs on” to :name.',
                            { name: machine.name },
                        )
                    }}
                    <Link
                        href="/monitors"
                        class="font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                        >{{ $t('Add a monitor') }}</Link
                    >
                </p>
            </section>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="page-services"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 id="page-services" class="font-medium">
                        {{ ' ' + $t('Services on the page') }}
                        <span
                            class="ml-1 text-[13px] font-normal text-(--ds-text-faint) tabular-nums"
                            >{{
                                $t(':shownCount of :length', {
                                    shownCount: String(shownCount ?? ''),
                                    length: String(
                                        statusForm.services.length ?? '',
                                    ),
                                })
                            }}</span
                        >
                    </h2>
                    <button
                        v-if="editable && statusForm.services.length"
                        type="button"
                        class="cursor-pointer text-[13px]/5 font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                        @click="setAll(shownCount < statusForm.services.length)"
                    >
                        {{
                            shownCount < statusForm.services.length
                                ? $t('Show all')
                                : $t('Hide all')
                        }}
                    </button>
                </div>

                <div
                    v-if="statusForm.services.length"
                    class="ds-tile overflow-x-auto"
                    role="table"
                    :aria-label="$t('Services on the page')"
                >
                    <div
                        class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                        :class="serviceColumns"
                        role="row"
                    >
                        <span role="columnheader">{{ $t('Service') }}</span>
                        <span role="columnheader">{{
                            $t('Name on the page')
                        }}</span>
                        <span role="columnheader">{{ $t('Now') }}</span>
                        <span role="columnheader" class="text-right">{{
                            $t('Shown')
                        }}</span>
                    </div>
                    <div
                        v-for="(service, index) in statusForm.services"
                        :key="service.key"
                        class="border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                        :class="serviceColumns"
                        role="row"
                    >
                        <span
                            class="flex min-w-0 items-center gap-3"
                            role="cell"
                        >
                            <ServiceLogo
                                :name="statusPage.services[index].name"
                                :kind="statusPage.services[index].kind"
                                :service-key="service.key"
                                :image="statusPage.services[index].image"
                            />
                            <span
                                class="truncate font-medium"
                                :class="{
                                    'text-(--ds-text-faint)': !service.shown,
                                }"
                                :title="statusPage.services[index].name"
                                >{{ statusPage.services[index].name }}</span
                            >
                        </span>
                        <span class="hidden md:block" role="cell">
                            <Input
                                v-model="service.label"
                                maxlength="60"
                                class="h-8 max-w-xs text-[13px]"
                                :placeholder="
                                    statusPage.services[index].suggested
                                "
                                :aria-label="
                                    $t('Name on the page for :value', {
                                        value: String(
                                            statusPage.services[index].name,
                                        ),
                                    })
                                "
                                :disabled="!editable || !service.shown"
                            />
                        </span>
                        <span class="hidden md:block" role="cell">
                            <StatusPill
                                :status="
                                    componentStatus(
                                        statusPage.services[index].status,
                                    )
                                "
                            />
                        </span>
                        <span class="flex justify-end" role="cell">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="
                                    $t('Show :value', {
                                        value: String(
                                            statusPage.services[index].name,
                                        ),
                                    })
                                "
                            >
                                <button
                                    v-for="option in visibilities"
                                    :key="option.value"
                                    type="button"
                                    class="cursor-pointer px-2.5! py-1! text-[12.5px]!"
                                    :class="focusRing"
                                    :aria-pressed="
                                        visibilityOf(service) === option.value
                                    "
                                    :title="
                                        option.value === 'problems'
                                            ? $t('Only while it isn’t working')
                                            : undefined
                                    "
                                    :disabled="!editable"
                                    @click="
                                        setVisibility(service, option.value)
                                    "
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </span>
                    </div>
                </div>
                <p
                    v-else
                    class="ds-tile p-5 text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'No services found yet. They appear here after the machine’s next scan.',
                        ) +
                        ' '
                    }}
                </p>
            </section>
        </form>

        <ClearDataDialog
            v-if="machine.id"
            v-model:open="clearOpen"
            :machines="[{ id: machine.id, name: machine.name }]"
            :kinds="clearKinds"
            :sizes="dataSizes ?? undefined"
            @done="loadCollected"
        />

        <Dialog v-model:open="confirmingDelete">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Remove :name?', { name: machine.name })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'It leaves ServerOS with its deploy history, groups and status page.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="deleteMachine"
                >
                    <div class="grid gap-2">
                        <label for="delete-machine" class="text-[13px]/5">
                            {{ ' ' + $t('Type') }}
                            <span class="font-ui-mono text-(--ds-text)">{{
                                machine.name
                            }}</span>
                            {{ $t('to confirm.') + ' ' }}
                        </label>
                        <Input
                            id="delete-machine"
                            v-model="deleteConfirmation"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-ui-mono"
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <AppButton size="sm" @click="confirmingDelete = false">
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <DangerButton
                            :label="$t('Remove machine')"
                            :processing="deleting"
                            :disabled="
                                !deleting && deleteConfirmation !== machine.name
                            "
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
