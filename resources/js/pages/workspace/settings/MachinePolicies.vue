<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PolicySends from '@/components/machines/PolicySends.vue';
import PolicySwitches from '@/components/machines/PolicySwitches.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import Tile from '@/components/workspace/Tile.vue';
import {
    fieldLabel,
    policyBaseline,
    policySettings,
    policySummary,
} from '@/lib/machinePolicies';
import type {
    MachinePolicy,
    PolicyRule,
    PolicySettings,
} from '@/lib/machinePolicies';
import { SolarShieldCheck } from '@/lib/solarIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

type Row = MachinePolicy & {
    machines: number;
    groups: string[];
    pending: number;
    /** Following machines with an update (or the pin) to install. */
    updatable: number;
    outdated: string[];
};

/**
 * Settings › Machine policies: what the workspace's machines
 * collect and send. A machine follows its own policy, else its group's,
 * else the default; saving one sends it to every machine that follows it.
 */
const props = defineProps<{
    workspace: Workspace;
    canManage: boolean;
    defaultPolicyId: number;
    policies: Row[];
    /** What rules can match: the values the workspace's machines use. */
    metadata: {
        providers: string[];
        regions: string[];
        tiers: string[];
        labels: Record<string, string[]>;
    };
    /** Releases a policy can stay on, newest first. */
    releases: string[];
    since: string;
}>();

const base = computed(
    () => onWorkspace(settingsRoutes.machinePolicies(props.workspace.slug)).url,
);

const open = ref(false);
const editing = ref<Row | null>(null);
const name = ref('');
const settings = ref<PolicySettings>({ ...policyBaseline });
const clearLogs = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

/**
 * Narrowing what machines following it send: logs going off, or IP masking
 * going on. Offer to delete what they already sent.
 */
const logsGoingOff = computed(
    () => editing.value !== null && editing.value.logs && !settings.value.logs,
);
const maskingGoingOn = computed(
    () =>
        editing.value !== null &&
        settings.value.logs &&
        editing.value.log_ip_masking === 'off' &&
        settings.value.log_ip_masking !== 'off',
);
const offerClear = computed(
    () =>
        (editing.value?.machines ?? 0) > 0 &&
        (logsGoingOff.value || maskingGoingOn.value),
);

function newPolicy(): void {
    editing.value = null;
    name.value = '';
    // Start from the default, the usual way to begin a variation.
    const start = props.policies.find((p) => p.id === props.defaultPolicyId);
    settings.value = start ? policySettings(start) : { ...policyBaseline };
    rules.value = [];
    clearLogs.value = false;
    errors.value = {};
    open.value = true;
}

function edit(policy: Row): void {
    editing.value = policy;
    name.value = policy.name;
    settings.value = policySettings(policy);
    rules.value = (policy.appliesTo ?? []).map((rule) => ({ ...rule }));
    clearLogs.value = false;
    errors.value = {};
    open.value = true;
}

const requestOptions = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (saving.value = true),
    onFinish: () => (saving.value = false),
    onError: (bag: Record<string, string>) => (errors.value = bag),
    onSuccess: () => (open.value = false),
};

function save(): void {
    const payload = {
        name: name.value.trim(),
        ...settings.value,
        applies_to: rules.value.filter((rule) => rule.value.trim() !== ''),
        clear_logs: offerClear.value && clearLogs.value,
    };
    if (editing.value) {
        router.put(
            `${base.value}/${editing.value.id}`,
            payload,
            requestOptions,
        );
    } else {
        router.post(base.value, payload, requestOptions);
    }
}

/*
 * Rules: machines this policy applies to by their details, when neither
 * they nor their group picked one. Any rule matching is enough.
 */
const rules = ref<PolicyRule[]>([]);
const ruleFields = computed(() => [
    'provider',
    'region',
    'tier',
    ...Object.keys(props.metadata.labels).map((key) => `label:${key}`),
]);

function valuesFor(field: string): string[] {
    if (field === 'provider') return props.metadata.providers;
    if (field === 'region') return props.metadata.regions;
    if (field === 'tier') return props.metadata.tiers;

    return props.metadata.labels[field.slice(6)] ?? [];
}

function addRule(): void {
    rules.value = [...rules.value, { field: 'tier', value: '' }];
}

function rulesText(policy: Row): string {
    return (policy.appliesTo ?? [])
        .map((rule) => `${fieldLabel(rule.field)}: ${rule.value}`)
        .join(', ');
}

/** Install the waiting update (or the pin) on every machine that follows it. */
function installUpdates(policy: Row): void {
    router.post(
        `${base.value}/${policy.id}/install-updates`,
        {},
        { preserveScroll: true, onError: (bag) => (errors.value = bag) },
    );
}

function makeDefault(policy: Row): void {
    router.post(`${base.value}/${policy.id}/default`, {}, requestOptions);
}

function remove(policy: Row): void {
    router.delete(`${base.value}/${policy.id}`, requestOptions);
}

