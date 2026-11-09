<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import {
    SolarCode as Code,
    SolarHistory as History,
    SolarKeyRound as KeyRound,
    SolarCopy as Copy,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import { toast } from '@/lib/toast';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { timeAgo } from '@/lib/timeAgo';
import type { Workspace } from '@/types';

type Key = {
    id: number;
    name: string;
    prefix: string;
    scopes: string[];
    owner: { id: number; name: string; you: boolean };
    lastUsedAt: string | null;
    createdAt: string;
};

type ApiRequest = {
    id: number;
    tool: string;
    status: number | null;
    error: string | null;
    key: string | null;
    durationMs: number;
    at: string;
};

/**
 * Settings › API: the workspace's REST API. Where it is, the keys that
 * reach it (yours, or everyone's for those who manage members), and the
 * requests made with them.
 */
const props = defineProps<{
    workspace: Workspace;
    baseUrl: string;
    openapiUrl: string;
    guideUrl: string;
    referenceUrl: string;
    rateLimit: number | null;
    twoFactor: boolean;
    canChange: boolean;
    seesAll: boolean;
    keys: Key[];
    requests: ApiRequest[];
}>();

const copied = ref<string | null>(null);
async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}

const keyName = ref('');
const keyAccess = ref<'read' | 'write' | 'commands'>(
    props.canChange ? 'write' : 'read',
);
const creating = ref(false);
const keyErrors = ref<Record<string, string>>({});
const created = ref<string | null>(null);
const keyOpen = ref(false);

function newKey(): void {
    keyName.value = '';
    keyErrors.value = {};
    created.value = null;
    keyOpen.value = true;
}

function csrf(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function createKey(): Promise<void> {
    if (!keyName.value.trim() || creating.value) return;
    creating.value = true;
    keyErrors.value = {};
    try {
        const response = await fetch('/settings/api/keys', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                name: keyName.value.trim(),
                access: keyAccess.value,
            }),
        });
        const body = (await response.json().catch(() => ({}))) as {
            secret?: string;
            message?: string;
            errors?: Record<string, string[]>;
        };
        if (!response.ok || !body.secret) {
            keyErrors.value = Object.fromEntries(
                Object.entries(body.errors ?? {}).map(([k, v]) => [k, v[0]]),
            );
            if (!body.errors)
                keyErrors.value.name =
                    body.message ?? trans('Couldn’t make the key.');
            return;
        }
        created.value = body.secret;
        router.reload({ only: ['keys'] });
    } finally {
        creating.value = false;
    }
}

const accessOptions = computed(() => [
    {
        value: 'read' as const,
        label: trans('Read only'),
        description: trans('Look, never change anything.'),
        disabled: false,
    },
    {
        value: 'write' as const,
        label: trans('Read and make changes'),
        description: trans(
            'Deploy, restart, rename, organise, as your role allows.',
        ),
        disabled: !props.canChange,
    },
    {
        value: 'commands' as const,
        label: trans('Changes and commands'),
        description: props.twoFactor
            ? trans('Also run shell commands inside containers.')
            : trans('Needs two-factor authentication on your account.'),
        disabled: !props.canChange || !props.twoFactor,
    },
]);

function scopeLabel(scope: string): string {
    return (
        {
            'workspace:read': trans('Read'),
            'workspace:write': trans('Changes'),
            'workspace:commands': trans('Commands'),
        }[scope] ?? scope
    );
}

const revoking = ref<Key | null>(null);
const revokeBusy = ref(false);

function revoke(): void {
    if (!revoking.value) return;
    router.delete(`/settings/api/keys/${revoking.value.id}`, {
        preserveScroll: true,
        onStart: () => (revokeBusy.value = true),
        onFinish: () => {
            revokeBusy.value = false;
            revoking.value = null;
        },
    });
}

const example = computed(
    () =>
        `curl ${props.baseUrl}/machines \\\n  -H "Authorization: Bearer ${created.value ?? 'sos_…'}"`,
);

