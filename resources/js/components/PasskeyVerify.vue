<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { SolarKeyRound as KeyRound } from '@/lib/solarIcons';
import InputError from '@/components/InputError.vue';
import LastUsedPill from '@/components/LastUsedPill.vue';
import { appButtonClasses } from '@/lib/appButton';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
    /** Hide the divider when the page draws its own after other sign-in options. */
    hideSeparator?: boolean;
    /** Mark the button as how this browser last signed in. */
    lastUsed?: boolean;
};

const props = defineProps<Props>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    ...(props.routes
        ? {
              routes: {
                  options: props.routes.options.url,
                  submit: props.routes.submit.url,
              },
          }
        : {}),
    onSuccess: (response) => {
        router.visit(response.redirect ?? '/dashboard');
    },
});
</script>

<template>
    <div v-if="isSupported">
        <div class="grid gap-2">
            <button
                :class="appButtonClasses('secondary', 'md', 'w-full')"
                type="button"
                @click="verify"
                :disabled="isLoading"
            >
                <Spinner v-if="isLoading" />
                <KeyRound v-else class="h-4 w-4" />
                {{
                    isLoading
                        ? (props.loadingLabel ?? $t('Authenticating...'))
                        : (props.label ?? $t('Sign in with a passkey'))
                }}
                <LastUsedPill v-if="props.lastUsed && !isLoading" />
            </button>

            <div v-if="error" class="text-center">
                <InputError :message="error" />
            </div>
        </div>

        <div v-if="!props.hideSeparator" class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <Separator class="w-full" />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-background px-2 text-muted-foreground">
                    {{ props.separator ?? $t('Or continue with email') }}
                </span>
            </div>
        </div>
    </div>
</template>
