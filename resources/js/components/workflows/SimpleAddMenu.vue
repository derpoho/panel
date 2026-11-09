<script setup lang="ts">
import { Plus } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { STEP_GROUPS, STEPS } from './kinds';
import type { StepKind } from './kinds';
import { PLAIN } from './simple';

/** "Add a step": every kind, grouped, in everyday words. */
defineProps<{ label: string; prominent?: boolean }>();
const emit = defineEmits<{ pick: [kind: StepKind] }>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex w-fit cursor-pointer items-center focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                :class="
                    prominent
                        ? 'gap-2 rounded-[10px] border border-dashed border-(--ds-border-strong) px-3.5 py-2.5 text-[13px]/5 font-medium text-(--ds-text-secondary) hover:border-(--ds-border-hover) hover:bg-(--ds-hover) hover:text-(--ds-text)'
                        : 'gap-1.5 rounded-md px-1.5 py-1 text-[12.5px]/5 text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)'
                "
            >
                <Plus :class="prominent ? 'size-4' : 'size-3.5'" />
                {{ label }}
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="ds-modal max-h-96 min-w-60 overflow-y-auto font-ui"
            align="start"
        >
            <template v-for="(group, g) in STEP_GROUPS" :key="group.label">
                <DropdownMenuSeparator v-if="g > 0" />
                <DropdownMenuLabel class="text-[11px] text-(--ds-text-faint)">{{
                    group.label
                }}</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="kind in group.kinds"
                    :key="kind"
                    @select="emit('pick', kind)"
                >
                    <component :is="STEPS[kind].icon" class="size-4" />
                    {{ PLAIN[kind] }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
