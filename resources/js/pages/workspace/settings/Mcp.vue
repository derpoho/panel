<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import {
    SolarHistory as History,
    SolarKeyRound as KeyRound,
    SolarTriangleAlert as TriangleAlert,
    SolarBug as Bug,
    SolarChartNoAxesColumn as ChartNoAxesColumn,
    SolarDatabaseBackup as DatabaseBackup,
    SolarPlug as Plug,
    SolarUserRoundCog as UserRoundCog,
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
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import Tile from '@/components/workspace/Tile.vue';
import AiProviders from '@/components/workspace/settings/AiProviders.vue';
import type { AiProvider } from '@/components/workspace/settings/AiProviders.vue';
import type { AiBrand } from '@/lib/aiBrands';
import { appButtonClasses } from '@/lib/appButton';
import { MCP_CLIENTS } from '@/lib/mcpClients';
import { timeAgo } from '@/lib/timeAgo';
import mcpTerms from '@/routes/mcp-terms';
import type { McpCallsUsage, Workspace } from '@/types';

type Connection = {
    id: number;
    kind: 'key' | 'oauth';
    name: string;
    known: AiBrand | null;
    prefix: string | null;
    scopes: string[];
    owner: { id: number; name: string; email: string; you: boolean };
    lastUsedAt: string | null;
    createdAt: string;
};

type Call = {
    id: number;
    tool: string;
    ok: boolean;
    error: string | null;
    arguments: Record<string, unknown> | null;
    via: string | null;
    durationMs: number;
    at: string;
};

/**
 * Settings › AI and MCP: connect Claude, ChatGPT, Cursor and other AI
 * apps to this workspace. It's experimental, and says so before anything
 * else; then how to connect each app, the keys and connected apps (yours,
 * or everyone's for those who manage members), and what agents did.
 */
const props = defineProps<{
    workspace: Workspace;
    ai: { providers: AiProvider[]; agentUses: string; canManage: boolean };
    mcpUrl: string;
    guideUrl: string;
    termsAccepted: boolean;
    twoFactor: boolean;
    canChange: boolean;
    seesAll: boolean;
    scopes: Record<string, { label: string; description: string }>;
    connections: Connection[];
    calls: Call[];
    mcpCalls: McpCallsUsage;
    /** Where to add calls or upgrade; null when there's nowhere. */
    billingUrl: string | null;
    canManageBilling: boolean;
}>();

const number = (n: number) => n.toLocaleString('en-GB');
const resetsOn = computed(() =>
    new Date(props.mcpCalls.resetsAt).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    }),
);
const usedShare = computed(() => {
    const { used, limit } = props.mcpCalls;
    if (limit === null) return 0;

    return limit === 0 ? 1 : Math.min(1, used / limit);
});

const agreed = ref(false);
const accepting = ref(false);
const termsError = ref<string | null>(null);

function accept(): void {
    if (!agreed.value) return;
    router.post(
        mcpTerms.accept().url,
        { accepted: true },
        {
            preserveScroll: true,
            onStart: () => (accepting.value = true),
            onFinish: () => (accepting.value = false),
            onError: (e) =>
                (termsError.value = e.accepted ?? trans('Couldn’t save that.')),
        },
    );
}

const risks = [
    {
        icon: UserRoundCog,
        title: trans('It acts as you'),
        body: trans(
            'A connected app has your role here, so it can stop services, redeploy, reboot or remove machines.',
        ),
    },
    {
        icon: Bug,
        title: trans('It may cause issues'),
        body: trans(
            'MCP is experimental. Actions can fail halfway, an AI can misread what you asked, and you could lose data.',
        ),
    },
    {
        icon: DatabaseBackup,
        title: trans('Start somewhere safe'),
        body: trans(
            'Try it on machines you don’t mind breaking, keep backups, and read what the AI is about to do before you let it.',
        ),
    },
];

const clientId = ref(MCP_CLIENTS[0].id);
const client = computed(
    () => MCP_CLIENTS.find((c) => c.id === clientId.value) ?? MCP_CLIENTS[0],
);
const withKey = ref(false);

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
const created = ref<{ secret: string; scopes: string[] } | null>(null);
const keyOpen = ref(false);
const keyStep = ref<'form' | 'secret'>('form');

