<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { shake } from '@/lib/shake';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { appButtonClasses } from '@/lib/appButton';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import {
    login as passkeyLogin,
    loginOptions as passkeyLoginOptions,
} from '@/routes/passkey';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import OAuthButtons from '@/components/OAuthButtons.vue';
import AuthDivider from '@/components/AuthDivider.vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import type { Component } from 'vue';
import LastUsedPill from '@/components/LastUsedPill.vue';

// Parts the private packages put on this page, for its ExtensionSlots.
const slotParts = import.meta.glob<Component>(
    '../../../../vendor/serveroshq/*/resources/js/slots/auth/Login/**/*.vue',
    { eager: true, import: 'default' },
);

defineOptions({
    layout: {
        title: trans('Sign in'),
        description: trans('Welcome back. Sign in to continue.'),
    },
    // Props packages add to the page reach their slots through usePage(), not here.
    inheritAttrs: false,
});

defineProps<{
    status?: string;
    /** Why signing in with Google or GitHub didn't work. */
    error?: string;
    canResetPassword: boolean;
    oauthProviders: string[];
    /** How this browser last signed in, marked "Last used". */
    lastUsed?: 'password' | 'passkey' | 'google' | 'github' | 'sso' | null;
}>();

/* Our own checks before sending, in place of the browser's bubbles */
const local = ref<{ email?: string; password?: string }>({});

function check(event: Event): void {
    const email = document.getElementById('email') as HTMLInputElement | null;
    const password = document.getElementById(
        'password',
    ) as HTMLInputElement | null;
    const errors: { email?: string; password?: string } = {};

    if (!email?.value.trim())
        errors.email = trans('We need your email address.');
    else if (!email.validity.valid)
        errors.email = trans('That doesn’t look like an email address.');
    if (!password?.value) errors.password = trans('Enter your password.');

    local.value = errors;
    if (errors.email || errors.password) {
        event.preventDefault();
        shake(errors.email ? email : password);
    }
}

/** When the server turns it down (wrong password and so on), shake too. */
function onError(errors: Record<string, string>): void {
    shake(
        document.getElementById(
            errors.email ? 'email' : 'password',
        ) as HTMLInputElement | null,
    );
}

function clear(field: 'email' | 'password'): void {
    if (local.value[field])
        local.value = { ...local.value, [field]: undefined };
}
</script>

<template>
    <Head :title="$t('Sign in')" />

    <div
        v-if="status"
        class="mb-4 rounded-lg bg-(--ds-green)/10 px-3 py-2 text-center text-[13px] font-medium text-(--ds-green)"
    >
        {{ status }}
    </div>
    <div
        v-if="error"
        role="alert"
        class="mb-4 rounded-lg bg-(--ds-red)/10 px-3 py-2 text-center text-[13px] font-medium text-(--ds-red)"
    >
        {{ error }}
    </div>
    <!-- Packages' notices: panel-ee's, when a company that requires SSO
         turned a sign-in down. -->
    <ExtensionSlot name="auth/Login/notices" :parts="slotParts" />

    <div class="grid gap-2">
        <OAuthButtons :providers="oauthProviders" :last-used="lastUsed" />
        <!-- A passkey keeps you signed in, as Google and GitHub do: the
             passkey client only sends the credential, so ask for it here. -->
        <PasskeyVerify
            :routes="{
                options: passkeyLoginOptions(),
                submit: passkeyLogin({ query: { remember: 1 } }),
            }"
            hide-separator
            :last-used="lastUsed === 'passkey'"
        />
        <!-- Packages' ways in: panel-ee's Continue with SSO. -->
        <ExtensionSlot
            :parts="slotParts"
            name="auth/Login/sign-in-options"
            :last-used="lastUsed"
        />
    </div>
    <AuthDivider />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        novalidate
        @error="onError"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <div class="flex items-center gap-2">
                    <Label for="email">{{ $t('Email address') }}</Label>
                    <LastUsedPill
                        v-if="lastUsed === 'password'"
                        class="-my-0.5"
                    />
                </div>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    :placeholder="$t('email@example.com')"
                    :aria-invalid="!!(local.email || errors.email)"
                    @input="clear('email')"
                />
                <InputError :message="local.email ?? errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">{{ $t('Password') }}</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-[12px] text-(--ds-text-muted) hover:text-(--ds-text)"
                        :tabindex="5"
                    >
                        {{ ' ' + $t('Forgot your password?') + ' ' }}
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    :placeholder="$t('Password')"
                    :aria-invalid="!!(local.password || errors.password)"
                    @input="clear('password')"
                />
                <InputError :message="local.password ?? errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>{{ $t('Remember me') }}</span>
                </Label>
            </div>

            <button
                :class="appButtonClasses('primary', 'md', 'mt-4 w-full')"
                type="submit"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
                @click="check"
            >
                <Spinner v-if="processing" />
                {{ $t('Sign in') + ' ' }}
            </button>
        </div>

        <div
            class="auth-foot -mx-8 mt-8 -mb-8 border-t border-(--ds-divider) bg-(--ds-subtle) px-8 py-3.5 text-center text-[12.5px] text-(--ds-text-muted)"
        >
            {{ ' ' + $t('New to ServerOS?') }}
            <TextLink :href="register()" :tabindex="5">{{
                $t('Create an account')
            }}</TextLink>
        </div>
    </Form>
</template>
