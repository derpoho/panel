<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import PolicySends from '@/components/machines/PolicySends.vue';
import ShellCommand from '@/components/workspace/ShellCommand.vue';
import SecurityScan from '@/components/machines/SecurityScan.vue';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpRight, Check, Plus } from '@lucide/vue';
import {
    SolarLock as Lock,
    SolarCopy as Copy,
    SolarRefreshCw as RefreshCw,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from '@/lib/toast';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import MorphHeight from '@/components/workspace/MorphHeight.vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import UpgradeDialog from '@/components/workspace/UpgradeDialog.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import {
    policyBaseline,
    policySettings,
    policySummary,
} from '@/lib/machinePolicies';
import type { MachinePolicy } from '@/lib/machinePolicies';
import type { LockedPlan } from '@/lib/planLocks';
import type { AppButtonSize } from '@/lib/appButton';
import { onWorkspace } from '@/lib/workspaceRoute';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace, WorkspaceAccess } from '@/types';

type Props = { size?: AppButtonSize };
const { size = 'md' } = defineProps<Props>();

// Two roots (the button and its dialog): classes go on the button.
defineOptions({ inheritAttrs: false });
const page = usePage<{
    workspace: Workspace;
    workspaceAccess: WorkspaceAccess | null;
}>();
const billing = computed(() => page.props.workspace.billing);
// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const upgradeLabel = computed(() => {
    if (billing.value.canAddMachine) return null;
    // No plan raises a self-hosted install's own limit: the dialog says so.
    if (billing.value.selfHosted) return null;
    return billing.value.plan === null
        ? trans('Choose a plan')
        : trans('Upgrade to connect more');
});

/** The plan with room for more machines, when this one is full. */
const nextPlan = computed<LockedPlan | null>(() => {
    if (billing.value.onTrial) return null;
    if (billing.value.plan === 'starter') return 'pro';
    if (billing.value.plan === 'pro') return 'business';
    return null;
});
const upgrading = ref(false);

const open = ref(false);
const loading = ref(false);
const error = ref('');
const command = ref('');
const enrolmentId = ref<number | null>(null);
const state = ref<
    'waiting' | 'expired' | 'enrolled' | 'connected' | 'discovered'
>('waiting');
const machineName = ref<string | null>(null);
const machineId = ref<number | null>(null);
const serviceCount = ref(0);
const listenerCount = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

/*
 * Simple: the command straight away, and the machine follows the default
 * policy (or its group's). Advanced: pick the policy and group first, so
 * it joins with them from its first start.
 */
type Options = {
    defaultPolicyId: number;
    policies: MachinePolicy[];
    groups: { id: number; name: string; policyId: number | null }[];
};
const mode = ref<'simple' | 'advanced'>('simple');
/** A command was made since the dialog opened. */
const madeHere = ref(false);
const options = ref<Options | null>(null);
const policyId = ref<number | null>(null);
const groupId = ref<number | null>(null);

async function loadOptions(force = false): Promise<void> {
    if (options.value && !force) return;
    try {
        const response = await fetch('/machines/connect/options', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) return;
        options.value = (await response.json()) as Options;
        policyId.value ??= options.value.defaultPolicyId;
    } catch {
        // Advanced waits for them; Simple works without.
    }
}

/*
 * New policy, new group: picked from the bottom of their menus, the
 * dialog turns into a short form, then back with the new one chosen.
 */
const view = ref<'pick' | 'policy' | 'group'>('pick');
const saving = ref(false);
const formError = ref('');
const newPolicy = ref({
    name: '',
    discovery: true,
    logs: true,
    security_watch: true,
});
const newGroup = ref<{ name: string; policy: number | null }>({
    name: '',
    policy: null,
});

