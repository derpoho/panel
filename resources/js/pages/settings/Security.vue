<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head } from '@inertiajs/vue3';
import { SolarKeyRound as KeyRound } from '@/lib/solarIcons';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import ConnectedAccounts from '@/components/ConnectedAccounts.vue';
import type { ConnectedAccount } from '@/components/ConnectedAccounts.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import SecurityActivity from '@/components/SecurityActivity.vue';
import type { SecurityEvent } from '@/components/SecurityActivity.vue';
import SignedInDevices from '@/components/SignedInDevices.vue';
import type { SignedInDevice } from '@/components/SignedInDevices.vue';
import SettingsRow from '@/components/workspace/SettingsRow.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { edit } from '@/routes/security';

/**
 * Your password, authenticator app and passkeys, as tiles like the
 * workspace's Security page.
 */
// oxfmt-ignore
type Props = {
    passwordRules: string;
    /** False for Google and GitHub sign-ups who haven't chosen one. */
    hasPassword: boolean;
    connectedAccounts: ConnectedAccount[];
    /** Null while staff view as this person. */
    devices: SignedInDevice[] | null;
    securityEvents: SecurityEvent[];
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Security settings'),
                href: edit(),
            },
        ],
    },
});

/** What's typed in the new password, for the strength meter. */
const password = ref('');
function onPassword(event: Event): void {
    password.value = (event.target as HTMLInputElement).value;
}
</script>

<template>
    <Head :title="$t('Security settings')" />

    <h1 class="sr-only">{{ $t('Security settings') }}</h1>

    <div class="flex flex-col gap-5">
        <Tile
            :title="props.hasPassword ? $t('Password') : $t('Set a password')"
            :description="
                props.hasPassword
                    ? $t('Your password for every workspace you’re in.')
                    : $t(
                          'You signed up with Google or GitHub, so you don’t have a password yet. Set one to sign in with your email too.',
                      )
            "
            :icon="KeyRound"
        >
            <Form
                v-bind="SecurityController.update.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                v-slot="{ errors, processing, recentlySuccessful }"
                class="grid gap-x-8 gap-y-6 md:grid-cols-2"
            >
                <SettingsRow
                    v-if="props.hasPassword"
                    :label="$t('Current password')"
                    input-id="current_password"
                    wide
                >
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                    />
                    <InputError :message="errors.current_password" />
                </SettingsRow>
                <SettingsRow :label="$t('New password')" input-id="password">
                    <PasswordInput
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        :passwordrules="props.passwordRules"
                        @input="onPassword"
                    />
                    <InputError :message="errors.password" />
                    <PasswordStrength
                        :password="password"
                        :rules="props.passwordRules"
                    />
                </SettingsRow>
                <SettingsRow
                    :label="$t('Confirm password')"
                    input-id="password_confirmation"
                >
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </SettingsRow>
                <div class="flex justify-end md:col-span-2">
                    <SubmitButton
                        :processing="processing"
                        :done="recentlySuccessful"
                    />
                </div>
            </Form>
        </Tile>

        <ConnectedAccounts
            :accounts="props.connectedAccounts"
            :has-password="props.hasPassword"
        />

        <ManageTwoFactor
            :can-manage-two-factor="canManageTwoFactor"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <ManagePasskeys
            :can-manage-passkeys="canManagePasskeys"
            :passkeys="passkeys"
        />

        <SignedInDevices v-if="props.devices" :devices="props.devices" />

        <SecurityActivity :events="props.securityEvents" />
    </div>
</template>
