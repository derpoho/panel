<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { appButtonClasses } from '@/lib/appButton';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: trans('Email verification'),
        description: trans(
            'Please verify your email address by clicking on the link we just emailed to you.',
        ),
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Email verification')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 rounded-lg bg-(--ds-green)/10 px-3 py-2 text-center text-[13px] font-medium text-(--ds-green)"
    >
        {{
            ' ' +
            $t(
                'A new verification link has been sent to the email address you provided during registration.',
            ) +
            ' '
        }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <button :class="appButtonClasses('secondary')" :disabled="processing">
            <Spinner v-if="processing" />
            {{ $t('Resend verification email') + ' ' }}
        </button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ ' ' + $t('Log out') + ' ' }}
        </TextLink>
    </Form>
</template>
