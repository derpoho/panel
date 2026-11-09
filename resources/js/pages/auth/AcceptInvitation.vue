<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { dashboard, logout } from '@/routes';
import { accept } from '@/routes/invitations';

type Props = {
    token: string;
    status: 'valid' | 'invalid' | 'expired' | 'wrong-account';
    invitation: {
        email: string;
        role: string;
        workspace: string;
        host: string;
        invitedBy: string | null;
    } | null;
};

const props = defineProps<Props>();

const title = computed(() => {
    switch (props.status) {
        case 'valid':
            return trans('Join :workspace', {
                workspace: String(props.invitation?.workspace),
            });
        case 'expired':
            return trans('This invite has expired');
        case 'wrong-account':
            return trans('This invite is for someone else');
        default:
            return trans('This invite isn’t valid');
    }
});

defineOptions({
    layout: {
        title: '',
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 text-center">
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[15px]/5 font-medium text-(--ds-text)">
                {{ title }}
            </h2>

            <p
                v-if="status === 'valid' && invitation"
                class="text-[13px]/5 text-(--ds-text-muted)"
            >
                {{
                    $t(':value invited you to', {
                        value: invitation.invitedBy ?? $t('Someone'),
                    })
                }}
                <span class="text-(--ds-text)">{{ invitation.host }}</span>
                {{
                    $t('as :value :role.', {
                        value: invitation.role === 'Admin' ? 'an' : 'a',
                        role: invitation.role,
                    }) + ' '
                }}
            </p>
            <p
                v-else-if="status === 'wrong-account' && invitation"
                class="text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ ' ' + $t('It was sent to') }}
                <span class="text-(--ds-text)">{{ invitation.email }}</span
                >{{ $t('. Sign in with that email to accept it.') + ' ' }}
            </p>
            <p
                v-else-if="status === 'expired'"
                class="text-[13px]/5 text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t('Ask someone in :value to send a new one.', {
                        value: invitation?.workspace ?? $t('the workspace'),
                    }) +
                    ' '
                }}
            </p>
            <p v-else class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    ' ' + $t('It may have been revoked or already used.') + ' '
                }}
            </p>
        </div>

        <Form
            v-if="status === 'valid'"
            v-bind="accept.form(token)"
            v-slot="{ processing }"
        >
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'w-full')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ $t('Accept invite') + ' ' }}
            </button>
        </Form>

        <Link
            v-else-if="status === 'wrong-account'"
            :href="logout()"
            as="button"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            {{ ' ' + $t('Sign out') + ' ' }}
        </Link>

        <Link
            :href="dashboard()"
            class="text-[13px]/4 text-(--ds-text-muted) underline-offset-4 hover:underline"
        >
            {{ ' ' + $t('Go to your workspace') + ' ' }}
        </Link>
    </div>
</template>
