<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import StepHeading from './StepHeading.vue';
import { stepUrl } from './types';
import type { OwnerProps } from './types';

/* The owner's account and first workspace, made when they finish. */
const props = defineProps<{ owner: OwnerProps }>();

const password = ref('');
const workspace = ref(props.owner.saved?.workspace ?? '');
</script>

<template>
    <StepHeading
        :title="$t('Your account')"
        :description="
            $t(
                'You’ll own this install. Your email is trusted as it is, so you can sign in before email is set up.',
            )
        "
    />

    <Form
        action="/install/owner"
        method="post"
        :reset-on-error="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-2">
            <Label for="name">{{ $t('Name') }}</Label>
            <Input
                id="name"
                name="name"
                required
                v-focus
                autocomplete="name"
                :default-value="owner.saved?.name ?? ''"
            />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="email">{{ $t('Email address') }}</Label>
            <Input
                id="email"
                name="email"
                type="email"
                required
                autocomplete="email"
                :default-value="owner.saved?.email ?? ''"
            />
            <InputError :message="errors.email" />
        </div>
        <div class="grid gap-2">
            <Label for="password">{{ $t('Password') }}</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                :passwordrules="owner.passwordRules"
                @input="password = ($event.target as HTMLInputElement).value"
            />
            <InputError :message="errors.password" />
            <PasswordStrength
                :password="password"
                :rules="owner.passwordRules"
            />
        </div>
        <div class="grid gap-2">
            <Label for="password_confirmation">{{
                $t('Confirm password')
            }}</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :passwordrules="owner.passwordRules"
            />
            <InputError :message="errors.password_confirmation" />
        </div>
        <div class="grid gap-2">
            <Label for="workspace">{{ $t('Workspace name') }}</Label>
            <Input
                id="workspace"
                v-model="workspace"
                name="workspace"
                required
                :placeholder="$t('Acme')"
            />
            <p class="text-[12.5px]/4 text-(--ds-text-muted)">
                {{
                    $t(
                        'Your first workspace, for your machines and your team. You can make more later.',
                    )
                }}
            </p>
            <InputError :message="errors.workspace" />
        </div>

        <div class="flex gap-2">
            <Link
                :href="stepUrl('mail')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ $t('Continue') }}
            </button>
        </div>
    </Form>
</template>
