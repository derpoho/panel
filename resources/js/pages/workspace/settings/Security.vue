<script setup lang="ts">
import PasswordStrength from '@/components/PasswordStrength.vue';
import { Form, Head, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    SolarKeyRound as KeyRound,
    SolarShieldCheck as ShieldCheck,
} from '@/lib/solarIcons';
import { computed, ref, watch } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import ConnectedAccounts from '@/components/ConnectedAccounts.vue';
import type { ConnectedAccount } from '@/components/ConnectedAccounts.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SecurityActivity from '@/components/SecurityActivity.vue';
import type { SecurityEvent } from '@/components/SecurityActivity.vue';
import SignedInDevices from '@/components/SignedInDevices.vue';
import type { SignedInDevice } from '@/components/SignedInDevices.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import type { Component } from 'vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import type { PickerOption } from '@/components/workspace/PickerMenu.vue';
import PlanLock from '@/components/workspace/PlanLock.vue';
import SettingsRow from '@/components/workspace/SettingsRow.vue';
import VerifiedDomains from '@/components/workspace/VerifiedDomains.vue';
import type { EmailDomains } from '@/components/workspace/VerifiedDomains.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import settings from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

// Parts the private packages put on this page, for its ExtensionSlots.
const slotParts = import.meta.glob<Component>(
    '../../../../../vendor/serveroshq/*/resources/js/slots/workspace/settings/Security/**/*.vue',
    { eager: true, import: 'default' },
);

/**
 * Security, inside the workspace: whether the workspace requires two-factor
 * sign-in (owners and admins), then your own password, authenticator app
 * and passkeys, which apply to every workspace you're in.
 */
