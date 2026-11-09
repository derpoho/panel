<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import OAuthButtons from '@/components/OAuthButtons.vue';
import AuthDivider from '@/components/AuthDivider.vue';
import { track } from '@/lib/analytics';
import { appButtonClasses } from '@/lib/appButton';
import { shake } from '@/lib/shake';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import WorkspaceSlugInput from '@/components/WorkspaceSlugInput.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';

/*
 * Signing up in two short steps inside one form: who you are, then a
 * password (and, if you like, your workspace's address). Both steps stay
 * in the page, so a single submit sends everything; if the server
 * rejects something from the first step, it opens again.
 */
const props = defineProps<{
    passwordRules: string;
    workspaceDomain: string;
    oauthProviders: string[];
    /** Cloudflare Turnstile, when it's set up. */
    turnstileSiteKey: string | null;
    /** Only on a self-hosted install, which has no trial or plans. */
    selfHosted?: boolean;
}>();

defineOptions({
    layout: {
        title: trans('Create your account'),
        description: trans('Start with 14 days of Pro, free.'),
    },
});

if (props.selfHosted) {
    setLayoutProps({
        title: trans('Create your account'),
        description: trans('Your own ServerOS panel. No card, no trial.'),
    });
}

const step = ref<1 | 2>(1);
const firstStep = ref<HTMLElement | null>(null);

/* Our own checks for step one, in place of the browser's bubbles */
const local = ref<{ name?: string; email?: string }>({});

/** Moves on only once the browser is happy with the name and email. */
function next(): void {
    const name = firstStep.value?.querySelector<HTMLInputElement>('#name');
    const email = firstStep.value?.querySelector<HTMLInputElement>('#email');
    const errors: { name?: string; email?: string } = {};

    if (!name?.value.trim()) errors.name = trans('Tell us your name.');
    if (!email?.value.trim())
        errors.email = trans('We need your email address.');
    else if (!email.validity.valid)
        errors.email = trans('That doesn’t look like an email address.');

    local.value = errors;
    const first = errors.name ? name : errors.email ? email : null;
    if (first) {
        shake(first);
        return;
    }
    step.value = 2;
    track('signup_step_completed', { step: 1 });
}

/** Clears a field's message as soon as they start fixing it. */
function clear(field: 'name' | 'email'): void {
    if (local.value[field])
        local.value = { ...local.value, [field]: undefined };
}

const turnstile =
    useTemplateRef<InstanceType<typeof TurnstileWidget>>('turnstile');

function onError(errors: Record<string, string>): void {
    track('signup_failed', { fields: Object.keys(errors) });
    if (errors.name || errors.email) step.value = 1;
    // Its token was used up by this submit: get a fresh one.
    turnstile.value?.reset();
}

/** What's typed in the new password, for the strength meter. */
const password = ref('');
function onPassword(event: Event): void {
    password.value = (event.target as HTMLInputElement).value;
}
</script>

<template>
    <Head :title="$t('Create account')" />

    <div class="mb-6 flex items-center gap-3">
        <div class="flex grow gap-1.5" aria-hidden="true">
            <span class="h-1 flex-1 rounded-full bg-[#3B7BFF]" />
            <span
                class="h-1 flex-1 rounded-full transition-colors duration-500"
                :class="step === 2 ? 'bg-[#3B7BFF]' : 'bg-(--ds-border-strong)'"
            />
        </div>
        <span class="shrink-0 text-[12px]/4 font-medium text-(--ds-text-muted)">
            {{
                ' ' + $t('Step :step of 2', { step: String(step ?? '') }) + ' '
            }}
        </span>
    </div>

    <template v-if="step === 1 && oauthProviders.length">
        <OAuthButtons :providers="oauthProviders" />
        <AuthDivider />
    </template>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        @error="onError"
        @start="track('signup_submitted')"
        novalidate
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div v-show="step === 1" ref="firstStep" class="grid gap-5">
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    autocomplete="name"
                    name="name"
                    :placeholder="$t('Full name')"
                    :aria-invalid="!!(local.name || errors.name)"
                    @input="clear('name')"
                    @keydown.enter.prevent="next"
                />
                <InputError :message="local.name ?? errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    :placeholder="$t('email@example.com')"
                    :aria-invalid="!!(local.email || errors.email)"
                    @input="clear('email')"
                    @keydown.enter.prevent="next"
                />
                <InputError :message="local.email ?? errors.email" />
            </div>

            <button
                type="button"
                :class="appButtonClasses('primary', 'md', 'mt-1 w-full')"
                @click="next"
            >
                {{ ' ' + $t('Continue') + ' ' }}
            </button>
        </div>

        <div v-show="step === 2" class="cs-rise grid gap-5">
            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    @input="onPassword"
                    :placeholder="$t('Password')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
                <PasswordStrength :password="password" :rules="passwordRules" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    $t('Confirm password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    :placeholder="$t('Confirm password')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <WorkspaceSlugInput
                :domain="workspaceDomain"
                :error="errors.workspace"
            />

            <div v-if="turnstileSiteKey" class="grid gap-2">
                <TurnstileWidget ref="turnstile" :site-key="turnstileSiteKey" />
                <InputError :message="errors.turnstile" />
            </div>

            <div class="mt-1 flex gap-2">
                <button
                    type="button"
                    :class="
                        appButtonClasses('secondary', 'md', 'shrink-0 px-3')
                    "
                    :aria-label="$t('Back to name and email')"
                    @click="step = 1"
                >
                    <ArrowLeft class="size-4" :stroke-width="2" />
                </button>
                <button
                    :class="appButtonClasses('primary', 'md', 'grow')"
                    type="submit"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Create account') + ' ' }}
                </button>
            </div>
        </div>

        <div
            class="auth-foot -mx-8 mt-8 -mb-8 border-t border-(--ds-divider) bg-(--ds-subtle) px-8 py-3.5 text-center text-[12.5px] text-(--ds-text-muted)"
        >
            {{ ' ' + $t('Already have an account?') }}
            <TextLink :href="login()" class="underline underline-offset-4">{{
                $t('Sign in')
            }}</TextLink>
        </div>
    </Form>
</template>