const policyOptions = computed(() =>
    (options.value?.policies ?? []).map((policy) => ({
        value: policy.id as number | null,
        label: policy.name,
        // "Default" beside a policy called Default says nothing.
        hint:
            policy.isDefault && policy.name !== trans('Default')
                ? trans('Default')
                : undefined,
    })),
);
const groupOptions = computed(() => [
    { value: null as number | null, label: trans('No group') },
    ...(options.value?.groups ?? []).map((group) => ({
        value: group.id as number | null,
        label: group.name,
        hint: options.value?.policies.find((p) => p.id === group.policyId)
            ?.name,
    })),
]);

function startPolicy(): void {
    const base = chosenPolicy.value ?? policyBaseline;
    newPolicy.value = {
        name: '',
        discovery: base.discovery,
        logs: base.logs,
        security_watch: base.security_watch,
    };
    formError.value = '';
    view.value = 'policy';
}

function startGroup(): void {
    newGroup.value = { name: '', policy: policyId.value };
    formError.value = '';
    view.value = 'group';
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/** Posts the form, then reloads the choices and picks the new one by name. */
async function create(
    url: string,
    body: Record<string, unknown>,
    pick: (fresh: Options, name: string) => void,
): Promise<void> {
    saving.value = true;
    formError.value = '';
    try {
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
        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            formError.value =
                Object.values(
                    (result.errors ?? {}) as Record<string, string[]>,
                )[0]?.[0] ??
                result.message ??
                trans('Something went wrong. Try again.');
            return;
        }
        await loadOptions(true);
        if (options.value) pick(options.value, String(body.name).trim());
        view.value = 'pick';
    } catch {
        formError.value = trans('Something went wrong. Try again.');
    } finally {
        saving.value = false;
    }
}

function savePolicy(): Promise<void> {
    const { name, ...picks } = newPolicy.value;

    return create(
        '/settings/machine-policies',
        {
            ...policySettings(chosenPolicy.value ?? policyBaseline),
            ...picks,
            name,
            applies_to: [],
        },
        (fresh, created) => {
            policyId.value =
                fresh.policies.find((p) => p.name === created)?.id ??
                policyId.value;
        },
    );
}

function saveGroup(): Promise<void> {
    return create(
        '/machine-groups',
        {
            name: newGroup.value.name,
            machines: [],
            policy: newGroup.value.policy,
        },
        (fresh, created) => {
            groupId.value =
                fresh.groups.find((g) => g.name === created)?.id ??
                groupId.value;
        },
    );
}

watch(open, (value) => {
    if (!value) view.value = 'pick';
});

const defaultPolicy = computed(
    () =>
        options.value?.policies.find(
            (p) => p.id === options.value?.defaultPolicyId,
        ) ?? null,
);
const chosenPolicy = computed(
    () => options.value?.policies.find((p) => p.id === policyId.value) ?? null,
);
/** What the machine follows with no pick of its own: its group's, else the default. */
const inherited = computed(() => {
    const group = options.value?.groups.find((g) => g.id === groupId.value);

    return group?.policyId ?? options.value?.defaultPolicyId ?? null;
});

// Joining a group picks its policy, which can still be changed.
watch(groupId, () => {
    if (inherited.value !== null) policyId.value = inherited.value;
    command.value = '';
    stopPolling();
});
watch(policyId, () => {
    if (mode.value === 'advanced') {
        command.value = '';
        stopPolling();
    }
});
watch(mode, (value) => {
    view.value = 'pick';
    stopPolling();
    command.value = '';
    error.value = '';
    if (value === 'advanced') void loadOptions();
    else void makeCommand();
});

function stopPolling(): void {
    if (timer) clearInterval(timer);
    timer = null;
}

watch(open, (value) => {
    if (!value) {
        stopPolling();
        command.value = '';
        enrolmentId.value = null;
        mode.value = 'simple';
        madeHere.value = false;
        groupId.value = null;
        policyId.value = options.value?.defaultPolicyId ?? null;
    } else {
        void loadOptions();
    }
});
onBeforeUnmount(stopPolling);

