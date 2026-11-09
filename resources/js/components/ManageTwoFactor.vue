<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { SolarShieldCheck as ShieldCheck } from '@/lib/solarIcons';
import { onUnmounted, ref } from 'vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <Tile
        v-if="canManageTwoFactor"
        :title="$t('Two-factor authentication')"
        :description="
            $t('A code from an authenticator app each time you sign in.')
        "
        :icon="ShieldCheck"
    >
        <div class="flex flex-col gap-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p
                    v-if="!twoFactorEnabled"
                    class="max-w-xl text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'Turn it on and you’ll be asked for a 6-digit code from an authenticator app, like 1Password or Google Authenticator, when you sign in.',
                        ) +
                        ' '
                    }}
                </p>
                <p v-else class="max-w-xl text-[13px]/5 text-(--ds-text-muted)">
                    <span class="font-medium text-(--ds-green)">{{
                        $t('On.')
                    }}</span>
                    {{
                        $t(
                            'You’ll be asked for a code from your authenticator app when you sign in.',
                        ) + ' '
                    }}
                </p>

                <template v-if="!twoFactorEnabled">
                    <AppButton
                        v-if="hasSetupData"
                        variant="primary"
                        @click="showSetupModal = true"
                    >
                        <ShieldCheck class="size-3.5" />
                        {{ $t('Continue setup') + ' ' }}
                    </AppButton>
                    <Form
                        v-else
                        v-bind="enable.form()"
                        @success="showSetupModal = true"
                        #default="{ processing }"
                    >
                        <AppButton
                            type="submit"
                            variant="primary"
                            :disabled="processing"
                        >
                            {{ ' ' + $t('Turn on') + ' ' }}
                        </AppButton>
                    </Form>
                </template>
                <Form v-else v-bind="disable.form()" #default="{ processing }">
                    <AppButton
                        type="submit"
                        variant="danger"
                        :disabled="processing"
                    >
                        {{ ' ' + $t('Turn off') + ' ' }}
                    </AppButton>
                </Form>
            </div>

            <TwoFactorRecoveryCodes v-if="twoFactorEnabled" />
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </Tile>
</template>
