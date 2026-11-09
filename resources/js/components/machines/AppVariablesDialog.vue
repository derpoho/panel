<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link2, Plus, Undo2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { appButtonClasses } from '@/lib/appButton';
import { toast } from '@/lib/toast';

/*
 * An app's environment variables, by name only: values go in and never
 * come back. Saving deploys the app again with the change; the running
 * version keeps serving until the new one is up. The ones a project
 * connection writes are marked and left to it.
 */

type Variables = {
    names: { name: string; from: string | null; build: boolean }[];
    deployed: boolean;
};

const props = defineProps<{
    machineId: number;
    /** The name the app was deployed as. */
    app: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [jobId: string | null] }>();

const url = computed(
    () =>
        `/machines/${props.machineId}/apps/${encodeURIComponent(props.app)}/variables`,
);
const variables = ref<Variables | null>(null);
const failedToLoad = ref(false);
const drafts = ref<{ name: string; value: string; isNew: boolean }[]>([]);
const removing = ref<Set<string>>(new Set());
const busy = ref(false);
const error = ref<string | null>(null);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function load(): Promise<void> {
    variables.value = null;
    failedToLoad.value = false;
    const response = await fetch(url.value, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    }).catch(() => null);
    if (!response?.ok) {
        failedToLoad.value = true;

        return;
    }
    variables.value = (await response.json()) as Variables;
}

watch(
    open,
    (now) => {
        if (!now) return;
        drafts.value = [];
        removing.value = new Set();
        error.value = null;
        void load();
    },
    { immediate: true },
);

function addDraft(): void {
    drafts.value.push({ name: '', value: '', isNew: true });
}

function editDraft(name: string): void {
    if (!drafts.value.some((d) => d.name === name)) {
        drafts.value.push({ name, value: '', isNew: false });
    }
}

function toggleRemove(name: string): void {
    const next = new Set(removing.value);
    if (next.has(name)) next.delete(name);
    else {
        next.add(name);
        // Removing it drops a new value typed for it.
        drafts.value = drafts.value.filter((d) => d.name !== name);
    }
    removing.value = next;
}

