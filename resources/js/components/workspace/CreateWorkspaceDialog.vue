<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import WorkspaceSlugInput from '@/components/WorkspaceSlugInput.vue';
import { store } from '@/routes/workspaces';
import AppButton from '@/components/workspace/AppButton.vue';
import { SolarServer as Server } from '@/lib/solarIcons';

type Props = {
    /** The root domain workspaces live under, e.g. serveros.com. */
    domain: string;
};

defineProps<Props>();

const open = defineModel<boolean>('open', { default: false });

/* Machines to move in from workspaces they manage machines in. Fetched when
   the dialog opens; the section stays folded until they want it. */
type Movable = {
    /** Whether this workspace gets the Pro trial: only someone's first one does. */
    trial: boolean;
    limit: number | null;
    workspaces: {
        name: string;
        machines: {
            id: number;
            name: string;
            online: boolean;
            services: number;
        }[];
    }[];
};
const movable = ref<Movable | null>(null);
const moveOpen = ref(false);
const picked = ref<number[]>([]);

watch(open, async (isOpen) => {
    if (!isOpen) {
        moveOpen.value = false;
        picked.value = [];
        return;
    }
    try {
        const response = await fetch('/workspaces/movable-machines', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        movable.value = response.ok
            ? ((await response.json()) as Movable)
            : null;
    } catch {
        movable.value = null;
    }
});

const hasMachines = computed(() =>
    (movable.value?.workspaces ?? []).some((w) => w.machines.length > 0),
);
const overLimit = computed(
    () =>
        movable.value?.limit != null &&
        picked.value.length > movable.value.limit,
);

function toggle(id: number, on: boolean): void {
    picked.value = on
        ? [...picked.value, id]
        : picked.value.filter((p) => p !== id);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-form ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle class="text-[15px]/5 font-semibold">{{
                    $t('Create a workspace')
                }}</DialogTitle>
                <DialogDescription class="text-[13px]/5">
                    {{
                        $t(
                            'Workspaces keep machines, people and billing separate, e.g. one per client or company.',
                        )
                    }}
                    <template v-if="movable">
                        {{
                            ' ' +
                            (movable.trial
                                ? $t('This one starts with a 14-day Pro trial.')
                                : $t(
                                      'You’ve had your free trial, so this one starts without a plan. Choose one once it’s made.',
                                  ))
                        }}
                    </template>
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="store.form()"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-5"
            >
                <div class="grid gap-2">
                    <Label for="workspace-name">{{ $t('Name') }}</Label>
                    <Input
                        id="workspace-name"
                        name="name"
                        required
                        v-focus
                        autocomplete="organization"
                        :placeholder="$t('Kernel Panic Ltd')"
                    />
                    <InputError :message="errors.name" />
                </div>

                <WorkspaceSlugInput
                    :domain="domain"
                    :error="errors.workspace"
                    :hint="
                        $t(
                            'Optional. We\'ll create one from the name if you skip it.',
                        )
                    "
                />

                <div
                    v-if="hasMachines"
                    class="overflow-hidden rounded-lg border border-(--ds-border)"
                >
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2.5 text-left text-[13px]/5 hover:bg-(--ds-hover)"
                        :aria-expanded="moveOpen"
                        @click="moveOpen = !moveOpen"
                    >
                        <Server
                            class="size-4 shrink-0 text-(--ds-text-muted)"
                        />
                        <span class="grow font-medium">
                            {{ $t('Move machines in') }}
                            <span
                                v-if="picked.length"
                                class="ml-1 font-normal text-(--ds-text-muted)"
                                >· {{ picked.length }} picked</span
                            >
                        </span>
                        <span class="text-[12px] text-(--ds-text-muted)">{{
                            $t('Optional')
                        }}</span>
                        <ChevronDown
                            class="size-3.5 text-(--ds-text-muted) transition-transform"
                            :class="{ 'rotate-180': moveOpen }"
                        />
                    </button>
                    <div
                        v-show="moveOpen"
                        class="flex max-h-64 flex-col gap-3 overflow-y-auto border-t border-(--ds-border) px-3 py-3"
                    >
                        <p class="text-[12px]/4.5 text-(--ds-text-muted)">
                            {{
                                $t(
                                    'They leave their current workspace with their services, monitors, backups and history. Nothing on the server changes.',
                                )
                            }}
                            <template v-if="movable && !movable.trial">
                                {{
                                    ' ' +
                                    $t(
                                        'Deploys, monitors and workflows on them start again once this workspace has a plan.',
                                    )
                                }}
                            </template>
                        </p>
                        <div
                            v-for="group in movable?.workspaces ?? []"
                            :key="group.name"
                            class="flex flex-col gap-1"
                        >
                            <p
                                class="text-[11px]/4 font-medium tracking-wide text-(--ds-text-faint) uppercase"
                            >
                                {{ group.name }}
                            </p>
                            <label
                                v-for="machine in group.machines"
                                :key="machine.id"
                                class="flex cursor-pointer items-center gap-2.5 rounded-md px-1.5 py-1.5 text-[13px]/5 hover:bg-(--ds-hover)"
                            >
                                <input
                                    type="checkbox"
                                    name="machines[]"
                                    :value="machine.id"
                                    :checked="picked.includes(machine.id)"
                                    class="size-4 accent-(--ds-blue)"
                                    @change="
                                        toggle(
                                            machine.id,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="
                                        machine.online
                                            ? 'bg-(--ds-green)'
                                            : 'bg-(--ds-text-faint)'
                                    "
                                    aria-hidden="true"
                                />
                                <span class="grow truncate">{{
                                    machine.name
                                }}</span>
                                <span
                                    class="shrink-0 text-[12px] text-(--ds-text-muted)"
                                    >{{ machine.services }}
                                    {{
                                        machine.services === 1
                                            ? 'service'
                                            : 'services'
                                    }}</span
                                >
                            </label>
                        </div>
                        <p
                            v-if="overLimit"
                            class="text-[12px]/4.5 text-(--ds-amber)"
                        >
                            {{
                                $t(
                                    'A new workspace can take up to :limit machines during its trial.',
                                    { limit: String(movable?.limit) },
                                )
                            }}
                        </p>
                    </div>
                    <InputError
                        :message="errors.machines"
                        class="px-3 pb-2.5"
                    />
                </div>

                <AppButton
                    type="submit"
                    variant="primary"
                    :disabled="processing || overLimit"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Create workspace') + ' ' }}
                </AppButton>
            </Form>
        </DialogContent>
    </Dialog>
</template>