// oxfmt-ignore
type Props = {
    workspace: Workspace;
    passwordRules: string;
    /** False for Google and GitHub sign-ups who haven't chosen one. */
    hasPassword: boolean;
    connectedAccounts: ConnectedAccount[];
    /** Null while staff view as this person. */
    devices: SignedInDevice[] | null;
    securityEvents: SecurityEvent[];
    canManageSettings: boolean;
    requireTwoFactor: boolean;
    withoutTwoFactor: { id: number; name: string; email: string }[];
    /** The company's verified domains; null below Pro or when you can't manage settings. */
    emailDomains: EmailDomains | null;
    /** Sign in with Google for the company; null when Google sign-in isn't set up or you can't manage settings. */
    googleSignIn: GoogleSignIn | null;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

type JoinMode = 'off' | 'auto' | 'request';

type GoogleSignIn = {
    /** On Pro and Business. */
    available: boolean;
    /** The workspace's verified domains, from the Verified domains section. */
    verifiedDomains: string[];
    join: JoinMode;
    /** A built-in role or `custom:<id>`. */
    joinRole: string;
    requireGoogle: boolean;
    isOwner: boolean;
    /** The verified domain this session signed in with Google on, if any. */
    signedInWithDomain: string | null;
    hasStaffRoles: boolean;
    hasCustomRoles: boolean;
    customRoles: { id: number; name: string }[];
};

// Props packages add to the page reach their slots through usePage(), not here.
defineOptions({ inheritAttrs: false });

const props = defineProps<Props>();

const requireOn = ref(props.requireTwoFactor);
const requireError = ref<string | null>(null);

function saveRequirement(value: boolean): void {
    requireError.value = null;
    router.put(
        onWorkspace(settings.twoFactor(props.workspace.slug)).url,
        { require: value },
        {
            preserveScroll: true,
            onError: (errors) => {
                requireError.value = errors.require ?? null;
                requireOn.value = props.requireTwoFactor;
            },
        },
    );
}

/* Sign in with Google for the company */
const google = computed(() => props.googleSignIn);
const verifiedDomains = computed(() => google.value?.verifiedDomains ?? []);

const joinMode = ref<JoinMode>(props.googleSignIn?.join ?? 'off');
const joinRole = ref<string>(props.googleSignIn?.joinRole ?? 'member');
const requireGoogle = ref(props.googleSignIn?.requireGoogle ?? false);
watch(
    () => props.googleSignIn,
    (value) => {
        joinMode.value = value?.join ?? 'off';
        joinRole.value = value?.joinRole ?? 'member';
        requireGoogle.value = value?.requireGoogle ?? false;
    },
);

const joinOptions = computed<PickerOption<JoinMode>[]>(() => [
    { value: 'off', label: trans('Only people you invite') },
    { value: 'auto', label: trans('Anyone on these domains joins') },
    {
        value: 'request',
        label: trans('Anyone on these domains can ask to join'),
    },
]);

const roleOptions = computed<PickerOption<string>[]>(() => {
    const staff = google.value?.hasStaffRoles ?? false;
    return [
        { value: 'admin', label: trans('Admin') },
        ...(staff
            ? [
                  { value: 'member', label: trans('Member') },
                  { value: 'viewer', label: trans('Viewer') },
              ]
            : []),
        ...(google.value?.hasCustomRoles
            ? google.value.customRoles.map((role) => ({
                  value: `custom:${role.id}`,
                  label: role.name,
                  hint: trans('Your role'),
              }))
            : []),
    ];
});

const joinError = ref<string | null>(null);
function saveJoin(): void {
    joinError.value = null;
    router.put(
        '/settings/domain-join',
        { mode: joinMode.value, role: joinRole.value },
        {
            preserveScroll: true,
            onError: (errors) => {
                joinError.value = errors.mode ?? errors.role ?? null;
                joinMode.value = props.googleSignIn?.join ?? 'off';
                joinRole.value = props.googleSignIn?.joinRole ?? 'member';
            },
        },
    );
}

const requireGoogleError = ref<string | null>(null);
function saveRequireGoogle(value: boolean): void {
    requireGoogleError.value = null;
    router.put(
        '/settings/require-google',
        { require: value },
        {
            preserveScroll: true,
            onError: (errors) => {
                requireGoogleError.value = errors.require ?? null;
                requireGoogle.value =
                    props.googleSignIn?.requireGoogle ?? false;
            },
        },
    );
}

/** What's typed in the new password, for the strength meter. */
const password = ref('');
function onPassword(event: Event): void {
    password.value = (event.target as HTMLInputElement).value;
}
</script>

<template>
    <Head :title="$t('Security')" />

    <div class="flex flex-col gap-5">
        <Tile
            id="workspace-security"
            v-if="canManageSettings"
            :title="$t('Workspace security')"
            :description="
                $t('Rules for everyone in :name.', {
                    name: String(workspace.name),
                })
            "
            :icon="ShieldCheck"
        >
            <div class="flex flex-col gap-5">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{
                            $t('Require two-factor sign-in')
                        }}</span>
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'Members need an authenticator app or a passkey to open this workspace. Anyone without one is asked to set it up first, owners included.',
                                ) +
                                ' '
                            }}
                        </span>
                    </div>
                    <ToggleSwitch
                        v-model="requireOn"
                        :label="$t('Require two-factor sign-in')"
                        @update:model-value="saveRequirement"
                    />
                </div>
                <InputError :message="requireError ?? undefined" />

                <div
                    v-if="withoutTwoFactor.length > 0"
                    class="flex flex-col gap-2 border-t border-(--ds-divider) pt-4"
                >
                    <span class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                ':length :valueValue without two-factor sign-in:value2',
                                {
                                    length: String(
                                        withoutTwoFactor.length ?? '',
                                    ),
                                    valueValue:
                                        withoutTwoFactor.length === 1
                                            ? $t('person')
                                            : $t('people'),
                                    value2: requireOn
                                        ? ' ' +
                                          $t(
                                              '(they can’t open the workspace yet)',
                                          )
                                        : '',
                                },
                            )
                        }}
                    </span>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="member in withoutTwoFactor"
                            :key="member.id"
                            class="rounded-md bg-(--ds-hover) px-2 py-0.5 text-[12.5px]/5"
                            :title="member.email"
                        >
                            {{ member.name }}
                        </li>
                    </ul>
                </div>
                <p
                    v-else
                    class="border-t border-(--ds-divider) pt-4 text-[13px]/5 text-(--ds-green)"
                >
                    {{
                        ' ' +
                        $t(
                            'Everyone in this workspace has two-factor sign-in.',
                        ) +
                        ' '
                    }}
                </p>
            </div>
        </Tile>

        <VerifiedDomains v-if="emailDomains" :email-domains="emailDomains" />

        <section
            v-if="google"
            id="google-sign-in"
            class="flex flex-col gap-3"
            aria-labelledby="google-sign-in-heading"
        >
            <div class="flex flex-col gap-0.5">
                <h2
                    id="google-sign-in-heading"
                    class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                >
                    {{ $t('Sign in with Google') }}
                </h2>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Let people with a Google account on your verified domains join :name, and require Google sign-in.',
                            { name: String(workspace.name) },
                        ) + ' '
                    }}
                    <a
                        v-if="emailDomains"
                        href="#verified-domains"
                        class="underline underline-offset-2 hover:text-(--ds-text)"
                        >{{ $t('Manage domains') }}</a
                    >
                </p>
            </div>

            <PlanLock
                :locked="!google.available"
                :feature="$t('Verified domains')"
                plan="pro"
                :description="
                    $t(
                        'Let your team in with their company Google accounts, and require Google sign-in.',
                    )
                "
            >
                <div class="flex flex-col gap-3">
                    <!-- Who can join, and requiring Google -->
                    <div class="ds-tile ds-tile-rows flex flex-col">
                        <div
                            class="ds-tile-row flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-5 py-3.5"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[13.5px]/5 font-medium">{{
                                    $t('Who can join')
                                }}</span>
                                <span
                                    class="text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{
                                        verifiedDomains.length
                                            ? $t(
                                                  'For people who sign in with a Google account on a verified domain.',
                                              )
                                            : $t(
                                                  'Starts working once a domain is verified.',
                                              )
                                    }}
                                </span>
                            </div>
                            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                                <div class="w-full sm:w-72">
                                    <PickerMenu
                                        id="domain-join"
                                        v-model="joinMode"
                                        :options="joinOptions"
                                        @update:model-value="saveJoin"
                                    />
                                </div>
                                <div
                                    v-if="joinMode !== 'off'"
                                    class="w-full sm:w-40"
                                >
                                    <PickerMenu
                                        id="domain-join-role"
                                        v-model="joinRole"
                                        :options="roleOptions"
                                        @update:model-value="saveJoin"
                                    />
                                </div>
                            </div>
                            <InputError
                                class="w-full"
                                :message="joinError ?? undefined"
                            />
                        </div>

                        <div
                            class="ds-tile-row flex flex-col gap-2 px-5 py-3.5"
                        >
                            <div class="flex items-start justify-between gap-6">
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="text-[13.5px]/5 font-medium">{{
                                        $t('Require Google sign-in')
                                    }}</span>
                                    <span
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t(
                                                'Members get in only after signing in with Google on one of your domains. The owner can always get in.',
                                            )
                                        }}
                                    </span>
                                </div>
                                <ToggleSwitch
                                    v-model="requireGoogle"
                                    :label="$t('Require Google sign-in')"
                                    :disabled="
                                        !requireGoogle &&
                                        !verifiedDomains.length
                                    "
                                    @update:model-value="saveRequireGoogle"
                                />
                            </div>
                            <p
                                v-if="
                                    !requireGoogle &&
                                    verifiedDomains.length &&
                                    !google.isOwner &&
                                    !google.signedInWithDomain
                                "
                                class="text-[12.5px]/5 text-(--ds-text-faint)"
                            >
                                {{
                                    $t(
                                        'To turn it on, sign in with Google on :domains first, so you can still get in.',
                                        {
                                            domains: verifiedDomains.join(', '),
                                        },
                                    )
                                }}
                            </p>
                            <InputError
                                :message="requireGoogleError ?? undefined"
                            />
                        </div>
                    </div>
                </div>
            </PlanLock>
        </section>

        <ExtensionSlot
            :parts="slotParts"
            name="workspace/settings/Security/after-google-sign-in"
            :workspace-name="String(workspace.name)"
        />

        <Tile
            id="password"
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
                        @input="onPassword"
                        autocomplete="new-password"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                    <PasswordStrength
                        :password="password"
                        :rules="passwordRules"
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
                        :passwordrules="passwordRules"
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
            id="connected-accounts"
            :accounts="props.connectedAccounts"
            :has-password="props.hasPassword"
        />

        <ManageTwoFactor
            id="two-factor"
            :can-manage-two-factor="canManageTwoFactor"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <ManagePasskeys
            id="passkeys"
            :can-manage-passkeys="canManagePasskeys"
            :passkeys="passkeys"
        />

        <SignedInDevices
            id="devices"
            v-if="props.devices"
            :devices="props.devices"
        />

        <SecurityActivity
            id="security-activity"
            :events="props.securityEvents"
        />
    </div>
</template>
