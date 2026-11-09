<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { appButtonClasses } from '@/lib/appButton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: trans('Forgot password'),
        description: trans('Enter your email to receive a password reset link'),
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Forgot password')" />

    <div
        v-if="status"
        class="mb-4 rounded-lg bg-(--ds-green)/10 px-3 py-2 text-center text-[13px] font-medium text-(--ds-green)"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    :placeholder="$t('email@example.com')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <button
                    :class="appButtonClasses('primary', 'md', 'w-full')"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Email password reset link') + ' ' }}
                </button>
            </div>
        </Form>

        <div
            class="auth-foot -mx-8 mt-8 -mb-8 space-x-1 border-t border-(--ds-divider) bg-(--ds-subtle) px-8 py-3.5 text-center text-[12.5px] text-(--ds-text-muted)"
        >
            <span>{{ $t('Or, return to') }}</span>
            <TextLink :href="login()">{{ $t('log in') }}</TextLink>
        </div>
    </div>
</template>
