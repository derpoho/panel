<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import {
    SolarBug as Bug,
    SolarDatabaseBackup as DatabaseBackup,
    SolarSparkles as Sparkles,
    SolarUserRoundCog as UserRoundCog,
    SolarCopy as Copy,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { useStepFrame } from '@/composables/useStepFrame';
import { appButtonClasses } from '@/lib/appButton';
import { MCP_CLIENTS } from '@/lib/mcpClients';
import mcpTerms from '@/routes/mcp-terms';
import type { Workspace } from '@/types';

/**
 * Connecting an AI agent over MCP, styled after the search palette. First
 * the experimental notice, which has to be accepted (recorded on the
 * account), then the server URL and the apps that work with it; keys and
 * per-app steps live in Settings › AI and MCP.
 */
const open = defineModel<boolean>('open', { default: false });

const page = usePage<{ workspace: Workspace }>();

const accepted = computed(() => !!page.props.auth.user.mcp_terms_accepted_at);

const agreed = ref(false);
const accepting = ref(false);
const error = ref<string | null>(null);

/*
 * Which step shows. Accepting doesn't swap it straight away: the button
 * shows its tick first, then the notice blurs out, setup slides in and the
 * modal eases to the new height.
 */
const stage = ref<'terms' | 'setup'>(accepted.value ? 'setup' : 'terms');
const justAccepted = ref(false);
let advanceTimer: ReturnType<typeof setTimeout> | undefined;

watch(open, (isOpen) => {
    clearTimeout(advanceTimer);

    if (isOpen) {
        agreed.value = false;
        error.value = null;
        justAccepted.value = false;
        stage.value = accepted.value ? 'setup' : 'terms';
    }
});

/* The frame animates its height between the two steps. */
const { height: frameHeight, freeze, unfreeze } = useStepFrame('stageInner');

onBeforeUnmount(() => clearTimeout(advanceTimer));

function accept(): void {
    if (!agreed.value || accepting.value) {
        return;
    }

    router.post(
        mcpTerms.accept().url,
        { accepted: true },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['auth'],
            onStart: () => (accepting.value = true),
            onFinish: () => (accepting.value = false),
            onSuccess: () => {
                justAccepted.value = true;
                advanceTimer = setTimeout(() => (stage.value = 'setup'), 650);
            },
            onError: (errors) =>
                (error.value =
                    errors.accepted ?? trans('Couldn’t save that. Try again.')),
        },
    );
}

const url = computed(() => `https://${page.props.workspace.host}/mcp`);
const copied = ref(false);

async function copyUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(url.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard can be blocked; the URL is selectable.
    }
}

