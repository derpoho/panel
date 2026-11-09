<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import Tile from '@/components/workspace/Tile.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { appButtonClasses } from '@/lib/appButton';
import { SolarSparkles as Sparkles } from '@/lib/solarIcons';

export type AiProvider = {
    provider: 'anthropic' | 'openai';
    label: string;
    defaultModel: string;
    connected: boolean;
    hint: string | null;
    model: string | null;
    by: string | null;
    connectedAt: string | null;
};

/**
 * The workspace's own AI keys. With one connected, server fixes and coding
 * agents run on the workspace's account instead of ServerOS's. Each row
 * opens a dialog to connect, change or disconnect.
 */
const props = defineProps<{
    providers: AiProvider[];
    agentUses: string;
    canManage: boolean;
}>();

const brand = { anthropic: 'claude', openai: 'openai' } as const;
const about = {
    anthropic: {
        name: 'Anthropic',
        product: 'Claude',
        uses: 'Server fixes and Claude Code',
        keysUrl: 'https://console.anthropic.com/settings/keys',
        placeholder: 'sk-ant-api03-…',
    },
    openai: {
        name: 'OpenAI',
        product: 'GPT',
        uses: 'Server fixes and Codex',
        keysUrl: 'https://platform.openai.com/api-keys',
        placeholder: 'sk-proj-…',
    },
} as const;

const openFor = ref<AiProvider['provider'] | null>(null);
const current = computed(
    () => props.providers.find((p) => p.provider === openFor.value) ?? null,
);
const open = computed({
    get: () => openFor.value !== null,
    set: (v: boolean) => {
        if (!v) openFor.value = null;
    },
});

const form = useForm({ key: '', model: '' });
const confirmingDisconnect = ref(false);

function show(p: AiProvider): void {
    form.reset();
    form.clearErrors();
    form.model = p.model ?? '';
    confirmingDisconnect.value = false;
    openFor.value = p.provider;
}

function save(): void {
    const p = current.value;
    if (!p) return;
    form.put(`/settings/ai/${p.provider}`, {
        preserveScroll: true,
        onSuccess: () => (openFor.value = null),
    });
}

function disconnect(): void {
    const p = current.value;
    if (!p) return;
    if (!confirmingDisconnect.value) {
        confirmingDisconnect.value = true;

        return;
    }
    router.delete(`/settings/ai/${p.provider}`, {
        preserveScroll: true,
        onSuccess: () => (openFor.value = null),
    });
}

