<script setup lang="ts">
import LastUsedPill from '@/components/LastUsedPill.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import { appButtonClasses } from '@/lib/appButton';

/*
 * Sign in or sign up with Google or GitHub. Plain links, not Inertia ones:
 * the flow leaves the site for the provider and comes back.
 */
defineProps<{
    /** The providers with keys set; the others aren't shown. */
    providers: string[];
    /** How this browser last signed in, to mark that button. */
    lastUsed?: string | null;
}>();
</script>

<template>
    <div v-if="providers.length" class="grid gap-2">
        <a
            v-if="providers.includes('google')"
            href="/auth/google/redirect"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            <ProviderLogo provider="google" class="size-4" />
            {{ $t('Continue with Google') + ' ' }}
            <LastUsedPill v-if="lastUsed === 'google'" />
        </a>
        <a
            v-if="providers.includes('github')"
            href="/auth/github/redirect"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            <ProviderLogo provider="github" class="size-4" />
            {{ $t('Continue with GitHub') + ' ' }}
            <LastUsedPill v-if="lastUsed === 'github'" />
        </a>
    </div>
</template>
