<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAssistant } from '@/composables/useAssistant';

/**
 * Which model Ask AI runs on, for a workspace with its own AI key: the
 * models each key can use, newest first, grouped by provider. The pick is
 * kept in the browser and sent with each message; the key's own model
 * (Settings › AI and MCP) is the default. Without a key it runs on
 * ServerOS's AI and there's nothing to pick, so it says how to get one.
 */
const props = defineProps<{ mode: 'button' | 'note' }>();

const { model, modelOptions, loadModels, streaming } = useAssistant();

onMounted(() => void loadModels());

/** "provider:model" for each option, as the server takes it. */
const value = computed<string>({
    get() {
        const options = modelOptions.value ?? [];
        const pick = model.value;
        if (pick) {
            const [provider, ...rest] = pick.split(':');
            const option = options.find((o) => o.provider === provider);
            if (option?.models.some((m) => m.id === rest.join(':')))
                return pick;
        }
        // What the server uses with no pick: the first key's own model.
        return options[0] ? `${options[0].provider}:${options[0].default}` : '';
    },
    set(next: string) {
        model.value = next || null;
    },
});

const current = computed(() => {
    const [provider, ...rest] = value.value.split(':');
    const id = rest.join(':');
    const option = modelOptions.value?.find((o) => o.provider === provider);

    return option?.models.find((m) => m.id === id)?.label ?? id;
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <DropdownMenu v-if="props.mode === 'button' && modelOptions?.length">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex h-8 max-w-[150px] shrink-0 cursor-pointer items-center gap-1 rounded-[7px] px-2 text-[12px]/4 text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) disabled:cursor-default disabled:opacity-60"
                :class="focusRing"
                :disabled="streaming"
                :title="$t('Model: :model', { model: current })"
                :aria-label="$t('Model: :model', { model: current })"
            >
                <span class="truncate">{{ current }}</span>
                <ChevronDown class="size-3 shrink-0" :stroke-width="2" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            side="top"
            class="max-h-80 w-64 overflow-y-auto font-ui"
        >
            <DropdownMenuRadioGroup v-model="value">
                <template
                    v-for="(option, index) in modelOptions"
                    :key="option.provider"
                >
                    <DropdownMenuSeparator v-if="index > 0" />
                    <DropdownMenuLabel
                        class="text-[11px]/4 font-medium text-(--ds-text-faint)"
                    >
                        {{
                            $t('Your :provider key', { provider: option.label })
                        }}
                    </DropdownMenuLabel>
                    <DropdownMenuRadioItem
                        v-for="m in option.models"
                        :key="m.id"
                        :value="`${option.provider}:${m.id}`"
                        class="text-[13px]/5"
                    >
                        <span class="truncate">{{ m.label }}</span>
                        <span
                            v-if="m.id === option.default"
                            class="ml-auto pl-2 text-[11px]/4 text-(--ds-text-faint)"
                            >{{ $t('default') }}</span
                        >
                    </DropdownMenuRadioItem>
                </template>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>

    <template
        v-else-if="
            props.mode === 'note' && modelOptions && !modelOptions.length
        "
    >
        {{ ' ' + $t('Runs on ServerOS AI.') + ' ' }}
        <Link
            href="/settings/mcp"
            class="underline decoration-(--ds-text-faint)/50 underline-offset-2 hover:text-(--ds-text)"
            >{{ $t('Use your own key') }}</Link
        >
        {{ ' ' + $t('to pick a model.') + ' ' }}
    </template>
</template>
