<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    SolarBell as Bell,
    SolarBuilding2 as Building2,
    SolarGitBranch as GitBranch,
    SolarTriangleAlert as TriangleAlert,
} from '@/lib/solarIcons';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import AlertsDialog from '@/components/monitors/AlertsDialog.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import PanelUpdateTile from '@/components/workspace/PanelUpdateTile.vue';
import type { PanelUpdateState } from '@/components/workspace/PanelUpdateTile.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import settings from '@/routes/workspace/settings';
import logo from '@/routes/workspace/settings/logo';
import type { Workspace } from '@/types';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SettingsRow from '@/components/workspace/SettingsRow.vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';

type Props = {
    workspace: Workspace;
    canManageSettings: boolean;
    canDelete: boolean;
    canManageAlerts: boolean;
    alerts: {
        email: boolean;
        webhook: string | null;
        webhookSet: boolean;
        muted: string[];
    };
    github:
        | {
              id: number;
              account: string;
              organisation: boolean;
              manageUrl: string;
          }[]
        | null;
    githubSetup: {
        ready: boolean;
        canSetUp: boolean;
        fromEnvironment: boolean;
    } | null;
    panelUpdate: PanelUpdateState | null;
};

const props = defineProps<Props>();

const updateRoute = onWorkspace(settings.update(props.workspace.slug));

const confirmingDelete = ref(false);
const alertsOpen = ref(false);

const githubConnectUrl = `/settings/github/connect`;

function disconnectGitHub(id: number, account: string): void {
    if (
        !window.confirm(
            trans(
                'Disconnect :account? Its private repositories can’t be deployed until it’s connected again.',
                { account: String(account) },
            ),
        )
    ) {
        return;
    }
    router.delete(`/settings/github/${id}`, { preserveScroll: true });
}
const deleteForm = useForm({ confirmation: '' });

function deleteWorkspace(): void {
    if (deleteForm.processing) {
        return;
    }

    deleteForm.delete(onWorkspace(settings.destroy(props.workspace.slug)).url, {
        preserveScroll: true,
    });
}

watch(confirmingDelete, (open) => {
    if (!open) {
        deleteForm.reset();
        deleteForm.clearErrors();
    }
});
</script>

