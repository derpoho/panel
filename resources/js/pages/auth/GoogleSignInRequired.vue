<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { logout } from '@/routes';

/**
 * A workspace that requires Google sign-in, opened on a session that
 * didn't come through Google on one of its domains. The button signs out
 * and goes through Google, then comes back here.
 */
const props = defineProps<{
    workspace: string;
    domains: string[];
    email: string;
    switchUrl: string;
}>();

const domainList = computed(() => props.domains.join(', '));

defineOptions({
    layout: {
        title: '',
    },
});
</script>

<template>
    <Head :title="$t('Sign in with Google')" />

    <div class="flex flex-col gap-5 text-center">
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[15px]/5 font-medium text-(--ds-text)">
                {{
                    $t('Sign in with Google to open :workspace', { workspace })
                }}
            </h2>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    domains.length === 1
                        ? $t(
                              ':workspace asks you to sign in with Google using your :domain account.',
                              { workspace, domain: domainList },
                          )
                        : $t(
                              ':workspace asks you to sign in with Google using an account on :domains.',
                              { workspace, domains: domainList },
                          )
                }}
            </p>
            <p class="text-[13px]/5 text-(--ds-text-faint)">
                {{ $t('You’re signed in as :email.', { email }) }}
            </p>
        </div>

        <Form :action="switchUrl" method="post" v-slot="{ processing }">
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'w-full')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                <ProviderLogo v-else provider="google" class="size-4" />
                {{ $t('Sign in with Google') }}
            </button>
        </Form>

        <Link
            :href="logout()"
            as="button"
            class="cursor-pointer text-[13px]/4 text-(--ds-text-muted) underline-offset-4 hover:underline"
        >
            {{ $t('Sign out') }}
        </Link>
    </div>
</template>
