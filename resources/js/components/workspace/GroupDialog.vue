<script setup lang="ts" generic="Id extends string | number">
import { Search } from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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

export type GroupDialogItem<T> = {
    id: T;
    label: string;
    detail?: string;
    /** Already in another group: choosing it here moves it. */
    groupName?: string | null;
};

/**
 * Create or edit a group: a name and which items (services or machines)
 * are in it. The page saves; this only collects the choice.
 */
const props = defineProps<{
    /** "service" or "machine", for the wording. */
    noun: string;
    items: GroupDialogItem<Id>[];
    /** Editing an existing group, rather than creating one. */
    editing?: boolean;
    initialName?: string;
    initialSelected?: Id[];
    processing?: boolean;
    errors?: Record<string, string>;
}>();

const emit = defineEmits<{
    save: [value: { name: string; ids: Id[] }];
    delete: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const name = ref('');
const chosen = shallowRef<Set<Id>>(new Set());
const filter = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        name.value = props.initialName ?? '';
        chosen.value = new Set(props.initialSelected ?? []);
        filter.value = '';
    }
});

const visible = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return props.items.filter(
        (item) =>
            !needle ||
            `${item.label} ${item.detail ?? ''}`.toLowerCase().includes(needle),
    );
});

function toggle(id: Id, on: boolean): void {
    const next = new Set(chosen.value);

    if (on) {
        next.add(id);
    } else {
        next.delete(id);
    }

    chosen.value = next;
}

function submit(): void {
    emit('save', {
        name: name.value.trim(),
        ids: props.items
            .map((item) => item.id)
            .filter((id) => chosen.value.has(id)),
    });
}

/** The first error about the chosen items (services, machines.0…). */
const itemsError = computed(
    () =>
        Object.entries(props.errors ?? {}).find(([key]) => key !== 'name')?.[1],
);

const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <!-- min-w-0: a long service name mustn't stretch the dialog -->
            <form class="flex min-w-0 flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ editing ? $t('Edit group') : $t('New group') }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Grouped :nouns show as one row with their combined stats. Click it to see what’s inside.',
                                { noun },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="group-name" :class="labelClass">
                            {{ ' ' + $t('Name') + ' ' }}
                        </label>
                        <Input
                            id="group-name"
                            v-model="name"
                            maxlength="60"
                            :placeholder="$t('Web stack')"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="errors?.name" />
                    </div>

                    <!-- More about the group, e.g. its icon -->
                    <slot name="extra" />

                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <span :class="labelClass">
                                {{ noun[0].toUpperCase() + noun.slice(1) }}s
                            </span>
                            <span
                                class="text-xs/4 text-(--ds-text-faint) tabular-nums"
                            >
                                {{
                                    $t(':size chosen', {
                                        size: String(chosen.size ?? ''),
                                    }) + ' '
                                }}
                            </span>
                        </div>
                        <label
                            v-if="items.length > 6"
                            class="ds-raised-button cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring)"
                        >
                            <Search
                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                                :stroke-width="1.6"
                            />
                            <span class="sr-only">{{
                                $t('Filter :nouns', { noun })
                            }}</span>
                            <input
                                v-model="filter"
                                type="search"
                                :placeholder="
                                    $t('Filter :nouns', { noun: String(noun) })
                                "
                                class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                            />
                        </label>
                        <ul
                            class="max-h-64 overflow-y-auto rounded-[10px] border border-(--ds-border)"
                        >
                            <li
                                v-for="item in visible"
                                :key="String(item.id)"
                                class="border-t border-(--ds-border) first:border-t-0"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13px]/4 hover:bg-(--ds-row-hover)"
                                >
                                    <TickBox
                                        :model-value="chosen.has(item.id)"
                                        @update:model-value="
                                            (on) => toggle(item.id, on)
                                        "
                                    />
                                    <span class="flex min-w-0 grow flex-col">
                                        <span class="truncate">{{
                                            item.label
                                        }}</span>
                                        <span
                                            v-if="item.detail"
                                            class="truncate text-xs text-(--ds-text-muted)"
                                        >
                                            {{ item.detail }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="
                                            item.groupName &&
                                            !chosen.has(item.id)
                                        "
                                        class="shrink-0 rounded-[4px] bg-(--ds-hover) px-1.5 text-[11px]/4 text-(--ds-text-muted)"
                                        :title="
                                            $t(
                                                'In :groupName; choosing it moves it here',
                                                {
                                                    groupName: String(
                                                        item.groupName,
                                                    ),
                                                },
                                            )
                                        "
                                    >
                                        {{ item.groupName }}
                                    </span>
                                </label>
                            </li>
                            <li
                                v-if="visible.length === 0"
                                class="px-3 py-4 text-center text-[13px]/5 text-(--ds-text-muted)"
                            >
                                {{ ' ' + $t('Nothing matches.') + ' ' }}
                            </li>
                        </ul>
                        <InputError :message="itemsError" />
                    </div>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        v-if="editing"
                        size="sm"
                        variant="quiet"
                        class="text-(--ds-red)!"
                        @click="emit('delete')"
                    >
                        {{ ' ' + $t('Delete group') + ' ' }}
                    </AppButton>
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="editing ? $t('Save group') : $t('Create group')"
                        busy-label="Saving…"
                        :processing="processing ?? false"
                        :disabled="name.trim() === ''"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
