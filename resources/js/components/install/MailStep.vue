<script setup lang="ts">
import { Form, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { postJson } from '@/lib/install';
import type { Check } from '@/lib/install';
import CheckLines from './CheckLines.vue';
import StepHeading from './StepHeading.vue';
import { stepUrl } from './types';
import type { MailProps } from './types';

/*
 * Email, which can wait: until it's set up the panel only writes emails to
 * its log. SMTP settings, tried with a real email before they're saved.
 */
const props = defineProps<{ mail: MailProps; docker: boolean }>();

const inner = ref<HTMLElement | null>(null);
const to = ref('');
const sending = ref(false);
const sent = ref<Check[] | null>(null);
const skipping = ref(false);

async function sendTest(): Promise<void> {
    const form = inner.value?.closest('form') ?? undefined;
    sending.value = true;
    sent.value = null;
    try {
        const result = await postJson<{ ok: boolean; message: string }>(
            '/install/mail/test',
            {
                ...Object.fromEntries(new FormData(form).entries()),
                to: to.value,
            },
        );
        sent.value = [
            { status: result.ok ? 'ok' : 'fail', text: result.message },
        ];
    } catch (error) {
        sent.value = [{ status: 'fail', text: (error as Error).message }];
    } finally {
        sending.value = false;
    }
}

function skip(): void {
    router.post(
        '/install/mail/skip',
        {},
        {
            onStart: () => (skipping.value = true),
            onFinish: () => (skipping.value = false),
        },
    );
}

const port = String(props.mail.port ?? '587');
</script>

<template>
    <StepHeading
        :title="$t('Email')"
        :description="
            $t(
                'For invitations, alerts and password resets. You can skip it for now: until it’s set up, emails only go to the panel’s log.',
            )
        "
    />

    <div v-if="!mail.editable" class="flex flex-col gap-6">
        <p class="text-[13.5px]/5 text-(--ds-text-secondary)">
            {{
                $t(
                    'Email is set outside the panel (the MAIL_* settings), so it’s changed there.',
                )
            }}
        </p>
        <div class="flex gap-2">
            <Link
                :href="stepUrl('address')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="button"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="skipping"
                @click="skip"
            >
                {{ $t('Continue') }}
            </button>
        </div>
    </div>

    <Form
        v-else
        action="/install/mail"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div ref="inner" class="contents">
            <div class="grid grid-cols-[1fr_6rem] gap-3">
                <div class="grid gap-2">
                    <Label for="host">{{ $t('SMTP server') }}</Label>
                    <Input
                        id="host"
                        name="host"
                        required
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="smtp.example.com"
                        :default-value="mail.host ?? ''"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="port">{{ $t('Port') }}</Label>
                    <Input
                        id="port"
                        name="port"
                        required
                        inputmode="numeric"
                        :default-value="port"
                    />
                </div>
            </div>
            <InputError :message="errors.host ?? errors.port" />
            <div class="grid gap-2">
                <Label for="username">{{ $t('Username') }}</Label>
                <Input
                    id="username"
                    name="username"
                    autocomplete="off"
                    :default-value="mail.username ?? ''"
                />
                <InputError :message="errors.username" />
            </div>
            <div class="grid gap-2">
                <Label for="mail_password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="mail_password"
                    name="mail_password"
                    autocomplete="new-password"
                    :placeholder="
                        mail.hasPassword
                            ? $t('Leave empty to keep the saved one')
                            : ''
                    "
                />
                <InputError :message="errors.mail_password" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="from_address">{{ $t('From address') }}</Label>
                    <Input
                        id="from_address"
                        name="from_address"
                        type="email"
                        required
                        placeholder="serveros@example.com"
                        :default-value="mail.fromAddress ?? ''"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="from_name">{{ $t('From name') }}</Label>
                    <Input
                        id="from_name"
                        name="from_name"
                        :default-value="mail.fromName"
                    />
                </div>
            </div>
            <InputError :message="errors.from_address ?? errors.from_name" />
        </div>

        <div class="flex flex-col gap-2 border-t border-(--ds-divider) pt-5">
            <Label for="to">{{ $t('Send a test to') }}</Label>
            <div class="flex gap-2">
                <Input
                    id="to"
                    v-model="to"
                    type="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    class="grow"
                />
                <button
                    type="button"
                    :class="appButtonClasses('secondary', 'md', 'h-9')"
                    :disabled="sending || !to"
                    @click="sendTest"
                >
                    <Spinner v-if="sending" />
                    {{ $t('Send') }}
                </button>
            </div>
        </div>
        <CheckLines v-if="sent" :lines="sent" />

        <div class="flex gap-2">
            <Link
                :href="stepUrl('address')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="button"
                :class="appButtonClasses('quiet', 'md')"
                :disabled="skipping || processing"
                @click="skip"
            >
                {{ $t('Skip for now') }}
            </button>
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ $t('Save') }}
            </button>
        </div>
    </Form>
</template>