<template>
    <Head :title="$t('Workspace settings')" />

    <Tile
        id="workspace"
        :title="$t('Workspace')"
        :description="
            canManageSettings
                ? $t('How the workspace appears to everyone in it.')
                : $t(
                      'How the workspace appears to everyone in it. Only owners and admins can change these.',
                  )
        "
        :icon="Building2"
    >
        <div class="grid gap-x-8 gap-y-6 md:grid-cols-2">
            <SettingsRow :label="$t('Logo')" wide>
                <ImageUploadField
                    field="logo"
                    :label="$t('Logo')"
                    hide-label
                    :image-url="workspace.logoUrl"
                    :fallback="workspace.name.charAt(0).toUpperCase()"
                    :upload-url="onWorkspace(logo.update(workspace.slug)).url"
                    :remove-url="onWorkspace(logo.destroy(workspace.slug)).url"
                    :disabled="!canManageSettings"
                />
            </SettingsRow>

            <Form
                :action="updateRoute.url"
                method="patch"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing, recentlySuccessful }"
                class="contents"
            >
                <SettingsRow :label="$t('Name')" input-id="workspace-name">
                    <Input
                        id="workspace-name"
                        name="name"
                        :default-value="workspace.name"
                        required
                        maxlength="255"
                        :disabled="!canManageSettings"
                    />
                    <InputError :message="errors.name" />
                </SettingsRow>

                <SettingsRow
                    :label="$t('Address')"
                    input-id="workspace-address"
                    :hint="$t('The address can\'t be changed yet.')"
                >
                    <Input
                        id="workspace-address"
                        :model-value="workspace.host"
                        readonly
                        class="font-ui-mono text-(--ds-text-muted) md:text-[13px]"
                    />
                </SettingsRow>

                <div
                    v-if="canManageSettings"
                    class="flex justify-end md:col-span-2"
                >
                    <SubmitButton
                        :processing="processing"
                        :done="recentlySuccessful"
                    />
                </div>
            </Form>
        </div>
    </Tile>

    <Tile
        id="alerts"
        v-if="canManageAlerts"
        :title="$t('Alerts')"
        :description="$t('Who hears about it when something breaks.')"
        :icon="Bell"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex max-w-xl flex-col gap-1">
                <h3 class="text-[13px]/5 font-medium">
                    {{
                        [
                            alerts.email
                                ? $t('Email to owners and admins')
                                : null,
                            alerts.webhookSet ? $t('Webhook') : null,
                        ]
                            .filter(Boolean)
                            .join(' and ') || $t('Off')
                    }}
                </h3>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'When a machine stops reporting or runs low on disk or memory, a service stops, a deploy or backup fails, a monitor goes down, or a certificate is about to expire, and again when it’s fixed.',
                        ) +
                        ' '
                    }}
                    <template v-if="alerts.muted.length">
                        {{
                            ' ' +
                            $t(
                                alerts.muted.length === 1
                                    ? ':count kind is switched off.'
                                    : ':count kinds are switched off.',
                                { count: String(alerts.muted.length) },
                            )
                        }}
                    </template>
                </p>
            </div>
            <AppButton size="sm" @click="alertsOpen = true">
                {{ ' ' + $t('Change') + ' ' }}
            </AppButton>
        </div>
    </Tile>
    <AlertsDialog v-model:open="alertsOpen" :alerts="alerts" />

    <Tile
        id="github"
        v-if="github"
        :title="$t('GitHub')"
        :description="
            $t(
                'Deploy private repositories. ServerOS reads only the ones you choose on GitHub.',
            )
        "
        :icon="GitBranch"
    >
        <div
            v-if="githubSetup && !githubSetup.ready"
            class="flex flex-col items-start gap-3"
        >
            <p class="max-w-xl text-[13px]/5 text-(--ds-text-muted)">
                {{
                    githubSetup.canSetUp
                        ? $t(
                              'This install needs a GitHub App of its own before it can deploy private repositories. Setting one up takes about a minute, and we’ll walk you through it.',
                          )
                        : $t(
                              'This install doesn’t have a GitHub App yet. Ask the workspace owner to set one up in Settings › General. Public repositories deploy without it.',
                          )
                }}
            </p>
            <Link
                v-if="githubSetup.canSetUp"
                href="/settings/github/app"
                :class="appButtonClasses('secondary', 'sm')"
            >
                {{ $t('Set up GitHub') }}
            </Link>
        </div>
        <div v-else class="flex flex-col gap-3">
            <ul v-if="github.length" class="flex flex-col">
                <li
                    v-for="account in github"
                    :key="account.id"
                    class="flex items-center gap-3 border-b border-(--ds-divider) py-2.5 first:pt-0 last:border-b-0"
                >
                    <span class="min-w-0 grow">
                        <span class="block truncate text-[13px]/5 font-medium">
                            {{ account.account }}
                        </span>
                        <span class="block text-xs/4 text-(--ds-text-muted)">
                            {{
                                account.organisation
                                    ? $t('Organisation')
                                    : $t('Personal account')
                            }}
                        </span>
                    </span>
                    <a
                        :href="account.manageUrl"
                        target="_blank"
                        rel="noopener"
                        :class="appButtonClasses('quiet', 'sm')"
                    >
                        {{ ' ' + $t('Choose repositories') + ' ' }}
                    </a>
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="disconnectGitHub(account.id, account.account)"
                    >
                        {{ ' ' + $t('Disconnect') + ' ' }}
                    </AppButton>
                </li>
            </ul>
            <p v-else class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    ' ' +
                    $t(
                        'Not connected. Public repositories deploy without it.',
                    ) +
                    ' '
                }}
            </p>
            <div>
                <a
                    :href="githubConnectUrl"
                    :class="appButtonClasses('secondary', 'sm')"
                >
                    {{
                        github.length
                            ? $t('Connect another account')
                            : $t('Connect GitHub')
                    }}
                </a>
                <Link
                    v-if="githubSetup?.canSetUp"
                    href="/settings/github/app"
                    :class="appButtonClasses('quiet', 'sm')"
                    class="ml-1"
                >
                    {{ $t('GitHub App') }}
                </Link>
            </div>
        </div>
    </Tile>

    <PanelUpdateTile v-if="panelUpdate" :state="panelUpdate" />

    <Tile
        id="danger-zone"
        v-if="canDelete"
        :title="$t('Danger zone')"
        :description="$t('Actions here can\'t be undone.')"
        :icon="TriangleAlert"
        danger
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex max-w-xl flex-col gap-1">
                <h3 class="text-[13px]/5 font-medium">
                    {{ ' ' + $t('Delete workspace') + ' ' }}
                </h3>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Permanently deletes :name, its machines, members and invitations. Connected machines stop reporting to it, but nothing on the machines themselves is touched.',
                            { name: workspace.name },
                        ) +
                        ' '
                    }}
                </p>
            </div>
            <button
                type="button"
                :class="
                    appButtonClasses(
                        'secondary',
                        'sm',
                        'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10',
                    )
                "
                @click="confirmingDelete = true"
            >
                {{ $t('Delete workspace') }}
            </button>
        </div>
    </Tile>

    <Dialog v-model:open="confirmingDelete">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: workspace.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            "This permanently deletes the workspace, its machines, members and invitations. It can't be undone.",
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="deleteWorkspace">
                <div class="grid gap-2">
                    <label for="delete-confirmation" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            workspace.slug
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
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="confirmingDelete = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Delete workspace')"
                        :processing="deleteForm.processing"
                        :disabled="
                            !deleteForm.processing &&
                            deleteForm.confirmation !== workspace.slug
                        "
                        :error="deleteForm.errors.confirmation"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