function added(p: AiProvider): string {
    return p.connectedAt
        ? new Date(p.connectedAt).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const field =
    'h-10 w-full rounded-lg border border-(--ds-border) bg-transparent px-3 font-ui-mono text-[13px]/5 placeholder:text-(--ds-text-faint) focus:border-(--ds-border-hover) focus:outline-none';
</script>

<template>
    <Tile
        :title="$t('Your AI providers')"
        :description="
            $t(
                'Run the agents on your own account. Your logs and code go to your provider, and the usage is yours.',
            )
        "
        :icon="Sparkles"
        :padded="false"
    >
        <ul>
            <li
                v-for="(p, i) in providers"
                :key="p.provider"
                :class="{ 'border-t border-(--ds-divider)': i > 0 }"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-3.5 px-5 py-3.5 text-left transition-colors enabled:cursor-pointer enabled:hover:bg-(--ds-row-hover)"
                    :class="focusRing"
                    :disabled="!canManage"
                    @click="show(p)"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-[10px] border border-(--ds-border) bg-(--ds-hover)"
                    >
                        <AiBrandIcon
                            :brand="brand[p.provider]"
                            class="size-[18px]"
                        />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="text-[13.5px]/5 font-medium">{{
                            about[p.provider].name
                        }}</span>
                        <span
                            class="truncate text-[12.5px]/5 text-(--ds-text-muted)"
                        >
                            <template v-if="p.connected">
                                <code class="font-ui-mono text-[12px]">{{
                                    p.hint
                                }}</code>
                                · {{ p.model ?? p.defaultModel }}
                            </template>
                            <template v-else>{{
                                $t(about[p.provider].uses)
                            }}</template>
                        </span>
                    </span>
                    <span
                        v-if="p.connected"
                        class="flex items-center gap-1.5 text-[12.5px]/5 text-(--ds-green)"
                    >
                        <span
                            class="size-1.5 rounded-full bg-(--ds-green)"
                            aria-hidden="true"
                        />{{ $t('Connected') }}
                    </span>
                    <span
                        v-else-if="canManage"
                        :class="appButtonClasses('secondary', 'sm')"
                        aria-hidden="true"
                        >{{ $t('Connect') }}</span
                    >
                </button>
            </li>
        </ul>
        <template #footer>
            <p class="text-[12.5px]/5 text-(--ds-text-muted)">
                {{ $t('Server fixes run on') }}
                <span class="font-medium text-(--ds-text)">{{
                    agentUses
                }}</span>
            </p>
        </template>
    </Tile>

    <Dialog v-model:open="open">
        <DialogContent
            v-if="current"
            class="ds-modal top-[16%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text) sm:max-w-md"
        >
            <form @submit.prevent="save">
                <div
                    class="flex flex-col items-center gap-3 px-6 pt-7 pb-5 text-center"
                >
                    <span
                        class="grid size-12 place-items-center rounded-[14px] border border-(--ds-border) bg-(--ds-hover)"
                    >
                        <AiBrandIcon
                            :brand="brand[current.provider]"
                            class="size-6"
                        />
                    </span>
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-[15px]/6 font-semibold">
                            {{
                                current.connected
                                    ? about[current.provider].name
                                    : $t('Connect :name', {
                                          name: about[current.provider].name,
                                      })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                current.connected
                                    ? $t('Key :hint, added :when:by.', {
                                          hint: String(current.hint),
                                          when: added(current),
                                          by: current.by
                                              ? ' ' +
                                                $t('by :name', {
                                                    name: current.by,
                                                })
                                              : '',
                                      })
                                    : $t(':uses run on your :name account.', {
                                          uses: about[current.provider].uses,
                                          name: about[current.provider].name,
                                      })
                            }}
                        </DialogDescription>
                    </div>
                </div>

                <div class="flex flex-col gap-4 px-6 pb-6">
                    <label class="flex flex-col gap-1.5">
                        <span
                            class="flex items-center justify-between text-[12px]/5 font-medium text-(--ds-text-muted)"
                        >
                            {{
                                current.connected
                                    ? $t('Replace the key')
                                    : $t('API key')
                            }}
                            <a
                                :href="about[current.provider].keysUrl"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-0.5 font-normal text-(--ds-text-faint) hover:text-(--ds-text)"
                                >{{ $t('Get a key')
                                }}<ArrowUpRight class="size-3"
                            /></a>
                        </span>
                        <input
                            v-model="form.key"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            :class="field"
                            :placeholder="
                                current.connected
                                    ? $t('Leave empty to keep it')
                                    : about[current.provider].placeholder
                            "
                            :required="!current.connected"
                            :autofocus="!current.connected"
                        />
                        <span
                            v-if="form.errors.key"
                            class="text-[12px]/4 text-(--ds-red)"
                            >{{ form.errors.key }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span
                            class="text-[12px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Model for server fixes') }}</span
                        >
                        <input
                            v-model="form.model"
                            type="text"
                            spellcheck="false"
                            :class="field"
                            :placeholder="current.defaultModel"
                        />
                        <span
                            v-if="form.errors.model"
                            class="text-[12px]/4 text-(--ds-red)"
                            >{{ form.errors.model }}</span
                        >
                    </label>
                    <p class="text-[12px]/4 text-(--ds-text-faint)">
                        {{
                            $t(
                                'Checked with :name before it’s saved, then kept encrypted. It’s never shown again.',
                                { name: about[current.provider].name },
                            )
                        }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-6 py-3.5"
                >
                    <button
                        v-if="current.connected"
                        type="button"
                        :class="appButtonClasses('danger', 'md', focusRing)"
                        @click="disconnect"
                    >
                        {{
                            confirmingDisconnect
                                ? $t('Disconnect, sure?')
                                : $t('Disconnect')
                        }}
                    </button>
                    <button
                        type="button"
                        :class="
                            appButtonClasses(
                                'quiet',
                                'md',
                                focusRing,
                                'ml-auto',
                            )
                        "
                        @click="openFor = null"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :class="appButtonClasses('primary', 'md', focusRing)"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('Checking…')
                                : current.connected
                                  ? $t('Save')
                                  : $t('Connect')
                        }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
