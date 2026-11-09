<script setup lang="ts" generic="T extends string | number | null">
import { Check, ChevronDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * A select that looks like the rest of the dashboard: a field-like button,
 * then a menu as wide as it with a tick on the chosen one, a quiet hint on
 * the right of any option, and an optional "New …" at the bottom.
 */
export type PickerOption<V> = {
    value: V;
    label: string;
    hint?: string;
    disabled?: boolean;
};

const props = defineProps<{
    id?: string;
    options: PickerOption<T>[];
    /** Shown at the bottom, e.g. "New policy…"; emits create. */
    createLabel?: string;
    placeholder?: string;
}>();

const value = defineModel<T>({ required: true });
const emit = defineEmits<{ create: [] }>();

const chosen = computed(() =>
    props.options.find((option) => option.value === value.value),
);

const row =
    'flex h-8 cursor-pointer items-center gap-2 rounded-[7px] px-2 text-[13.5px]/5 text-(--ds-text-secondary) data-highlighted:text-(--ds-text)';
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                :id="id"
                type="button"
                class="group flex h-9 w-full cursor-pointer items-center gap-2 rounded-[8px] border border-(--ds-border) bg-(--ds-field) px-3 text-left text-[13.5px]/5 transition-colors hover:border-(--ds-border-hover) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring) data-[state=open]:border-(--ds-border-hover)"
            >
                <span
                    class="truncate"
                    :class="{ 'text-(--ds-text-faint)': !chosen }"
                    >{{ chosen?.label ?? placeholder ?? '' }}</span
                >
                <span
                    v-if="chosen?.hint"
                    class="shrink-0 text-(--ds-text-faint)"
                    >{{ chosen.hint }}</span
                >
                <ChevronDown
                    class="mi-drop ml-auto size-3.5 shrink-0 text-(--ds-text-faint) group-data-[state=open]:rotate-180"
                    :stroke-width="1.75"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="ds-modal w-(--reka-dropdown-menu-trigger-width) rounded-[10px] p-1 font-ui"
            align="start"
            :side-offset="4"
        >
            <DropdownMenuItem
                v-for="option in options"
                :key="String(option.value)"
                :class="row"
                :disabled="option.disabled"
                @select="value = option.value"
            >
                <span
                    class="truncate"
                    :class="{ 'text-(--ds-text)': option.value === value }"
                    >{{ option.label }}</span
                >
                <span
                    v-if="option.hint"
                    class="shrink-0 text-[12.5px] text-(--ds-text-faint)"
                    >{{ option.hint }}</span
                >
                <Check
                    v-if="option.value === value"
                    class="ml-auto size-3.5 shrink-0 text-(--ds-text)"
                    :stroke-width="2"
                />
            </DropdownMenuItem>
            <template v-if="createLabel">
                <DropdownMenuSeparator class="-mx-1 my-1" />
                <DropdownMenuItem
                    :class="[row, 'text-(--ds-text-muted)']"
                    @select="emit('create')"
                >
                    <Plus class="size-3.5 shrink-0" :stroke-width="1.75" />
                    {{ createLabel }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
