<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import {
    SolarEye as Eye,
    SolarEyeOff as EyeOff,
    SolarLockKeyhole as LockKeyhole,
} from '@/lib/solarIcons';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-[10px] border border-(--ds-border) bg-(--ds-subtle)/40 p-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-0.5">
                <span class="flex items-center gap-2 text-sm font-medium">
                    <LockKeyhole class="size-3.5" :stroke-width="2" />
                    {{ $t('Recovery codes') + ' ' }}
                </span>
                <span class="max-w-lg text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Get back in if you lose your authenticator app. Keep them somewhere safe, like a password manager.',
                        ) +
                        ' '
                    }}
                </span>
            </div>
            <div class="flex flex-wrap gap-2 select-none">
                <AppButton size="sm" @click="toggleRecoveryCodesVisibility">
                    <component
                        :is="isRecoveryCodesVisible ? EyeOff : Eye"
                        class="size-3.5"
                    />
                    {{
                        $t(':value codes', {
                            value: isRecoveryCodesVisible
                                ? $t('Hide')
                                : $t('Show'),
                        }) + ' '
                    }}
                </AppButton>
                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        type="submit"
                        :disabled="processing"
                    >
                        <RefreshCw class="size-3.5" />
                        {{ $t('New codes') + ' ' }}
                    </AppButton>
                </Form>
            </div>
        </div>

        <template v-if="isRecoveryCodesVisible">
            <AlertError v-if="errors?.length" :errors="errors" />
            <template v-else>
                <div
                    ref="recoveryCodeSectionRef"
                    class="grid gap-1 rounded-md border border-(--ds-border) bg-(--ds-field) p-3 font-ui-mono text-[13px]/5 sm:grid-cols-2"
                >
                    <template v-if="!recoveryCodesList.length">
                        <div
                            v-for="n in 8"
                            :key="n"
                            class="h-4 animate-pulse rounded bg-(--ds-hover)"
                        />
                    </template>
                    <template v-else>
                        <div
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </div>
                    </template>
                </div>
                <p class="text-xs text-(--ds-text-muted) select-none">
                    {{ ' ' + $t('Each code works once. Use') }}
                    <strong>{{ $t('New codes') }}</strong>
                    {{
                        $t('to get a fresh set; the old ones stop working.') +
                        ' '
                    }}
                </p>
            </template>
        </template>
    </div>
</template>