const changes = computed(() => {
    const set: Record<string, string> = {};
    for (const d of drafts.value) {
        if (d.name.trim() && (d.isNew || d.value !== '')) {
            set[d.name.trim()] = d.value;
        }
    }

    return { set, remove: [...removing.value] };
});
const changeCount = computed(
    () => Object.keys(changes.value.set).length + changes.value.remove.length,
);

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    const response = await fetch(url.value, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(changes.value),
    }).catch(() => null);
    busy.value = false;
    const body = (await response?.json().catch(() => null)) as {
        jobId?: string | null;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;
    if (!response?.ok) {
        error.value =
            Object.values(body?.errors ?? {})[0]?.[0] ??
            body?.message ??
            trans('Couldn’t save that.');

        return;
    }
    open.value = false;
    if (body?.jobId) {
        toast.success(trans('Deploying it again with the new settings.'));
    }
    emit('saved', body?.jobId ?? null);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-xl"
        >
            <form class="flex max-h-[80dvh] flex-col" @submit.prevent="save">
                <div class="flex flex-col gap-1 p-5 pb-4">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t(':name’s settings', { name: app })
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Values are never shown again once saved. Type a new one to replace it. Saving deploys the app again with the change.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-5">
                    <p
                        v-if="failedToLoad"
                        class="py-6 text-center text-[13px] text-(--ds-text-muted)"
                    >
                        {{ $t('Couldn’t load them.') }}
                    </p>
                    <p
                        v-else-if="variables === null"
                        class="py-6 text-center text-[13px] text-(--ds-text-muted)"
                    >
                        {{ $t('Loading…') }}
                    </p>
                    <template v-else>
                        <ul
                            class="flex flex-col divide-y divide-(--ds-divider) rounded-[8px] border border-(color:--ds-divider)"
                        >
                            <li
                                v-for="item in variables.names"
                                :key="item.name"
                                class="flex items-center gap-2 px-3 py-2 text-[13px]"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate font-ui-mono text-xs"
                                    :class="
                                        removing.has(item.name) &&
                                        'text-(--ds-text-faint) line-through'
                                    "
                                    >{{ item.name }}</span
                                >
                                <span
                                    v-if="item.from"
                                    class="flex shrink-0 items-center gap-1 text-xs text-(--ds-text-muted)"
                                    :title="
                                        $t(
                                            'Written by the connection to :name',
                                            { name: item.from },
                                        )
                                    "
                                >
                                    <Link2
                                        class="size-3.5"
                                        :stroke-width="1.75"
                                    />
                                    {{ item.from }}
                                </span>
                                <span
                                    v-else-if="item.build"
                                    class="shrink-0 text-xs text-(--ds-text-faint)"
                                    >{{ $t('Set at build') }}</span
                                >
                                <template v-else>
                                    <Input
                                        v-if="
                                            drafts.some(
                                                (d) => d.name === item.name,
                                            )
                                        "
                                        v-model="
                                            drafts.find(
                                                (d) => d.name === item.name,
                                            )!.value
                                        "
                                        class="h-7 w-48 font-ui-mono text-xs"
                                        :placeholder="$t('New value')"
                                        :aria-label="
                                            $t('New value for :name', {
                                                name: item.name,
                                            })
                                        "
                                        autocomplete="off"
                                    />
                                    <button
                                        v-else-if="!removing.has(item.name)"
                                        type="button"
                                        :class="appButtonClasses('quiet', 'xs')"
                                        @click="editDraft(item.name)"
                                    >
                                        {{ $t('Replace') }}
                                    </button>
                                    <button
                                        type="button"
                                        :class="appButtonClasses('quiet', 'xs')"
                                        :aria-label="
                                            removing.has(item.name)
                                                ? $t('Keep :name', {
                                                      name: item.name,
                                                  })
                                                : $t('Remove :name', {
                                                      name: item.name,
                                                  })
                                        "
                                        @click="toggleRemove(item.name)"
                                    >
                                        <Undo2
                                            v-if="removing.has(item.name)"
                                            class="size-3.5"
                                        />
                                        <X v-else class="size-3.5" />
                                    </button>
                                </template>
                            </li>
                            <li
                                v-for="(draft, index) in drafts.filter(
                                    (d) => d.isNew,
                                )"
                                :key="'new' + index"
                                class="flex items-center gap-2 px-3 py-2"
                            >
                                <Input
                                    v-model="draft.name"
                                    class="h-7 min-w-0 flex-1 font-ui-mono text-xs"
                                    :placeholder="$t('NAME')"
                                    :aria-label="$t('Setting name')"
                                    autocomplete="off"
                                />
                                <Input
                                    v-model="draft.value"
                                    class="h-7 w-48 font-ui-mono text-xs"
                                    :placeholder="$t('Value')"
                                    :aria-label="$t('Setting value')"
                                    autocomplete="off"
                                />
                            </li>
                            <li
                                v-if="!variables.names.length && !drafts.length"
                                class="px-3 py-3 text-[13px] text-(--ds-text-muted)"
                            >
                                {{
                                    variables.deployed
                                        ? $t('No settings yet.')
                                        : $t(
                                              ':name hasn’t deployed yet, so it has no settings to change.',
                                              { name: app },
                                          )
                                }}
                            </li>
                        </ul>
                        <button
                            type="button"
                            class="mt-2"
                            :class="appButtonClasses('quiet', 'sm')"
                            @click="addDraft"
                        >
                            <Plus class="size-3.5" />
                            {{ $t('Add a setting') }}
                        </button>
                    </template>
                    <p
                        v-if="error"
                        class="mt-3 text-[13px] text-(--ds-red)"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </div>
                <div
                    class="mt-4 flex items-center justify-end gap-2 border-t border-(--ds-divider) px-5 py-4"
                >
                    <span class="mr-auto text-xs text-(--ds-text-muted)">
                        {{
                            changeCount === 0
                                ? ''
                                : $t(':count to change', {
                                      count: String(changeCount),
                                  })
                        }}
                    </span>
                    <button
                        type="button"
                        :class="appButtonClasses('secondary')"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :class="appButtonClasses('primary')"
                        :disabled="busy || changeCount === 0"
                    >
                        {{ $t('Save and deploy') }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