function tone(status: number | null): string {
    if (status === null) return 'bg-(--ds-hover) text-(--ds-text-muted)';
    if (status < 300) return 'bg-(--ds-green-bg) text-(--ds-green)';
    if (status < 500) return 'bg-(--ds-amber-bg) text-(--ds-amber)';

    return 'bg-(--ds-red-bg) text-(--ds-red)';
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const th = 'px-3 py-2.5 text-left text-[12px]/4 font-medium';
</script>

<template>
    <Head :title="$t('API')" />

    <div class="flex flex-col gap-5">
        <Tile
            :title="$t('REST API')"
            :description="
                $t(
                    'Everything in :name, from your own scripts and CI: machines, services, deploys, monitors, workflows, backups, logs and the team.',
                    { name: String(workspace.name) },
                )
            "
            :icon="Code"
        >
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1.5">
                    <span :class="labelClass">{{ $t('Base URL') }}</span>
                    <div class="flex gap-2">
                        <input
                            class="h-9 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-field) px-3 font-ui-mono text-[12px]/5"
                            :class="focusRing"
                            :value="baseUrl"
                            readonly
                            :aria-label="$t('Base URL')"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <AppButton @click="copy(baseUrl, 'url')">
                            <component
                                :is="copied === 'url' ? Check : Copy"
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{ copied === 'url' ? $t('Copied') : $t('Copy') }}
                        </AppButton>
                    </div>
                </div>

                <div class="relative">
                    <pre
                        class="overflow-x-auto rounded-lg border border-(--ds-border) bg-(--ds-field) px-3.5 py-3 font-ui-mono text-[12px]/5"
                        >{{ example }}</pre>
                    <button
                        type="button"
                        class="absolute top-2 right-2 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                        :class="focusRing"
                        :aria-label="$t('Copy')"
                        @click="copy(example, 'example')"
                    >
                        <component
                            :is="copied === 'example' ? Check : Copy"
                            class="size-3.5"
                        />
                    </button>
                </div>

                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    <template v-if="rateLimit !== null">
                        {{
                            $t(
                                'Each key may make :count requests a minute on this plan. API requests don’t use AI tool calls.',
                                { count: String(rateLimit) },
                            ) + ' '
                        }}
                    </template>
                    <template v-else>
                        {{ $t('The API needs a plan.') + ' ' }}
                    </template>
                    <a
                        :href="guideUrl"
                        class="text-(--ds-text) underline underline-offset-2"
                        >{{ $t('Guide') }}</a
                    >
                    ·
                    <a
                        :href="referenceUrl"
                        class="text-(--ds-text) underline underline-offset-2"
                        >{{ $t('Reference') }}</a
                    >
                    ·
                    <a
                        :href="openapiUrl"
                        class="text-(--ds-text) underline underline-offset-2"
                        >{{ $t('OpenAPI spec') }}</a
                    >
                </p>
            </div>
        </Tile>

        <Tile
            id="api-keys"
            :title="$t('API keys')"
            :description="
                seesAll
                    ? $t(
                          'Every key that can reach :name. Each acts as the person who made it, with their role. Revoking stops it at once.',
                          { name: String(workspace.name) },
                      )
                    : $t(
                          'Your keys. Each acts as you, with your role. Revoking stops it at once.',
                      )
            "
            :icon="KeyRound"
            :padded="false"
        >
            <template #actions>
                <AppButton size="sm" @click="newKey">
                    <KeyRound class="size-3.5" :stroke-width="1.75" />
                    {{ $t('New API key') + ' ' }}
                </AppButton>
            </template>

            <div v-if="keys.length" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13px]/5">
                    <thead>
                        <tr class="text-(--ds-text-muted)">
                            <th :class="[th, 'pl-5']">{{ $t('Name') }}</th>
                            <th :class="th">{{ $t('Access') }}</th>
                            <th :class="th">{{ $t('Key') }}</th>
                            <th v-if="seesAll" :class="th">
                                {{ $t('Owner') }}
                            </th>
                            <th :class="th">{{ $t('Last used') }}</th>
                            <th :class="[th, 'pr-5']" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="key in keys"
                            :key="key.id"
                            class="border-t border-(--ds-divider)"
                        >
                            <td class="px-3 py-2.5 pl-5 font-medium">
                                {{ key.name }}
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex flex-wrap gap-1">
                                    <span
                                        v-for="scope in key.scopes"
                                        :key="scope"
                                        class="rounded-full bg-(--ds-hover) px-1.5 py-px text-[11px]/4 text-(--ds-text-secondary)"
                                        >{{ scopeLabel(scope) }}</span
                                    >
                                </span>
                            </td>
                            <td
                                class="px-3 py-2.5 font-ui-mono text-[12px] text-(--ds-text-muted)"
                            >
                                {{ key.prefix }}…
                            </td>
                            <td
                                v-if="seesAll"
                                class="px-3 py-2.5 text-(--ds-text-secondary)"
                            >
                                {{ key.owner.you ? $t('You') : key.owner.name }}
                            </td>
                            <td class="px-3 py-2.5 text-(--ds-text-muted)">
                                {{
                                    key.lastUsedAt
                                        ? timeAgo(key.lastUsedAt)
                                        : $t('Never')
                                }}
                            </td>
                            <td class="px-3 py-2.5 pr-5 text-right">
                                <AppButton size="xs" @click="revoking = key">
                                    {{ $t('Revoke') }}
                                </AppButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="px-5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ ' ' + $t('No API keys yet.') + ' ' }}
            </p>
        </Tile>

        <Tile
            id="requests"
            :title="$t('Recent requests')"
            :description="
                $t(
                    'The last 50 requests made with these keys, newest first. Changes also show in the workspace’s activity.',
                )
            "
            :icon="History"
            :padded="false"
        >
            <div v-if="requests.length" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13px]/5">
                    <thead>
                        <tr class="text-(--ds-text-muted)">
                            <th :class="[th, 'pl-5']">{{ $t('Status') }}</th>
                            <th :class="th">{{ $t('Tool') }}</th>
                            <th :class="th">{{ $t('Key') }}</th>
                            <th :class="[th, 'text-right']">
                                {{ $t('Took') }}
                            </th>
                            <th :class="[th, 'pr-5 text-right']">
                                {{ $t('When') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="request in requests"
                            :key="request.id"
                            class="border-t border-(--ds-divider) align-top"
                        >
                            <td class="px-3 py-2.5 pl-5">
                                <span
                                    class="rounded-full px-1.5 py-px font-ui-mono text-[11px]/4 tabular-nums"
                                    :class="tone(request.status)"
                                    >{{ request.status ?? '–' }}</span
                                >
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="font-ui-mono text-[12.5px]">{{
                                    request.tool
                                }}</span>
                                <span
                                    v-if="request.error"
                                    class="block text-[12.5px]/5 text-(--ds-text-muted)"
                                    >{{ request.error }}</span
                                >
                            </td>
                            <td class="px-3 py-2.5 text-(--ds-text-secondary)">
                                {{ request.key ?? '–' }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-(--ds-text-muted) tabular-nums"
                            >
                                {{ request.durationMs }} ms
                            </td>
                            <td
                                class="px-3 py-2.5 pr-5 text-right text-(--ds-text-muted)"
                            >
                                {{ timeAgo(request.at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="px-5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ ' ' + $t('No requests yet.') + ' ' }}
            </p>
        </Tile>
    </div>

    <!-- Making a key, then showing it once -->
    <Dialog v-model:open="keyOpen">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form
                v-if="!created"
                class="flex min-w-0 flex-col"
                @submit.prevent="createKey"
            >
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ ' ' + $t('New API key') + ' ' }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'For scripts and CI. It acts as you, with your role in :name, and only works here.',
                                { name: workspace.name },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="api-key-name" :class="labelClass">
                            {{ ' ' + $t('Name') + ' ' }}
                        </label>
                        <Input
                            id="api-key-name"
                            v-model="keyName"
                            maxlength="80"
                            :placeholder="$t('GitHub Actions')"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="keyErrors.name" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{
                            $t('What it may do')
                        }}</span>
                        <div
                            class="flex flex-col gap-1.5"
                            role="radiogroup"
                            :aria-label="$t('What the key may do')"
                        >
                            <button
                                v-for="option in accessOptions"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="keyAccess === option.value"
                                :disabled="option.disabled"
                                class="ds-choice flex cursor-pointer flex-col items-start gap-0.5 px-3 py-2.5 text-left disabled:cursor-not-allowed disabled:opacity-50"
                                :class="focusRing"
                                @click="keyAccess = option.value"
                            >
                                <span class="text-[13px]/5 font-medium">{{
                                    option.label
                                }}</span>
                                <span
                                    class="text-[12px]/4 text-(--ds-text-muted)"
                                    >{{ option.description }}</span
                                >
                            </button>
                        </div>
                        <InputError :message="keyErrors.access" />
                    </div>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="keyOpen = false"
                    >
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Make key')"
                        busy-label="Making…"
                        :processing="creating"
                        :disabled="keyName.trim() === ''"
                    />
                </div>
            </form>

            <div v-else class="flex min-w-0 flex-col">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ ' ' + $t('Copy your key now') + ' ' }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'You won’t see it again. It’s also filled into the example, ready to try.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex gap-2 p-5">
                    <input
                        class="h-9 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-field) px-3 font-ui-mono text-[12px]/5"
                        :class="focusRing"
                        :value="created"
                        readonly
                        :aria-label="$t('New API key')"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <AppButton @click="copy(created, 'key')">
                        <component
                            :is="copied === 'key' ? Check : Copy"
                            class="size-3.5"
                            :stroke-width="1.75"
                        />
                        {{ copied === 'key' ? $t('Copied') : $t('Copy') }}
                    </AppButton>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="primary"
                        @click="keyOpen = false"
                    >
                        {{ ' ' + $t('Done') + ' ' }}
                    </AppButton>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="revoking !== null"
        @update:open="(open) => !open && (revoking = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-base/6 font-semibold">
                    {{ $t('Revoke') }} {{ revoking?.name }}?
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'It stops working at once. Anything using it needs a new key.',
                        ) +
                        ' '
                    }}
                </DialogDescription>
            </div>
            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
            >
                <AppButton size="sm" variant="quiet" @click="revoking = null">{{
                    $t('Cancel')
                }}</AppButton>
                <form @submit.prevent="revoke">
                    <DangerButton
                        :label="$t('Revoke')"
                        busy-label="Revoking…"
                        :processing="revokeBusy"
                    />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
