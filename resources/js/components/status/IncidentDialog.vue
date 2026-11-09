<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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

/**
 * An incident written for the status pages: post a new one, or open one to
 * read its updates, post another, resolve it, or delete it. One about a
 * machine shows on its page and the workspace's; one about nothing in
 * particular, on the workspace's only.
 */
export type Incident = {
    id: number;
    title: string;
    impact: 'degraded' | 'partial' | 'major';
    state: 'investigating' | 'identified' | 'monitoring' | 'resolved';
    machine: string | null;
    machineId: number | null;
    startedAt: string;
    resolvedAt: string | null;
    /** Newest first. */
    updates: { state: string; message: string; at: string }[];
};

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    /** The one to show; null to post a new one. */
    incident: Incident | null;
    machines: { id: number; name: string }[];
    canManage: boolean;
}>();

const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const textareaClass =
    'rounded-md border border-input bg-transparent px-3 py-2 text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const impacts = computed(() => [
    { value: 'degraded', label: trans('Degraded') },
    { value: 'partial', label: trans('Partial outage') },
    { value: 'major', label: trans('Major outage') },
]);
const states = computed(() => [
    { value: 'investigating', label: trans('Investigating') },
    { value: 'identified', label: trans('Identified') },
    { value: 'monitoring', label: trans('Monitoring') },
    { value: 'resolved', label: trans('Resolved') },
]);
const stateLabel = (state: string) =>
    states.value.find((s) => s.value === state)?.label ?? state;
const impactLabel = (impact: string) =>
    impacts.value.find((i) => i.value === impact)?.label ?? impact;

function when(iso: string): string {
    return new Date(iso).toLocaleString([], {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const form = useForm({
    title: '',
    impact: 'partial',
    state: 'investigating',
    message: '',
    machine_id: null as number | null,
});

// Every opening starts blank (a submit makes what was sent the form's
// defaults, so reset() would bring the last message back), and an
// existing one's next update starts from where it stands.
watch(open, (isOpen) => {
    if (!isOpen) return;
    const incident = props.incident;
    form.title = '';
    form.message = '';
    form.machine_id = null;
    form.impact = incident?.impact ?? 'partial';
    form.state = !incident
        ? 'investigating'
        : incident.resolvedAt
          ? 'investigating'
          : incident.state === 'investigating'
            ? 'identified'
            : 'monitoring';
    form.clearErrors();
});

function submit(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    if (props.incident) {
        form.transform((data) => ({
            state: data.state,
            message: data.message,
            impact: data.impact,
        })).post(`/status-incidents/${props.incident.id}/updates`, done);
    } else {
        form.transform((data) => data).post('/status-incidents', done);
    }
}

function remove(): void {
    if (!props.incident) return;
    if (
        !window.confirm(
            trans('Delete “:title”? It goes from the status page for good.', {
                title: props.incident.title,
            }),
        )
    )
        return;
    router.delete(`/status-incidents/${props.incident.id}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[90dvh] gap-0 overflow-y-auto p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ incident ? incident.title : $t('New incident') }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        <template v-if="incident">
                            {{
                                incident.resolvedAt
                                    ? stateLabel('resolved')
                                    : impactLabel(incident.impact)
                            }}
                            · {{ incident.machine ?? $t('Workspace page') }} ·
                            {{ when(incident.startedAt) }}
                        </template>
                        <template v-else>
                            {{
                                $t(
                                    'Shown on your status pages straight away, in your words.',
                                )
                            }}
                        </template>
                    </DialogDescription>
                </div>

                <!-- What's been said so far, newest first -->
                <ol
                    v-if="incident"
                    class="mx-5 mt-4 flex flex-col gap-3 border-l border-(--ds-divider) pl-4"
                >
                    <li
                        v-for="(entry, index) in incident.updates"
                        :key="index"
                        class="text-[13px]/5"
                    >
                        <span class="font-medium">{{
                            stateLabel(entry.state)
                        }}</span>
                        <span class="text-(--ds-text-faint)">
                            · {{ when(entry.at) }}</span
                        >
                        <p
                            class="whitespace-pre-line text-(--ds-text-secondary)"
                        >
                            {{ entry.message }}
                        </p>
                    </li>
                </ol>

                <div v-if="canManage" class="flex flex-col gap-4 p-5">
                    <div v-if="!incident" class="flex flex-col gap-1.5">
                        <label for="incident-title" :class="labelClass">{{
                            $t('Title')
                        }}</label>
                        <Input
                            id="incident-title"
                            v-model="form.title"
                            maxlength="120"
                            autocomplete="off"
                            :placeholder="$t('Checkout is failing')"
                            required
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Impact') }}</span>
                        <div
                            class="ds-segments self-start"
                            role="group"
                            :aria-label="$t('Impact')"
                        >
                            <button
                                v-for="impact in impacts"
                                :key="impact.value"
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="form.impact === impact.value"
                                @click="form.impact = impact.value"
                            >
                                {{ impact.label }}
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Status') }}</span>
                        <div
                            class="ds-segments self-start"
                            role="group"
                            :aria-label="$t('Status')"
                        >
                            <button
                                v-for="state in incident
                                    ? states
                                    : states.filter(
                                          (s) => s.value !== 'resolved',
                                      )"
                                :key="state.value"
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="form.state === state.value"
                                @click="form.state = state.value"
                            >
                                {{ state.label }}
                            </button>
                        </div>
                        <InputError :message="form.errors.state" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="incident-message" :class="labelClass">{{
                            incident ? $t('Update') : $t('Message')
                        }}</label>
                        <textarea
                            id="incident-message"
                            v-model="form.message"
                            rows="3"
                            maxlength="5000"
                            required
                            :class="textareaClass"
                            :placeholder="
                                form.state === 'resolved'
                                    ? $t(
                                          'It’s fixed, and everything is working again.',
                                      )
                                    : incident
                                      ? $t(
                                            'What’s changed since the last update.',
                                        )
                                      : $t('We’re looking into it.')
                            "
                        />
                        <InputError :message="form.errors.message" />
                    </div>

                    <div
                        v-if="!incident && machines.length"
                        class="flex items-center justify-between gap-4"
                    >
                        <span class="flex flex-col">
                            <label
                                for="incident-machine"
                                class="text-[13px]/5 font-medium"
                                >{{ $t('Machine') }}</label
                            >
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t(
                                    'Also shows it on that machine’s status page.',
                                )
                            }}</span>
                        </span>
                        <select
                            id="incident-machine"
                            :value="form.machine_id ?? ''"
                            class="ds-select ds-raised-button h-8 max-w-44 cursor-pointer appearance-none pr-8! text-[13px]"
                            @change="
                                (e) => {
                                    const id = Number(
                                        (e.target as HTMLSelectElement).value,
                                    );
                                    form.machine_id = id || null;
                                }
                            "
                        >
                            <option value="">{{ $t('No machine') }}</option>
                            <option
                                v-for="machine in machines"
                                :key="machine.id"
                                :value="machine.id"
                            >
                                {{ machine.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <div v-else class="h-5" />

                <div
                    v-if="canManage"
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        v-if="incident"
                        size="sm"
                        variant="quiet"
                        class="text-(--ds-red)!"
                        @click="remove"
                    >
                        {{ $t('Delete') }}
                    </AppButton>
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="
                            !incident
                                ? $t('Post incident')
                                : form.state === 'resolved'
                                  ? $t('Resolve')
                                  : $t('Post update')
                        "
                        busy-label="Posting…"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