const risks = [
    {
        icon: UserRoundCog,
        title: trans('It acts as you'),
        body: trans(
            'An agent has your role here, so it can stop services, redeploy or remove machines.',
        ),
    },
    {
        icon: Bug,
        title: trans('It may cause issues'),
        body: trans(
            'MCP is experimental. Actions can fail halfway or do the wrong thing, and you could lose data.',
        ),
    },
    {
        icon: DatabaseBackup,
        title: trans('Start somewhere safe'),
        body: trans(
            'Try it on a workspace you don’t mind wiping, and keep your own backups.',
        ),
    },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="ds-modal top-[14%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text) sm:max-w-lg"
            :show-close-button="false"
        >
            <div
                class="flex h-12 items-center gap-2.5 border-b border-(--ds-border) px-4"
            >
                <Sparkles
                    class="size-4 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.5"
                    aria-hidden="true"
                />
                <DialogTitle class="text-sm font-medium">
                    {{ ' ' + $t('Connect your agent') + ' ' }}
                </DialogTitle>
                <span
                    class="rounded-full border border-(--ds-amber)/30 bg-(--ds-amber)/10 px-2 py-px text-[11px]/4 font-medium text-(--ds-amber)"
                >
                    {{ ' ' + $t('Experimental') + ' ' }}
                </span>
                <kbd
                    class="ml-auto rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px text-[11px]/3.5 text-(--ds-text-muted)"
                    aria-hidden="true"
                >
                    Esc
                </kbd>
            </div>

            <!-- Step one: the notice, accepted once per account -->
            <div class="ds-step-frame" :style="{ height: frameHeight }">
                <div ref="stageInner">
                    <Transition
                        name="ds-step"
                        mode="out-in"
                        @before-leave="freeze"
                        @enter="unfreeze"
                    >
                        <form
                            v-if="stage === 'terms'"
                            key="terms"
                            @submit.prevent="accept"
                        >
                            <div
                                class="flex max-h-[65vh] flex-col gap-4 overflow-y-auto p-4"
                            >
                                <DialogDescription
                                    class="text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{
                                        ' ' +
                                        $t(
                                            'MCP lets an AI agent read and run your :name workspace: list machines, check services, deploy and roll back. Before you set it up, please read this.',
                                            { name: page.props.workspace.name },
                                        ) +
                                        ' '
                                    }}
                                </DialogDescription>

                                <ul
                                    class="overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50"
                                    :aria-label="$t('Before you turn it on')"
                                >
                                    <li
                                        v-for="risk in risks"
                                        :key="risk.title"
                                        class="flex gap-3 border-t border-(--ds-border) px-3.5 py-3 first:border-t-0"
                                    >
                                        <component
                                            :is="risk.icon"
                                            class="mt-0.5 size-4 shrink-0 text-(--ds-text-muted)"
                                            :stroke-width="1.6"
                                            aria-hidden="true"
                                        />
                                        <div
                                            class="flex min-w-0 flex-col gap-0.5"
                                        >
                                            <p
                                                class="text-[13px]/5 font-medium"
                                            >
                                                {{ risk.title }}
                                            </p>
                                            <p
                                                class="text-[13px]/5 text-(--ds-text-muted)"
                                            >
                                                {{ risk.body }}
                                            </p>
                                        </div>
                                    </li>
                                </ul>

                                <label
                                    class="flex cursor-pointer items-start gap-2.5 px-0.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
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
                                <InputError :message="error ?? undefined" />
                            </div>

                            <div
                                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                            >
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    @click="open = false"
                                >
                                    {{ ' ' + $t('Not now') + ' ' }}
                                </AppButton>
                                <SubmitButton
                                    :label="$t('Accept and continue')"
                                    busy-label="Saving…"
                                    done-label="Accepted"
                                    :processing="accepting"
                                    :done="justAccepted"
                                    :disabled="!agreed || justAccepted"
                                />
                            </div>
                        </form>

                        <div v-else key="setup">
                            <div
                                class="flex max-h-[65vh] flex-col gap-4 overflow-y-auto p-4"
                            >
                                <DialogDescription
                                    class="text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{
                                        ' ' +
                                        $t(
                                            'Connect an agent to :name. Remember it’s experimental: keep it away from anything you can’t afford to lose.',
                                            { name: page.props.workspace.name },
                                        ) +
                                        ' '
                                    }}
                                </DialogDescription>

                                <div class="flex flex-col gap-1.5">
                                    <span
                                        class="text-xs/4 font-medium text-(--ds-text-muted)"
                                        >{{ $t('Server URL') }}</span
                                    >
                                    <div class="flex gap-2">
                                        <input
                                            class="h-8 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-code) px-2.5 font-ui-mono text-xs/5 text-(--ds-text-secondary)"
                                            :value="url"
                                            readonly
                                            :aria-label="$t('MCP server URL')"
                                            @focus="
                                                (
                                                    $event.target as HTMLInputElement
                                                ).select()
                                            "
                                        />
                                        <AppButton size="sm" @click="copyUrl">
                                            <component
                                                :is="copied ? Check : Copy"
                                                class="size-3.5"
                                                :stroke-width="1.75"
                                            />
                                            {{
                                                copied
                                                    ? $t('Copied')
                                                    : $t('Copy')
                                            }}
                                        </AppButton>
                                    </div>
                                </div>

                                <section
                                    aria-labelledby="mcp-apps"
                                    class="flex flex-col gap-2"
                                >
                                    <h3
                                        id="mcp-apps"
                                        class="text-xs/4 font-medium text-(--ds-text-muted)"
                                    >
                                        {{ ' ' + $t('Works with') + ' ' }}
                                    </h3>
                                    <ul class="grid grid-cols-3 gap-2">
                                        <li
                                            v-for="client in MCP_CLIENTS"
                                            :key="client.id"
                                            class="flex items-center gap-2 rounded-lg border border-(--ds-border) px-2.5 py-2 text-[12.5px]/4"
                                        >
                                            <AiBrandIcon
                                                :brand="client.brand"
                                                class="size-4 shrink-0"
                                            />
                                            <span class="truncate">{{
                                                client.name
                                            }}</span>
                                        </li>
                                    </ul>
                                    <p
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            ' ' +
                                            $t(
                                                'Claude, ChatGPT, Cursor and VS Code sign in with OAuth: add the URL and approve the connection in ServerOS. For anything else, make an API key.',
                                            ) +
                                            ' '
                                        }}
                                    </p>
                                </section>
                            </div>

                            <div
                                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                            >
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    @click="open = false"
                                >
                                    {{ ' ' + $t('Close') + ' ' }}
                                </AppButton>
                                <Link
                                    href="/settings/mcp"
                                    :class="appButtonClasses('primary', 'sm')"
                                    @click="open = false"
                                >
                                    {{ ' ' + $t('Set it up') }}
                                    <ArrowRight class="size-3.5" />
                                </Link>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