/** Opens the new-key dialog fresh; the last secret stays in the snippet. */
function newKey(): void {
    keyName.value = '';
    keyErrors.value = {};
    keyStep.value = 'form';
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
        const response = await fetch('/settings/mcp/keys', {
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
            scopes?: string[];
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
        created.value = { secret: body.secret, scopes: body.scopes ?? [] };
        keyName.value = '';
        keyStep.value = 'secret';
        withKey.value = true;
        router.reload({ only: ['connections'] });
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

const revoking = ref<Connection | null>(null);
const revokeBusy = ref(false);

function revoke(): void {
    if (!revoking.value) return;
    router.delete(`/settings/mcp/connections/${revoking.value.id}`, {
        preserveScroll: true,
        onStart: () => (revokeBusy.value = true),
        onFinish: () => {
            revokeBusy.value = false;
            revoking.value = null;
        },
    });
}

function scopeLabel(scope: string): string {
    return (
        {
            'workspace:read': trans('Read'),
            'workspace:write': trans('Changes'),
            'workspace:commands': trans('Commands'),
        }[scope] ?? scope
    );
}

function summary(args: Record<string, unknown> | null): string {
    if (!args) return '';
    return Object.entries(args)
        .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
        .slice(0, 4)
        .map(([k, v]) => `${k}: ${String(v).slice(0, 40)}`)
        .join(' · ');
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';

/** The setup snippet, with the new key filled in when there is one. */
const snippet = computed(() =>
    client.value.snippet
        ? client.value.snippet(
              props.mcpUrl,
              withKey.value || !client.value.oauth
                  ? (created.value?.secret ?? null)
                  : null,
          )
        : null,
);
</script>

<template>
    <Head :title="$t('AI and MCP')" />

    <div class="flex flex-col gap-5">
        <!-- The workspace's own AI keys, for the agents -->
        <AiProviders v-bind="ai" />

        <!-- Before anything else: what it can do, and agreeing to it -->
        <Tile
            v-if="!termsAccepted"
            :title="$t('Before you connect an AI app')"
            :description="
                $t(
                    'AI apps can work in :name for you: check what’s running, deploy, fix and organise. Read this first.',
                    { name: String(workspace.name) },
                )
            "
            :icon="Plug"
            :padded="false"
        >
            <template #actions>
                <span
                    class="rounded-full bg-(--ds-amber)/12 px-2 py-px text-[11px]/4 font-medium text-(--ds-amber)"
                    >{{ $t('Experimental') }}</span
                >
            </template>

            <ul :aria-label="$t('Before you connect anything')">
                <li
                    v-for="(risk, index) in risks"
                    :key="risk.title"
                    class="flex gap-3.5 px-5 py-4"
                    :class="{ 'border-t border-(--ds-divider)': index > 0 }"
                >
                    <component
                        :is="risk.icon"
                        class="mt-0.5 size-4 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p class="text-[13px]/5 font-medium">
                            {{ risk.title }}
                        </p>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{ risk.body }}
                        </p>
                    </div>
                </li>
            </ul>

            <template #footer>
                <form
                    class="flex w-full flex-wrap items-center gap-3"
                    @submit.prevent="accept"
                >
                    <label
                        class="mr-auto flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                    >
                        <TickBox v-model="agreed" class="mt-0.5" />
                        <span>
                            {{
                                ' ' +
                                $t(
                                    'I understand MCP is experimental, may cause data loss, and I accept the ServerOS MCP terms.',
                                ) +
                                ' '
                            }}
                        </span>
                    </label>
                    <SubmitButton
                        :label="$t('Accept and continue')"
                        busy-label="Saving…"
                        :processing="accepting"
                        :disabled="!agreed"
                    />
                    <InputError
                        class="w-full"
                        :message="termsError ?? undefined"
                    />
                </form>
            </template>
        </Tile>

        <template v-else>
            <Tile
                id="connect"
                :title="$t('Connect an AI app')"
                :description="
                    $t(
                        'Let Claude, ChatGPT, Cursor and other AI apps work in :name for you: check what’s running, deploy, fix and organise.',
                        { name: String(workspace.name) },
                    )
                "
                :icon="Plug"
                :padded="false"
            >
                <template #actions>
                    <span
                        class="rounded-full bg-(--ds-amber)/12 px-2 py-px text-[11px]/4 font-medium text-(--ds-amber)"
                        >{{ $t('Experimental') }}</span
                    >
                </template>

                <!-- The address every app needs -->
                <div
                    class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex flex-col gap-0.5">
                        <p class="text-[13px]/5 font-medium">
                            {{ $t('Server URL') }}
                        </p>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'The address you give the app. It only reaches :name. New to this? Read the',
                                    { name: workspace.name },
                                )
                            }}
                            <a
                                :href="guideUrl"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-(--ds-text) underline underline-offset-2"
                                >{{ $t('step-by-step guide') }}</a
                            >.
                        </p>
                    </div>
                    <div class="flex w-full gap-2 md:w-auto">
                        <input
                            class="h-8 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-field) px-2.5 font-ui-mono text-[12px]/5 text-(--ds-text) md:w-80"
                            :class="focusRing"
                            :value="mcpUrl"
                            readonly
                            :aria-label="$t('Server URL')"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <AppButton size="sm" @click="copy(mcpUrl, 'url')">
                            <component
                                :is="copied === 'url' ? Check : Copy"
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{ copied === 'url' ? $t('Copied') : $t('Copy') }}
                        </AppButton>
                    </div>
                </div>

                <!-- Pick the app, then its steps -->
                <div
                    class="flex flex-col gap-4 border-t border-(--ds-divider) px-5 py-4"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="max-w-full overflow-x-auto">
                            <div
                                class="ds-segments"
                                role="group"
                                :aria-label="$t('AI app')"
                            >
                                <button
                                    v-for="option in MCP_CLIENTS"
                                    :key="option.id"
                                    type="button"
                                    class="cursor-pointer gap-1.5"
                                    :class="focusRing"
                                    :aria-pressed="clientId === option.id"
                                    @click="clientId = option.id"
                                >
                                    <AiBrandIcon
                                        :brand="option.brand"
                                        class="size-3.5"
                                    />
                                    {{ option.name }}
                                </button>
                            </div>
                        </div>
                        <button
                            v-if="client.snippet && client.oauth"
                            type="button"
                            class="ml-auto cursor-pointer rounded text-[12.5px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
                            :class="focusRing"
                            @click="withKey = !withKey"
                        >
                            {{
                                withKey
                                    ? $t('Sign in with OAuth instead')
                                    : $t('Use an API key instead')
                            }}
                        </button>
                    </div>

                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            client.oauth && !withKey
                                ? $t(
                                      'Signs in with OAuth: you approve it in ServerOS, no key to copy.',
                                  )
                                : $t(
                                      'Uses an API key: make one with New API key, under Keys and connected apps.',
                                  )
                        }}
                    </p>

                    <ol class="flex flex-col gap-2.5">
                        <li
                            v-for="(step, index) in client.steps"
                            :key="step"
                            class="flex gap-3 text-[13px]/5"
                        >
                            <span
                                class="sidebar-pill--active flex size-5 shrink-0 items-center justify-center rounded-full font-ui-mono text-[11px]/none text-(--ds-text)"
                                >{{ index + 1 }}</span
                            >
                            <span class="text-(--ds-text-secondary)">{{
                                step
                            }}</span>
                        </li>
                    </ol>

                    <div v-if="snippet" class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[12px]/4 text-(--ds-text-faint)">
                                {{ client.snippetLabel }}
                            </span>
                            <button
                                type="button"
                                class="flex cursor-pointer items-center gap-1 rounded text-[12px]/4 text-(--ds-text-muted) hover:text-(--ds-text)"
                                :class="focusRing"
                                @click="copy(snippet, 'snippet')"
                            >
                                <component
                                    :is="copied === 'snippet' ? Check : Copy"
                                    class="size-3"
                                    :stroke-width="1.75"
                                />
                                {{
                                    copied === 'snippet'
                                        ? $t('Copied')
                                        : $t('Copy')
                                }}
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto rounded-lg border border-(--ds-border) bg-(--ds-code) px-3 py-2.5 font-ui-mono text-xs/5 text-(--ds-text-secondary)"
                            >{{ snippet }}</pre>
                        <p
                            v-if="(withKey || !client.oauth) && !created"
                            class="text-[12px]/4 text-(--ds-text-faint)"
                        >
                            {{
                                ' ' +
                                $t('Make an API key and it’s filled in here.') +
                                ' '
                            }}
                        </p>
                    </div>
                </div>

                <p
                    class="flex gap-2 border-t border-(--ds-divider) px-5 py-3 text-[12.5px]/5 text-(--ds-text-muted)"
                >
                    <TriangleAlert
                        class="mt-0.5 size-3.5 shrink-0 text-(--ds-amber)"
                        :stroke-width="1.75"
                    />
                    {{
                        $t(
                            'AI apps make mistakes, and they act on your real machines. Keep backups, and read what they’re about to do before you let them.',
                        ) + ' '
                    }}
                </p>
            </Tile>

            <Tile
                id="tool-calls"
                :title="$t('AI tool calls')"
                :description="
                    mcpCalls.unlimited
                        ? $t(
                              'Each tool an AI app runs here is one call. This workspace has no limit.',
                          )
                        : $t(
                              'Each tool an AI app runs here is one call. Resets on :resetsOn.',
                              { resetsOn: String(resetsOn) },
                          )
                "
                :icon="ChartNoAxesColumn"
            >
                <template
                    v-if="canManageBilling && !mcpCalls.unlimited && billingUrl"
                    #actions
                >
                    <Link
                        :href="billingUrl"
                        :class="appButtonClasses('secondary', 'sm')"
                    >
                        {{
                            usedShare >= 0.8
                                ? $t('Add calls or upgrade')
                                : $t('Add calls')
                        }}
                    </Link>
                </template>

                <div class="flex flex-col gap-3">
                    <p class="text-[13px]/5 text-(--ds-text-secondary)">
                        <span
                            class="text-[20px]/none font-semibold text-(--ds-text) tabular-nums"
                            >{{ number(mcpCalls.used) }}</span
                        >
                        <template v-if="mcpCalls.limit !== null">
                            {{
                                ' ' +
                                $t('/ :value AI tool calls used', {
                                    value: number(mcpCalls.limit),
                                }) +
                                ' '
                            }}
                        </template>
                        <template v-else>
                            {{ ' ' + $t('AI tool calls this month') }}</template
                        >
                    </p>
                    <div
                        v-if="mcpCalls.limit !== null"
                        class="h-1.5 overflow-hidden rounded-full bg-(--ds-hover)"
                        role="progressbar"
                        :aria-valuenow="mcpCalls.used"
                        aria-valuemin="0"
                        :aria-valuemax="mcpCalls.limit"
                        :aria-label="$t('AI tool calls used this month')"
                    >
                        <div
                            class="h-full rounded-full"
                            :class="
                                usedShare >= 1
                                    ? 'bg-(--ds-red)'
                                    : usedShare >= 0.8
                                      ? 'bg-(--ds-amber)'
                                      : 'bg-(--ds-green)'
                            "
                            :style="{ width: `${usedShare * 100}%` }"
                        />
                    </div>
                    <p
                        v-if="mcpCalls.limit !== null && usedShare >= 1"
                        class="text-[13px]/5 text-(--ds-red)"
                    >
                        {{
                            $t(
                                "All used. AI apps can't run tools here until :resetsOn:value",
                                {
                                    resetsOn,
                                    value: canManageBilling
                                        ? $t(
                                              ', unless you add calls or upgrade.',
                                          )
                                        : '. Ask the workspace owner to add more.',
                                },
                            )
                        }}
                    </p>
                </div>
            </Tile>

            <Tile
                id="keys"
                :title="$t('Keys and connected apps')"
                :description="
                    seesAll
                        ? $t(
                              'Everything that can reach :name over MCP. Revoking stops it at once.',
                              { name: String(workspace.name) },
                          )
                        : $t(
                              'Your keys and the apps you connected. Revoking stops them at once.',
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

                <ul v-if="connections.length" class="text-[13px]/5">
                    <li
                        v-for="(connection, index) in connections"
                        :key="connection.id"
                        class="flex items-center gap-3 px-5 py-3"
                        :class="{ 'border-t border-(--ds-divider)': index > 0 }"
                    >
                        <span
                            class="ds-icon-chip size-8! rounded-[8px]!"
                            aria-hidden="true"
                        >
                            <AiBrandIcon
                                v-if="connection.known"
                                :brand="connection.known"
                                class="size-4"
                            />
                            <KeyRound
                                v-else-if="connection.kind === 'key'"
                                class="size-4 text-(--ds-text-muted)"
                                :stroke-width="1.75"
                            />
                            <AiBrandIcon v-else brand="mcp" class="size-4" />
                        </span>
                        <span class="flex min-w-0 grow flex-col">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span class="truncate font-medium">{{
                                    connection.name
                                }}</span>
                                <span
                                    v-for="scope in connection.scopes"
                                    :key="scope"
                                    class="rounded-full bg-(--ds-hover) px-1.5 py-px text-[11px]/4 text-(--ds-text-secondary)"
                                    >{{ scopeLabel(scope) }}</span
                                >
                            </span>
                            <span
                                class="truncate text-[12.5px]/5 text-(--ds-text-muted)"
                            >
                                {{
                                    connection.kind === 'key'
                                        ? `API key ${connection.prefix}…`
                                        : $t('Signed in with OAuth')
                                }}<template v-if="!connection.owner.you">
                                    · {{ connection.owner.name }}</template
                                >
                                ·
                                {{
                                    connection.lastUsedAt
                                        ? `used ${timeAgo(connection.lastUsedAt)}`
                                        : $t('never used')
                                }}
                            </span>
                        </span>
                        <AppButton size="sm" @click="revoking = connection">
                            {{
                                connection.kind === 'key'
                                    ? $t('Revoke')
                                    : $t('Disconnect')
                            }}
                        </AppButton>
                    </li>
                </ul>
                <p
                    v-else
                    class="px-5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('Nothing is connected yet.') + ' ' }}
                </p>
            </Tile>

            <Tile
                :title="$t('Recent activity')"
                :description="
                    $t(
                        'Every tool an AI app called here, newest first. Changes also show in the workspace’s activity.',
                    )
                "
                :icon="History"
                :padded="false"
            >
                <ul v-if="calls.length" class="text-[13px]/5">
                    <li
                        v-for="(call, index) in calls"
                        :key="call.id"
                        class="flex items-start gap-3 px-5 py-2.5"
                        :class="{ 'border-t border-(--ds-divider)': index > 0 }"
                    >
                        <span
                            class="mt-1.75 size-1.5 shrink-0 rounded-full"
                            :class="
                                call.ok ? 'bg-(--ds-green)' : 'bg-(--ds-red)'
                            "
                            :aria-label="call.ok ? $t('Worked') : $t('Failed')"
                        />
                        <span class="flex min-w-0 grow flex-col">
                            <span class="flex min-w-0 items-baseline gap-x-2">
                                <span
                                    class="shrink-0 font-ui-mono text-[12.5px]"
                                    >{{ call.tool }}</span
                                >
                                <span
                                    class="truncate text-[12.5px] text-(--ds-text-muted)"
                                    >{{ summary(call.arguments) }}</span
                                >
                            </span>
                            <span
                                v-if="call.error"
                                class="text-[12.5px]/5 text-(--ds-red)"
                                >{{ call.error }}</span
                            >
                        </span>
                        <span
                            class="shrink-0 text-right text-[12px]/5 text-(--ds-text-faint)"
                        >
                            {{ call.via ? `${call.via} · ` : ''
                            }}{{ timeAgo(call.at) }}
                        </span>
                    </li>
                </ul>
                <p
                    v-else
                    class="px-5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' + $t('No AI app has done anything here yet.') + ' '
                    }}
                </p>
            </Tile>
        </template>
    </div>

    <!-- Making a key, then showing it once -->
    <Dialog v-model:open="keyOpen">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form
                v-if="keyStep === 'form'"
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
                                'For apps that can’t sign in with OAuth. It acts as you, with your role in :name, and only works here.',
                                { name: workspace.name },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="mcp-key-name" :class="labelClass">
                            {{ ' ' + $t('Name') + ' ' }}
                        </label>
                        <Input
                            id="mcp-key-name"
                            v-model="keyName"
                            maxlength="80"
                            :placeholder="$t('Cursor on my laptop')"
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

            <div v-else-if="created" class="flex min-w-0 flex-col">
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
                                'You won’t see it again. It’s also filled into the setup steps, ready to paste.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex gap-2 p-5">
                    <input
                        class="h-9 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-field) px-3 font-ui-mono text-[12px]/5"
                        :class="focusRing"
                        :value="created.secret"
                        readonly
                        :aria-label="$t('New API key')"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <AppButton @click="copy(created.secret, 'key')">
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
                    {{
                        revoking?.kind === 'key'
                            ? $t('Revoke')
                            : $t('Disconnect')
                    }}
                    {{ revoking?.name }}?
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'It stops working at once. Anything using it has to be set up again.',
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
                        :label="
                            revoking?.kind === 'key'
                                ? $t('Revoke')
                                : $t('Disconnect')
                        "
                        busy-label="Revoking…"
                        :processing="revokeBusy"
                    />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
