<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';

/**
 * Where alerts go: email to the workspace's owners and admins, and a
 * webhook (a Slack or Discord incoming webhook works as is). And which
 * kinds go out at all, so a noisy one can be switched off on its own.
 */
const props = defineProps<{
    alerts: { email: boolean; webhook: string | null; muted?: string[] };
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({ email: true, webhook: '', muted: [] as string[] });

const kinds = [
    {
        key: 'machines',
        label: trans('A machine stops reporting'),
        hint: trans('And when it’s back.'),
    },
    {
        key: 'resources',
        label: trans('Disk or memory runs low'),
        hint: null,
    },
    {
        key: 'services',
        label: trans('A service stops or keeps restarting'),
        hint: trans('Not when you stop it, or a deploy or Swarm replaces it.'),
    },
    { key: 'deploys', label: trans('A deploy fails'), hint: null },
    { key: 'backups', label: trans('A backup fails'), hint: null },
    {
        key: 'monitors',
        label: trans('A monitor goes down'),
        hint: trans('And two weeks before a certificate expires.'),
    },
    { key: 'workflows', label: trans('A workflow fails'), hint: null },
    {
        key: 'updates',
        label: trans('A daemon update waits for approval'),
        hint: trans('And when one is installed, on machines that ask first.'),
    },
] as const;

function sends(kind: string): boolean {
    return !form.muted.includes(kind);
}

function setSends(kind: string, on: boolean): void {
    form.muted = on
        ? form.muted.filter((k) => k !== kind)
        : [...form.muted, kind];
}
const test = ref<{ ok: boolean; message: string } | null>(null);
const testing = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) return;
    form.defaults({
        email: props.alerts.email,
        webhook: props.alerts.webhook ?? '',
        muted: [...(props.alerts.muted ?? [])],
    });
    form.reset();
    form.clearErrors();
    test.value = null;
});

async function sendTest(): Promise<void> {
    testing.value = true;
    test.value = null;

    try {
        const response = await fetch('/monitor-alerts/test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({ webhook: form.webhook }),
        });
        const body = await response.json();

        test.value = response.ok
            ? body.ok
                ? { ok: true, message: trans('Sent. Check the channel.') }
                : { ok: false, message: body.error }
            : {
                  ok: false,
                  message:
                      body.errors?.webhook?.[0] ??
                      body.message ??
                      trans('Couldn’t send it.'),
              };
    } catch {
        test.value = { ok: false, message: trans('Couldn’t send it.') };
    } finally {
        testing.value = false;
    }
}

function submit(): void {
    form.put('/monitor-alerts', {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ ' ' + $t('Alerts') + ' ' }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Where alerts go, and which ones are worth hearing about.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div
                    class="flex max-h-[min(70dvh,40rem)] flex-col gap-5 overflow-y-auto p-5"
                >
                    <div class="flex items-center justify-between gap-4">
                        <span class="flex flex-col">
                            <span class="text-[13px]/5 font-medium">{{
                                $t('Email')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-muted)">
                                {{
                                    ' ' +
                                    $t(
                                        'To the workspace’s owners and admins.',
                                    ) +
                                    ' '
                                }}
                            </span>
                        </span>
                        <ToggleSwitch
                            v-model="form.email"
                            :label="$t('Email alerts')"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label
                            for="alerts-webhook"
                            class="text-[13px]/5 font-medium"
                        >
                            {{ ' ' + $t('Webhook') + ' ' }}
                        </label>
                        <div class="flex gap-2">
                            <Input
                                id="alerts-webhook"
                                v-model="form.webhook"
                                type="url"
                                :placeholder="
                                    $t('https://hooks.slack.com/services/…')
                                "
                                autocomplete="off"
                                spellcheck="false"
                                class="min-w-0 grow font-ui-mono text-[13px]"
                                @input="test = null"
                            />
                            <AppButton
                                :disabled="!form.webhook || testing"
                                @click="sendTest"
                            >
                                {{ testing ? $t('Sending…') : $t('Send test') }}
                            </AppButton>
                        </div>
                        <span class="text-xs/4 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'A Slack or Discord incoming webhook, or any https address that takes a JSON POST.',
                                ) +
                                ' '
                            }}
                        </span>
                        <p
                            v-if="test"
                            class="text-xs/4"
                            :class="
                                test.ok
                                    ? 'text-(--ds-green)'
                                    : 'text-(--ds-red)'
                            "
                            role="status"
                        >
                            {{ test.message }}
                        </p>
                        <InputError :message="form.errors.webhook" />
                    </div>

                    <fieldset class="flex flex-col">
                        <legend class="mb-1 text-[13px]/5 font-medium">
                            {{ ' ' + $t('What to send') + ' ' }}
                        </legend>
                        <div
                            v-for="kind in kinds"
                            :key="kind.key"
                            class="flex items-center justify-between gap-4 border-b border-(--ds-divider) py-2.5 last:border-b-0"
                        >
                            <span class="flex flex-col">
                                <span class="text-[13px]/5">{{
                                    kind.label
                                }}</span>
                                <span
                                    v-if="kind.hint"
                                    class="text-xs/4 text-(--ds-text-muted)"
                                    >{{ kind.hint }}</span
                                >
                            </span>
                            <ToggleSwitch
                                :model-value="sends(kind.key)"
                                :label="kind.label"
                                @update:model-value="
                                    (on: boolean) => setSends(kind.key, on)
                                "
                            />
                        </div>
                        <InputError :message="form.errors.muted" />
                    </fieldset>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Save')"
                        busy-label="Saving…"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
