<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight } from '@lucide/vue';
import {
    SolarMonitor as Monitor,
    SolarShieldAlert as ShieldAlert,
    SolarShieldCheck as ShieldCheck,
    SolarTriangleAlert as TriangleAlert,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import type { AiBrand } from '@/lib/aiBrands';
import { appButtonClasses } from '@/lib/appButton';
import { oauthAudienceFor } from '@/lib/oauthAudiences';

/**
 * OAuth consent for MCP: which app wants which workspace, what it may do,
 * and where its access goes. The app's name is whatever it called itself,
 * so the page leans on the redirect (which it can't fake and still get the
 * access) to say who it really is.
 *
 * A package's own audience (not a workspace) has no workspaces to choose
 * from; its copy comes from the package (lib/oauthAudiences.ts).
 */
defineOptions({
    inheritAttrs: false,
    layout: {
        title: trans('Connect an AI app'),
        description: trans('An app wants to work in ServerOS for you.'),
    },
});

const props = defineProps<{
    problem?: string;
    request?: string;
    client?: {
        name: string;
        known: AiBrand | null;
        redirectHost: string;
        redirectUri: string;
        local: boolean;
        registeredAt: string | null;
    };
    workspaces?: {
        id: number;
        name: string;
        host: string;
        role: string | null;
        canChange: boolean;
    }[];
    fixedWorkspace?: boolean;
    requested?: string[];
    scopes?: Record<string, { label: string; description: string }>;
    twoFactor?: boolean;
    account?: string;
    errors?: Record<string, string>;
}>();

const knownNames: Record<string, string> = {
    claude: 'Claude',
    openai: 'ChatGPT',
    cursor: 'Cursor',
    copilot: 'VS Code',
    windsurf: 'Windsurf',
};

/** A package's audience, when this isn't for a workspace: its props say
    which (`{key}: true`, lib/oauthAudiences.ts), and it has no workspaces
    of its own to choose from (the shared list is the person's, not the
    page's). */
const page = usePage();
const audience = computed(() => oauthAudienceFor(page.props));

const workspaceId = ref(props.workspaces?.[0]?.id ?? null);
const workspace = computed(() =>
    props.workspaces?.find((w) => w.id === workspaceId.value),
);

const readScope = computed(() => audience.value?.read ?? 'workspace:read');
const writeScope = computed(() => audience.value?.write ?? 'workspace:write');
const canChange = computed(() =>
    audience.value ? true : (workspace.value?.canChange ?? false),
);

const wantsWrite = computed(
    () => props.requested?.includes(writeScope.value) ?? false,
);
const wantsCommands = computed(
    () => props.requested?.includes('workspace:commands') ?? false,
);
const write = ref(wantsWrite.value && canChange.value);
const commands = ref(false);

const busy = ref<'approve' | 'deny' | null>(null);

function decide(decision: 'approve' | 'deny'): void {
    router.post(
        '/oauth/authorize',
        {
            request: props.request,
            decision,
            workspace: workspaceId.value,
            write: write.value,
            commands: !audience.value && write.value && commands.value,
        },
        {
            onStart: () => (busy.value = decision),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <Head :title="$t('Connect an AI app')" />

    <div
        v-if="problem"
        class="flex flex-col items-center gap-3 py-2 text-center"
        role="alert"
    >
        <ShieldAlert
            class="size-8 text-(--ds-red)"
            :stroke-width="1.5"
            aria-hidden="true"
        />
        <p class="text-[14px]/6 text-(--ds-text-secondary)">{{ problem }}</p>
    </div>

    <form
        v-else-if="client && (workspaces || audience)"
        class="flex flex-col gap-5"
        @submit.prevent="decide('approve')"
    >
        <!-- Who's asking -->
        <div class="flex items-center justify-center gap-3" aria-hidden="true">
            <span
                class="flex size-12 items-center justify-center rounded-[12px] bg-(--ds-field) ring-1 ring-(--ds-border)"
            >
                <AiBrandIcon :brand="client.known ?? 'mcp'" class="size-6" />
            </span>
            <ArrowLeftRight class="size-4 text-(--ds-text-faint)" />
            <span
                class="flex size-12 items-center justify-center rounded-[12px] bg-(--ds-field) ring-1 ring-(--ds-border)"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-6 dark:invert-0"
                />
            </span>
        </div>

        <div class="flex flex-col gap-1 text-center">
            <p class="text-[15px]/6 font-semibold">
                {{
                    $t(':name wants to use :value', {
                        name: client.name,
                        value: String(
                            audience
                                ? $t(audience.name)
                                : fixedWorkspace
                                  ? (workspace?.name ?? '')
                                  : $t('a workspace'),
                        ),
                    })
                }}
            </p>
            <p
                v-if="client.known"
                class="flex items-center justify-center gap-1.5 text-[12.5px]/5 text-(--ds-green)"
            >
                <ShieldCheck class="size-3.5" />
                {{
                    $t('Access can only go to :value (:redirectHost).', {
                        value: knownNames[client.known],
                        redirectHost: client.redirectHost,
                    }) + ' '
                }}
            </p>
            <p
                v-else-if="client.local"
                class="flex items-center justify-center gap-1.5 text-[12.5px]/5 text-(--ds-text-muted)"
            >
                <Monitor class="size-3.5" />
                {{ $t('Access goes to an app on this computer.') + ' ' }}
            </p>
            <p v-else class="text-[12.5px]/5 text-(--ds-amber)">
                {{
                    ' ' +
                    $t(
                        'ServerOS can’t verify this app. Only continue if you started this from an app you trust.',
                    ) +
                    ' '
                }}
            </p>
        </div>

        <!-- Which workspace -->
        <div v-if="audience" class="flex flex-col gap-1.5">
            <span class="text-[13px]/5 font-medium text-(--ds-text-muted)">{{
                $t('Where')
            }}</span>
            <p
                class="rounded-md border border-(--ds-border) bg-(--ds-field)/50 px-3 py-2 text-[13px]/5"
            >
                <span class="font-medium">{{ $t(audience.name) }}</span>
                <span class="text-(--ds-text-muted)">
                    {{ ' ' + $t(audience.where) }}</span
                >
            </p>
        </div>
        <div v-else class="flex flex-col gap-1.5">
            <label
                for="oauth-workspace"
                class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                >{{ $t('Workspace') }}</label
            >
            <select
                v-if="!fixedWorkspace && (workspaces?.length ?? 0) > 1"
                id="oauth-workspace"
                v-model="workspaceId"
                class="h-9 rounded-md border border-(--ds-border) bg-(--ds-field) px-2.5 text-[13px]/5"
            >
                <option v-for="w in workspaces" :key="w.id" :value="w.id">
                    {{ w.name }} ({{ w.role }})
                </option>
            </select>
            <p
                v-else
                id="oauth-workspace"
                class="rounded-md border border-(--ds-border) bg-(--ds-field)/50 px-3 py-2 text-[13px]/5"
            >
                <span class="font-medium">{{ workspace?.name }}</span>
                <span class="text-(--ds-text-muted)">
                    · {{ workspace?.role }} · {{ workspace?.host }}</span
                >
            </p>
            <InputError :message="errors?.workspace" />
        </div>

        <!-- What it may do -->
        <fieldset class="flex flex-col gap-2">
            <legend
                class="mb-1.5 text-[13px]/5 font-medium text-(--ds-text-muted)"
            >
                {{ ' ' + $t('It may') + ' ' }}
            </legend>
            <label class="flex items-start gap-2.5 text-[13px]/5">
                <TickBox :model-value="true" disabled class="mt-0.5" />
                <span>
                    <span class="font-medium">{{
                        scopes?.[readScope]?.label
                    }}</span>
                    <span class="block text-(--ds-text-muted)">{{
                        scopes?.[readScope]?.description
                    }}</span>
                </span>
            </label>
            <label
                v-if="wantsWrite"
                class="flex items-start gap-2.5 text-[13px]/5"
                :class="canChange ? 'cursor-pointer' : 'opacity-50'"
            >
                <TickBox
                    v-model="write"
                    :disabled="!canChange"
                    class="mt-0.5"
                />
                <span>
                    <span class="font-medium">{{
                        scopes?.[writeScope]?.label
                    }}</span>
                    <span class="block text-(--ds-text-muted)">{{
                        canChange
                            ? scopes?.[writeScope]?.description
                            : $t('Your role here is read-only.')
                    }}</span>
                </span>
            </label>
            <label
                v-if="wantsCommands && !audience"
                class="flex items-start gap-2.5 text-[13px]/5"
                :class="write && twoFactor ? 'cursor-pointer' : 'opacity-50'"
            >
                <TickBox
                    v-model="commands"
                    :disabled="!write || !twoFactor"
                    class="mt-0.5"
                />
                <span>
                    <span class="font-medium">{{
                        scopes?.['workspace:commands']?.label
                    }}</span>
                    <span class="block text-(--ds-text-muted)">{{
                        twoFactor
                            ? scopes?.['workspace:commands']?.description
                            : $t(
                                  'Turn on two-factor authentication to allow this.',
                              )
                    }}</span>
                </span>
            </label>
            <InputError :message="errors?.commands" />
        </fieldset>

        <div
            class="flex gap-2.5 rounded-[10px] border border-(--ds-amber)/30 bg-(--ds-amber)/8 px-3 py-2.5 text-[12.5px]/5 text-(--ds-text-secondary)"
        >
            <TriangleAlert
                class="mt-0.5 size-3.5 shrink-0 text-(--ds-amber)"
                aria-hidden="true"
            />
            <span v-if="audience">
                {{ ' ' + $t(audience.notice) + ' ' }}
            </span>
            <span v-else>
                {{
                    ' ' +
                    $t(
                        'MCP is experimental and may cause issues. The app acts as you, on real machines. You can disconnect it any time in Settings › AI and MCP.',
                    ) +
                    ' '
                }}
            </span>
        </div>

        <div class="flex flex-col gap-2">
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'w-full')"
                :disabled="busy !== null || (!audience && !workspaceId)"
            >
                {{ busy === 'approve' ? $t('Connecting…') : $t('Approve') }}
            </button>
            <button
                type="button"
                :class="appButtonClasses('secondary', 'md', 'w-full')"
                :disabled="busy !== null"
                @click="decide('deny')"
            >
                {{ busy === 'deny' ? $t('Saying no…') : $t('Deny') }}
            </button>
        </div>

        <p class="text-center text-[12px]/4 text-(--ds-text-faint)">
            {{
                ' ' +
                $t('Signed in as :account · access goes to', {
                    account: String(account ?? ''),
                })
            }}
            <span class="font-ui-mono break-all">{{ client.redirectUri }}</span>
        </p>
    </form>
</template>