async function poll(): Promise<void> {
    if (!open.value || enrolmentId.value === null) return;
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
        state.value = result.state;
        machineName.value = result.machineName;
        machineId.value = result.machineId ?? null;
        serviceCount.value = result.services;
        listenerCount.value = result.listeners;
        if (result.state === 'expired') stopPolling();
        // Once enrolled, the setup carries on in the machine's row.
        if (['enrolled', 'connected', 'discovered'].includes(result.state)) {
            stopPolling();
            open.value = false;
            toast.success(
                trans(':name is connecting. Follow its setup in Machines.', {
                    name: result.machineName ?? trans('Your machine'),
                }),
            );
            router.reload({ only: ['machines', 'workspace', 'stats'] });
        }
    } catch {
        // A temporary network failure should not end polling.
    }
}

function createCommand(): Promise<void> {
    open.value = true;

    return makeCommand();
}

async function makeCommand(): Promise<void> {
    loading.value = true;
    error.value = '';
    state.value = 'waiting';
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
            // A new command made in this dialog (another policy picked)
            // replaces the one before it, so they don't hold two of the
            // plan's places. Commands made elsewhere keep working.
            body: JSON.stringify(
                mode.value === 'advanced'
                    ? {
                          replace: madeHere.value,
                          // Only a pick of its own: the same as it'd follow
                          // anyway leaves it following.
                          policy:
                              policyId.value !== inherited.value
                                  ? policyId.value
                                  : null,
                          group: groupId.value,
                      }
                    : { replace: madeHere.value },
            ),
        });
        const result = await response.json();
        if (!response.ok)
            throw new Error(
                result.error ?? trans('Could not create an install command.'),
            );
        command.value = result.command;
        enrolmentId.value = result.enrolmentId;
        madeHere.value = true;
        timer = setInterval(poll, 4000);
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : trans('Could not create an install command.');
    } finally {
        loading.value = false;
    }
}

async function copyCommand(): Promise<void> {
    try {
        await navigator.clipboard.writeText(command.value);
        toast.success(trans('Install command copied'));
    } catch {
        toast.error(
            trans('Could not copy. Select the command and copy it manually.'),
        );
    }
}

/** Lets pages open the install flow from their own links. */
defineExpose({ connect: createCommand });
</script>

