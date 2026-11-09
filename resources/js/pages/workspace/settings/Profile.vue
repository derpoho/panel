<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    SolarBell as Bell,
    SolarTriangleAlert as TriangleAlert,
    SolarUserRound as UserRound,
} from '@/lib/solarIcons';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import { appButtonClasses } from '@/lib/appButton';
import { destroy as destroyProfile } from '@/routes/profile';
import { toast } from '@/lib/toast';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import { getInitials } from '@/composables/useInitials';
import { update } from '@/routes/profile';
import avatar from '@/routes/profile/avatar';
import { send } from '@/routes/verification';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import SettingsRow from '@/components/workspace/SettingsRow.vue';
import Tile from '@/components/workspace/Tile.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';

const props = defineProps<{
    mustVerifyEmail: boolean;
    status?: string;
    notifications: {
        alertEmails: 'all' | 'problems' | 'none';
        weeklySummary: boolean;
        getsAlerts: boolean;
    };
    deleteAccount: {
        hasPassword: boolean;
        email: string;
        /** Workspaces only they're in: these go with the account. */
        goes: string[];
        /** Workspaces they own that others are in: these stop it. */
        blocking: string[];
        /** Who to ask to hand one over. */
        support: string;
    };
}>();

const confirmingDelete = ref(false);
const deleteForm = useForm({ password: '', confirmation: '' });
const canConfirm = computed(() =>
    props.deleteAccount.hasPassword
        ? deleteForm.password !== ''
        : deleteForm.confirmation === props.deleteAccount.email,
);

function submitDelete(): void {
    deleteForm.submit(destroyProfile(), {
        preserveScroll: true,
        onFinish: () => deleteForm.reset('password'),
    });
}

const notify = useForm({
    alert_emails: props.notifications.alertEmails,
    weekly_summary: props.notifications.weeklySummary,
});

