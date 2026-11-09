<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { appButtonClasses } from '@/lib/appButton';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: trans('Reset password'),
        description: trans('Please enter your new password below'),
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);

/** What's typed in the new password, for the strength meter. */
const password = ref('');
function onPassword(event: Event): void {
    password.value = (event.target as HTMLInputElement).value;
}
</script>

<template>
    <Head :title="$t('Reset password')" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">{{ $t('Email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    @input="onPassword"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    :placeholder="$t('Password')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
                <PasswordStrength :password="password" :rules="passwordRules" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    {{ ' ' + $t('Confirm password') + ' ' }}
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    :placeholder="$t('Confirm password')"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <button
                :class="appButtonClasses('primary', 'md', 'mt-4 w-full')"
                type="submit"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                {{ $t('Reset password') + ' ' }}
            </button>
        </div>
    </Form>
</template>