<template>
    <!-- At the plan's machine limit: ask to move up a plan. -->
    <template v-if="upgradeLabel && canManage && nextPlan">
        <AppButton
            v-bind="$attrs"
            variant="primary"
            :size="size"
            :title="upgradeLabel"
            @click="upgrading = true"
        >
            <Lock class="size-3.5" :stroke-width="2" />
            <slot>{{ $t('Connect machine') }}</slot>
        </AppButton>
        <UpgradeDialog
            v-model:open="upgrading"
            feature="More machines"
            :plan="nextPlan"
        />
    </template>
    <AppButton
        v-else-if="upgradeLabel && canManage && upgrade"
        v-bind="$attrs"
        :size="size"
        :href="upgrade.href"
    >
        {{ upgradeLabel }} <ArrowUpRight class="size-3.5" :stroke-width="1.8" />
    </AppButton>
    <!-- At the limit with nowhere to get more, there's nothing to press. -->
    <AppButton
        v-else-if="canManage && !upgradeLabel"
        v-bind="$attrs"
        variant="primary"
        :size="size"
        @click="createCommand"
    >
        <Plus class="mi-plus size-3.5" :stroke-width="2" />
        <slot>{{ $t('Connect machine') }}</slot>
    </AppButton>

    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-form ds-modal font-ui text-foreground sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Connect a machine') }}</DialogTitle>
                <DialogDescription>
                    {{
                        ' ' +
                        $t(
                            'Run this on a Linux machine you manage. It installs the ServerOS daemon, which connects out over HTTPS and scans running services and listening ports without changing them.',
                        ) +
                        ' '
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                class="ds-segments w-full!"
                role="group"
                :aria-label="$t('How to connect')"
            >
                <button
                    type="button"
                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                    :aria-pressed="mode === 'simple'"
                    @click="mode = 'simple'"
                >
                    {{ $t('Simple') }}
                </button>
                <button
                    type="button"
                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                    :aria-pressed="mode === 'advanced'"
                    @click="mode = 'advanced'"
                >
                    {{ $t('Advanced') }}
                </button>
            </div>

            <!-- Advanced: the policy and group it joins with, before the command. -->
            <div
                v-if="mode === 'advanced' && !command && !loading && !error"
                class="flex flex-col gap-4"
            >
                <p
                    v-if="!options"
                    class="flex items-center gap-2 text-sm text-(--ds-text-secondary)"
                >
                    <RefreshCw class="size-4 animate-spin" />
                    {{ $t('Loading your policies…') }}
                </p>
                <MorphHeight v-else>
                    <Transition name="morph" mode="out-in">
                        <!-- Pick the policy and group -->
                        <div
                            v-if="view === 'pick'"
                            key="pick"
                            class="flex flex-col gap-4"
                        >
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        for="connect-policy"
                                        class="text-[12.5px]/4 text-(--ds-text-muted)"
                                        >{{ $t('Policy') }}</label
                                    >
                                    <PickerMenu
                                        id="connect-policy"
                                        v-model="policyId"
                                        :options="policyOptions"
                                        :create-label="$t('New policy…')"
                                        @create="startPolicy"
                                    />
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        for="connect-group"
                                        class="text-[12.5px]/4 text-(--ds-text-muted)"
                                        >{{ $t('Group') }}</label
                                    >
                                    <PickerMenu
                                        id="connect-group"
                                        v-model="groupId"
                                        :options="groupOptions"
                                        :create-label="$t('New group…')"
                                        @create="startGroup"
                                    />
                                </div>
                            </div>
                            <PolicySends
                                v-if="chosenPolicy"
                                :settings="chosenPolicy"
                            />
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <a
                                    :href="
                                        onWorkspace(
                                            settingsRoutes.machinePolicies(
                                                page.props.workspace.slug,
                                            ),
                                        ).url
                                    "
                                    class="rounded-sm text-[13px]/5 text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                    >{{ $t('Manage policies') }}</a
                                >
                                <AppButton
                                    size="sm"
                                    variant="primary"
                                    @click="makeCommand"
                                >
                                    {{ $t('Get the install command') }}
                                </AppButton>
                            </div>
                        </div>

                        <!-- A new policy: a name and what it sends -->
                        <form
                            v-else-if="view === 'policy'"
                            key="policy"
                            class="flex flex-col gap-4"
                            @submit.prevent="savePolicy"
                        >
                            <button
                                type="button"
                                class="flex w-fit cursor-pointer items-center gap-1.5 rounded-sm text-[13px]/5 text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                @click="view = 'pick'"
                            >
                                <ArrowLeft
                                    class="size-3.5"
                                    :stroke-width="1.75"
                                />
                                {{ $t('New policy') }}
                            </button>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    for="new-policy-name"
                                    class="text-[12.5px]/4 text-(--ds-text-muted)"
                                    >{{ $t('Name') }}</label
                                >
                                <input
                                    id="new-policy-name"
                                    v-model="newPolicy.name"
                                    type="text"
                                    maxlength="60"
                                    required
                                    autofocus
                                    :placeholder="$t('e.g. Production')"
                                    class="h-9 w-full rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-3 text-[13.5px]/5 outline-none placeholder:text-(--ds-text-faint) hover:border-(--ds-border-hover) focus:border-(--ds-border-focus) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
                                />
                            </div>
                            <div class="ds-tile ds-tile-rows flex flex-col">
                                <label
                                    v-for="[key, title, body] in [
                                        [
                                            'discovery',
                                            $t('Services and ports'),
                                            $t(
                                                'What runs, whether it’s up, and the ports it listens on',
                                            ),
                                        ],
                                        [
                                            'logs',
                                            $t('Logs'),
                                            $t('Service logs, as they are'),
                                        ],
                                        [
                                            'security_watch',
                                            $t('Security checks'),
                                            $t(
                                                'SSH settings, failed sign-ins and whether security updates are on',
                                            ),
                                        ],
                                    ] as const"
                                    :key="key"
                                    class="ds-tile-row flex cursor-pointer items-center gap-4 px-4 py-2.5"
                                >
                                    <span class="flex min-w-0 grow flex-col">
                                        <span
                                            class="text-[13.5px]/5 font-medium"
                                            >{{ title }}</span
                                        >
                                        <span
                                            class="text-[12.5px]/4.5 text-(--ds-text-muted)"
                                            >{{ body }}</span
                                        >
                                    </span>
                                    <ToggleSwitch
                                        v-model="newPolicy[key]"
                                        :label="title"
                                    />
                                </label>
                            </div>
                            <p class="text-[12.5px]/4 text-(--ds-text-faint)">
                                {{
                                    $t(
                                        'Health is always sent. Updates and the rest copy :name; change them in Manage policies.',
                                        {
                                            name:
                                                chosenPolicy?.name ??
                                                $t('the default'),
                                        },
                                    )
                                }}
                            </p>
                            <p
                                v-if="formError"
                                role="alert"
                                class="text-[13px]/5 text-(--ds-red)"
                            >
                                {{ formError }}
                            </p>
                            <div class="flex justify-end gap-2">
                                <AppButton
                                    size="sm"
                                    type="button"
                                    @click="view = 'pick'"
                                >
                                    {{ $t('Cancel') }}
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="primary"
                                    type="submit"
                                    :disabled="saving || !newPolicy.name.trim()"
                                >
                                    {{ $t('Create policy') }}
                                </AppButton>
                            </div>
                        </form>

                        <!-- A new group: a name and the policy it follows -->
                        <form
                            v-else
                            key="group"
                            class="flex flex-col gap-4"
                            @submit.prevent="saveGroup"
                        >
                            <button
                                type="button"
                                class="flex w-fit cursor-pointer items-center gap-1.5 rounded-sm text-[13px]/5 text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                @click="view = 'pick'"
                            >
                                <ArrowLeft
                                    class="size-3.5"
                                    :stroke-width="1.75"
                                />
                                {{ $t('New group') }}
                            </button>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        for="new-group-name"
                                        class="text-[12.5px]/4 text-(--ds-text-muted)"
                                        >{{ $t('Name') }}</label
                                    >
                                    <input
                                        id="new-group-name"
                                        v-model="newGroup.name"
                                        type="text"
                                        maxlength="60"
                                        required
                                        autofocus
                                        :placeholder="$t('e.g. Web servers')"
                                        class="h-9 w-full rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-3 text-[13.5px]/5 outline-none placeholder:text-(--ds-text-faint) hover:border-(--ds-border-hover) focus:border-(--ds-border-focus) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
                                    />
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        for="new-group-policy"
                                        class="text-[12.5px]/4 text-(--ds-text-muted)"
                                        >{{ $t('Its machines follow') }}</label
                                    >
                                    <PickerMenu
                                        id="new-group-policy"
                                        v-model="newGroup.policy"
                                        :options="policyOptions"
                                    />
                                </div>
                            </div>
                            <p
                                v-if="formError"
                                role="alert"
                                class="text-[13px]/5 text-(--ds-red)"
                            >
                                {{ formError }}
                            </p>
                            <div class="flex justify-end gap-2">
                                <AppButton
                                    size="sm"
                                    type="button"
                                    @click="view = 'pick'"
                                >
                                    {{ $t('Cancel') }}
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="primary"
                                    type="submit"
                                    :disabled="saving || !newGroup.name.trim()"
                                >
                                    {{ $t('Create group') }}
                                </AppButton>
                            </div>
                        </form>
                    </Transition>
                </MorphHeight>
            </div>

            <div
                v-else-if="loading"
                class="flex items-center gap-2 text-sm text-(--ds-text-secondary)"
            >
                <RefreshCw class="size-4 animate-spin" />
                {{ $t('Preparing a one-time install command…') + ' ' }}
            </div>
            <div v-else-if="error" class="space-y-3">
                <p role="alert" class="text-sm text-(--ds-red)">{{ error }}</p>
                <AppButton size="sm" @click="createCommand">{{
                    $t('Try again')
                }}</AppButton>
            </div>
            <div v-else-if="command" class="space-y-4">
                <div
                    class="rounded-[10px] border border-(--ds-border) bg-(--ds-code) px-4 py-3"
                >
                    <ShellCommand :command="command" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <AppButton size="sm" variant="primary" @click="copyCommand"
                        ><Copy class="mi-copy size-3.5" />
                        {{ $t('Copy command') }}</AppButton
                    >
                    <span class="text-xs text-(--ds-text-muted)">{{
                        $t('One use · expires in 15 minutes')
                    }}</span>
                </div>
                <p
                    v-if="mode === 'simple' && defaultPolicy"
                    class="text-xs/5 text-(--ds-text-muted)"
                >
                    {{
                        $t('Follows :name: :summary.', {
                            name: defaultPolicy.name,
                            summary: policySummary(defaultPolicy),
                        }) + ' '
                    }}
                    <button
                        type="button"
                        class="cursor-pointer text-(--ds-text) underline decoration-(--ds-border-hover) underline-offset-2"
                        @click="mode = 'advanced'"
                    >
                        {{ $t('Choose another') }}
                    </button>
                </p>
                <div class="border-t border-(--ds-border-strong) pt-4 text-sm">
                    <div
                        v-if="state === 'discovered'"
                        class="flex flex-col gap-4"
                    >
                        <p class="flex items-center gap-2 text-(--ds-green)">
                            <Check class="size-4" />
                            {{
                                $t(
                                    ':machineName connected · :serviceCount services · :listenerCount listeners found',
                                    {
                                        machineName: String(machineName ?? ''),
                                        serviceCount: String(
                                            serviceCount ?? '',
                                        ),
                                        listenerCount: String(
                                            listenerCount ?? '',
                                        ),
                                    },
                                ) + ' '
                            }}
                        </p>
                        <SecurityScan
                            v-if="machineId"
                            :machine-id="machineId"
                        />
                    </div>
                    <div
                        v-else-if="state === 'connected'"
                        class="text-(--ds-text-strong)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Daemon connected. Scanning running services and ports…',
                            ) +
                            ' '
                        }}
                    </div>
                    <div
                        v-else-if="state === 'enrolled'"
                        class="text-(--ds-text-strong)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Daemon enrolled. Waiting for its first connection…',
                            ) +
                            ' '
                        }}
                    </div>
                    <div
                        v-else-if="state === 'expired'"
                        class="text-(--ds-amber)"
                    >
                        {{
                            ' ' +
                            $t('This command expired. Generate a new one.') +
                            ' '
                        }}
                    </div>
                    <div v-else class="text-(--ds-text-muted)">
                        {{ ' ' + $t('Waiting for the daemon to enrol…') + ' ' }}
                    </div>
                </div>
                <p class="text-xs/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Requires Linux, systemd, sudo, curl, and outbound HTTPS. The daemon runs as root. You can',
                        )
                    }}
                    <a
                        :href="`${page.props.appUrl}/install.sh`"
                        target="_blank"
                        rel="noopener"
                        class="underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >{{ $t('read the install script') }}</a
                    >
                    {{ $t('first, and see') }}
                    <a
                        :href="`${page.props.appUrl}/security`"
                        target="_blank"
                        rel="noopener"
                        class="underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >{{ $t('what the daemon can and can’t do') }}</a
                    >.
                </p>
                <AppButton
                    v-if="state === 'expired'"
                    size="sm"
                    @click="createCommand"
                    >{{ $t('Generate new command') }}</AppButton
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