function saveNotifications(): void {
    notify.put('/settings/notifications', {
        preserveScroll: true,
        onSuccess: () => toast.success(trans('Saved')),
    });
}

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="$t('Profile')" />

    <Tile
        id="profile"
        :title="$t('Your profile')"
        :description="$t('Shown to people in every workspace you belong to.')"
        :icon="UserRound"
    >
        <div class="grid gap-x-8 gap-y-6 md:grid-cols-2">
            <SettingsRow :label="$t('Profile picture')" wide>
                <ImageUploadField
                    field="avatar"
                    :label="$t('Profile picture')"
                    hide-label
                    shape="round"
                    :image-url="user.avatar ?? null"
                    :fallback="getInitials(user.name)"
                    :upload-url="avatar.update().url"
                    :remove-url="avatar.destroy().url"
                />
            </SettingsRow>

            <Form
                v-bind="update.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing, recentlySuccessful }"
                class="contents"
            >
                <SettingsRow :label="$t('Name')" input-id="name">
                    <Input
                        id="name"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                    />
                    <InputError :message="errors.name" />
                </SettingsRow>

                <SettingsRow :label="$t('Email address')" input-id="email">
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="username"
                    />
                    <InputError :message="errors.email" />

                    <div
                        v-if="mustVerifyEmail && !user.email_verified_at"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{ ' ' + $t('Your email address is unverified.') }}
                        <Link
                            :href="send()"
                            as="button"
                            class="text-(--ds-text) underline underline-offset-4"
                        >
                            {{
                                ' ' +
                                $t('Re-send the verification email.') +
                                ' '
                            }}
                        </Link>
                        <p
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 font-medium text-emerald-700 dark:text-emerald-400"
                        >
                            {{
                                ' ' +
                                $t('A new verification link has been sent.') +
                                ' '
                            }}
                        </p>
                    </div>
                </SettingsRow>

                <div class="flex justify-end md:col-span-2">
                    <SubmitButton
                        :processing="processing"
                        :done="recentlySuccessful"
                    />
                </div>
            </Form>
        </div>
    </Tile>

    <Tile
        id="emails"
        :title="$t('Your emails from this workspace')"
        :description="$t('Only for you; everyone chooses their own.')"
        :icon="Bell"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <span class="text-[13px]/5 font-medium">{{
                    $t('Alert emails')
                }}</span>
                <div
                    class="ds-segments self-start"
                    role="group"
                    :aria-label="$t('Alert emails')"
                >
                    <button
                        v-for="[value, label] in [
                            ['all', 'Everything'],
                            ['problems', 'Only problems'],
                            ['none', 'None'],
                        ] as const"
                        :key="value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="notify.alert_emails === value"
                        :disabled="!notifications.getsAlerts"
                        @click="
                            notify.alert_emails = value;
                            saveNotifications();
                        "
                    >
                        {{ label }}
                    </button>
                </div>
                <span class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        notifications.getsAlerts
                            ? $t(
                                  '“Only problems” skips the “it’s back” and “good news” emails. The workspace webhook still gets everything.',
                              )
                            : $t(
                                  'Alerts go to owners and admins; ask one to change your role if you need them.',
                              )
                    }}
                </span>
            </div>
            <div class="flex items-center justify-between gap-4">
                <span class="flex flex-col">
                    <span class="text-[13px]/5 font-medium">{{
                        $t('Weekly summary')
                    }}</span>
                    <span class="text-xs/4 text-(--ds-text-muted)">
                        {{
                            ' ' +
                            $t(
                                'Monday morning: uptime, incidents, deploys, backups, and what needs attention.',
                            ) +
                            ' '
                        }}
                    </span>
                </span>
                <ToggleSwitch
                    :model-value="notify.weekly_summary"
                    :label="$t('Weekly summary')"
                    @update:model-value="
                        (on) => {
                            notify.weekly_summary = on;
                            saveNotifications();
                        }
                    "
                />
            </div>
        </div>
    </Tile>

    <Tile
        id="delete-account"
        :title="$t('Delete your account')"
        :description="$t('Actions here can\'t be undone.')"
        :icon="TriangleAlert"
        danger
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex max-w-xl flex-col gap-1">
                <h3 class="text-[13px]/5 font-medium">
                    {{ $t('Delete account and data') }}
                </h3>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Permanently deletes your account: your profile, sign-ins, passkeys, API keys and your place in every workspace. Nothing on your machines is touched.',
                        )
                    }}
                    <template v-if="deleteAccount.goes.length">
                        {{
                            ' ' +
                            $t(
                                'Workspaces only you are in go with it: :names.',
                                { names: deleteAccount.goes.join(', ') },
                            )
                        }}
                    </template>
                </p>
                <p
                    v-if="deleteAccount.blocking.length"
                    class="mt-1 text-[13px]/5 text-(--ds-amber)"
                >
                    {{
                        $t(
                            'You own :names, which other people are in. Delete it, or email :address to hand it over, first.',
                            {
                                names: deleteAccount.blocking.join(', '),
                                address: deleteAccount.support,
                            },
                        )
                    }}
                </p>
            </div>
            <button
                type="button"
                :disabled="deleteAccount.blocking.length > 0"
                :class="
                    appButtonClasses(
                        'secondary',
                        'sm',
                        'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10 disabled:cursor-not-allowed disabled:opacity-50',
                    )
                "
                @click="confirmingDelete = true"
            >
                {{ $t('Delete account') }}
            </button>
        </div>
    </Tile>

    <Dialog v-model:open="confirmingDelete">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Delete your account?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Your account and its data are deleted for good, and you’re signed out everywhere. This can’t be undone.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submitDelete">
                <div v-if="deleteAccount.hasPassword" class="grid gap-2">
                    <label for="delete-password" class="text-[13px]/5">
                        {{ $t('Your password') }}
                    </label>
                    <Input
                        id="delete-password"
                        v-model="deleteForm.password"
                        type="password"
                        autocomplete="current-password"
                    />
                    <InputError :message="deleteForm.errors.password" />
                </div>
                <div v-else class="grid gap-2">
                    <label for="delete-confirmation" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            deleteAccount.email
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <Input
                        id="delete-confirmation"
                        v-model="deleteForm.confirmation"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                    <InputError :message="deleteForm.errors.confirmation" />
                </div>
                <InputError
                    :message="
                        (deleteForm.errors as Record<string, string>).account
                    "
                />
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="confirmingDelete = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Delete account')"
                        :processing="deleteForm.processing"
                        :disabled="!deleteForm.processing && !canConfirm"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