const outdated = computed(() => [
    ...new Set(props.policies.flatMap((policy) => policy.outdated)),
]);

function followers(policy: Row): string {
    if (policy.machines === 0) return trans('No machines');

    return policy.machines === 1
        ? trans('1 machine')
        : trans(':count machines', { count: String(policy.machines) });
}

const th = 'px-3 py-2.5 text-left text-[12px]/4 font-medium';
const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
</script>

<template>
    <Head :title="$t('Machine policies')" />

    <div class="flex flex-col gap-5">
        <Tile
            :title="$t('Machine policies')"
            :description="
                $t(
                    'Choose what your machines look for, collect and send. A machine follows its own policy, else its group’s, else the default.',
                )
            "
            :icon="SolarShieldCheck"
            :padded="false"
        >
            <template v-if="canManage" #actions>
                <AppButton size="sm" @click="newPolicy">
                    <Plus class="size-3.5" :stroke-width="2" />
                    {{ $t('New policy') }}
                </AppButton>
            </template>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13px]/5">
                    <thead>
                        <tr class="text-(--ds-text-muted)">
                            <th :class="[th, 'pl-5']">{{ $t('Name') }}</th>
                            <th :class="th">{{ $t('What it does') }}</th>
                            <th :class="th">{{ $t('Followed by') }}</th>
                            <th :class="[th, 'pr-5']" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="policy in policies"
                            :key="policy.id"
                            class="border-t border-(--ds-divider) align-top"
                        >
                            <td class="px-3 py-3 pl-5">
                                <span class="flex items-center gap-2">
                                    <span class="font-medium">{{
                                        policy.name
                                    }}</span>
                                    <span
                                        v-if="policy.isDefault"
                                        class="rounded-full bg-(--ds-blue-bg) px-1.75 py-px text-[11px]/4 font-medium text-(--ds-blue) ring-1 ring-(--ds-blue-border)"
                                        >{{ $t('Default') }}</span
                                    >
                                </span>
                            </td>
                            <td class="px-3 py-3 text-(--ds-text-secondary)">
                                <span class="flex flex-col">
                                    <span>{{ policySummary(policy) }}</span>
                                    <span
                                        v-if="policy.appliesTo?.length"
                                        class="text-[12px]/4 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t('Applies to :rules', {
                                                rules: rulesText(policy),
                                            })
                                        }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-(--ds-text-secondary)">
                                <span class="flex flex-col">
                                    <span>{{ followers(policy) }}</span>
                                    <span
                                        v-if="policy.groups.length"
                                        class="text-[12px]/4 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t('Groups: :names', {
                                                names: policy.groups.join(', '),
                                            })
                                        }}
                                    </span>
                                    <span
                                        v-if="policy.pending"
                                        class="text-[12px]/4 text-(--ds-amber)"
                                    >
                                        {{
                                            $t(
                                                ':count offline, sent when they reconnect',
                                                {
                                                    count: String(
                                                        policy.pending,
                                                    ),
                                                },
                                            )
                                        }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 pr-5 text-right">
                                <span class="inline-flex items-center gap-2">
                                    <AppButton
                                        v-if="canManage && policy.updatable"
                                        size="sm"
                                        @click="installUpdates(policy)"
                                    >
                                        {{
                                            policy.updatable === 1
                                                ? $t('Update 1 machine')
                                                : $t('Update :count machines', {
                                                      count: String(
                                                          policy.updatable,
                                                      ),
                                                  })
                                        }}
                                    </AppButton>
                                    <AppButton
                                        v-if="canManage"
                                        size="sm"
                                        @click="edit(policy)"
                                    >
                                        {{ $t('Edit') }}
                                    </AppButton>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                class="border-t border-(--ds-divider) px-5 py-3 text-[12.5px]/4.5 text-(--ds-text-muted)"
            >
                {{
                    $t(
                        'Health (CPU, memory, disk and network) is always sent: it’s how ServerOS knows a machine is up. Give a machine its own policy in its settings, or a group’s in Machines.',
                    )
                }}
            </p>
        </Tile>

        <InputError v-if="!open" :message="errors.update" />

        <p
            v-if="outdated.length"
            class="rounded-[10px] border border-(--ds-amber-border) bg-(--ds-amber-bg) px-3.5 py-3 text-[13px]/5 text-(--ds-amber-strong)"
        >
            {{
                $t(
                    ':names run a daemon older than :version, so they can’t turn discovery or logs off at the source, or wait for update approval, yet. They update themselves. Logs a policy turns off are still dropped as they arrive.',
                    { names: outdated.join(', '), version: since },
                )
            }}
        </p>
    </div>

    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-4xl"
        >
            <form class="flex min-w-0 flex-col" @submit.prevent="save">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ editing ? $t('Edit policy') : $t('New policy') }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            editing && editing.machines > 0
                                ? $t(
                                      'Saving sends it to the :count machines that follow it. Offline ones get it when they reconnect.',
                                      { count: String(editing.machines) },
                                  )
                                : $t(
                                      'Give it to machines in their settings, to a group in Machines, or when you connect one.',
                                  )
                        }}
                    </DialogDescription>
                </div>

                <div
                    class="grid max-h-[70svh] gap-5 overflow-y-auto p-5 lg:grid-cols-[minmax(0,1fr)_300px]"
                >
                    <div class="flex min-w-0 flex-col gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="policy-name" :class="labelClass">
                                {{ $t('Name') }}
                            </label>
                            <Input
                                id="policy-name"
                                v-model="name"
                                maxlength="60"
                                :placeholder="$t('Customer servers')"
                                autocomplete="off"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <PolicySwitches
                            v-model="settings"
                            :releases="releases"
                        />
                        <InputError :message="errors.pinned_version" />

                        <!-- The default catches everything else: no rules. -->
                        <div
                            v-if="!editing?.isDefault"
                            class="flex flex-col gap-2 rounded-[10px] border border-(--ds-border) px-3.5 py-3"
                        >
                            <div class="flex flex-col">
                                <span class="text-[14px]/5 font-medium">{{
                                    $t('Applies to')
                                }}</span>
                                <span
                                    class="text-[13px]/4.5 text-(--ds-text-muted)"
                                >
                                    {{
                                        $t(
                                            'Machines with any of these details follow it, unless they or their group picked a policy.',
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                v-for="(rule, index) in rules"
                                :key="index"
                                class="flex items-center gap-2"
                            >
                                <select
                                    v-model="rule.field"
                                    class="ds-select ds-raised-button h-8 w-36 shrink-0 cursor-pointer appearance-none pr-8! text-[13px]"
                                    :aria-label="$t('Detail')"
                                >
                                    <option
                                        v-for="field in ruleFields"
                                        :key="field"
                                        :value="field"
                                    >
                                        {{ fieldLabel(field) }}
                                    </option>
                                </select>
                                <span
                                    class="text-[13px] text-(--ds-text-muted)"
                                    >{{ $t('is') }}</span
                                >
                                <Input
                                    v-model="rule.value"
                                    class="h-8 grow"
                                    :list="`policy-rule-values-${index}`"
                                    :placeholder="$t('performance')"
                                    :aria-label="$t('Value')"
                                />
                                <datalist :id="`policy-rule-values-${index}`">
                                    <option
                                        v-for="value in valuesFor(rule.field)"
                                        :key="value"
                                        :value="value"
                                    />
                                </datalist>
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    :aria-label="$t('Remove')"
                                    @click="rules.splice(index, 1)"
                                >
                                    <X class="size-3.5" />
                                </AppButton>
                            </div>
                            <div>
                                <AppButton size="sm" @click="addRule">
                                    <Plus class="size-3.5" :stroke-width="2" />
                                    {{ $t('Add a rule') }}
                                </AppButton>
                            </div>
                            <InputError
                                :message="
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('applies_to'),
                                    )?.[1]
                                "
                            />
                        </div>

                        <label
                            v-if="offerClear"
                            class="flex cursor-pointer items-start gap-2.5 rounded-[10px] border border-(--ds-border) bg-(--ds-subtle) px-3.5 py-3"
                        >
                            <TickBox v-model="clearLogs" class="mt-0.5" />
                            <span class="flex flex-col">
                                <span class="text-[13.5px]/5 font-medium">
                                    {{
                                        maskingGoingOn
                                            ? $t(
                                                  'Also delete the logs they sent with full IP addresses',
                                              )
                                            : $t(
                                                  'Also delete the logs they already sent',
                                              )
                                    }}
                                </span>
                                <span
                                    class="text-[12.5px]/4.5 text-(--ds-text-muted)"
                                >
                                    {{
                                        $t(
                                            'From the :count machines that follow this policy. This can’t be undone.',
                                            {
                                                count: String(
                                                    editing?.machines ?? 0,
                                                ),
                                            },
                                        )
                                    }}
                                </span>
                            </span>
                        </label>
                        <InputError :message="errors.policy" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <span :class="labelClass">{{
                            $t('What its servers send')
                        }}</span>
                        <PolicySends :settings="settings" />
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <template v-if="editing && !editing.isDefault">
                        <AppButton
                            size="sm"
                            variant="quiet"
                            class="text-(--ds-red)"
                            :disabled="saving"
                            @click="remove(editing)"
                        >
                            {{ $t('Delete') }}
                        </AppButton>
                        <AppButton
                            size="sm"
                            variant="quiet"
                            :disabled="saving"
                            @click="makeDefault(editing)"
                        >
                            {{ $t('Make default') }}
                        </AppButton>
                    </template>
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="editing ? $t('Save') : $t('Create policy')"
                        :busy-label="$t('Saving…')"
                        :processing="saving"
                        :disabled="name.trim() === ''"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
