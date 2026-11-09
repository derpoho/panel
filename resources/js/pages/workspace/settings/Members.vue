<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head, router, useForm } from '@inertiajs/vue3';
import {
    SolarMail as Mail,
    SolarShieldCheck as ShieldCheck,
    SolarUserPlus as UserPlus,
    SolarUsers as Users,
    SolarPencil as Pencil,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import { onWorkspace } from '@/lib/workspaceRoute';
import invitationRoutes from '@/routes/workspace/invitations';
import memberRoutes from '@/routes/workspace/members';
import type {
    Workspace,
    WorkspaceInvitation,
    WorkspaceMember,
    WorkspaceRoleKey,
    WorkspaceRoleOption,
} from '@/types';
import AppButton from '@/components/workspace/AppButton.vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import type { Component } from 'vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import type { PickerOption } from '@/components/workspace/PickerMenu.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';

// Parts the private packages put on this page, for its ExtensionSlots.
const slotParts = import.meta.glob<Component>(
    '../../../../../vendor/serveroshq/*/resources/js/slots/workspace/settings/Members/**/*.vue',
    { eager: true, import: 'default' },
);

type CustomRole = {
    id: number;
    name: string;
    description: string | null;
    permissions: string[];
};

type Props = {
    workspace: Workspace;
    members: (WorkspaceMember & {
        customRoleId: number | null;
        twoFactor: boolean;
        /** Added and managed by the company's identity provider (panel-ee). */
        scim?: boolean;
    })[];
    invitations: (WorkspaceInvitation & { customRoleId: number | null })[];
    roles: WorkspaceRoleOption[];
    canManageMembers: boolean;
    hasStaffRoles: boolean;
    hasCustomRoles: boolean;
    customRoles: CustomRole[];
    permissions: { value: string; label: string }[];
    requireTwoFactor: boolean;
    /** People who signed in with Google on a verified domain and asked to join. */
    joinRequests: JoinRequest[];
    /** The role they're let in as unless picked otherwise. */
    joinRole: string;
};

type JoinRequest = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    domain: string | null;
    askedAt: string | null;
};

// Props packages add to the page reach their slots through usePage(), not here.
defineOptions({ inheritAttrs: false });

const props = defineProps<Props>();

const slug = computed(() => props.workspace.slug);

const assignableRoles = computed(() =>
    props.roles.filter((role) => role.value !== 'owner'),
);

function roleLabel(
    role: WorkspaceRoleKey,
    customRoleId?: number | null,
): string {
    const custom = props.customRoles.find((item) => item.id === customRoleId);
    if (custom) return custom.name;
    return props.roles.find((option) => option.value === role)?.label ?? role;
}

/** The select value for a member or invite: a built-in role or `custom:<id>`. */
function roleValue(
    role: WorkspaceRoleKey,
    customRoleId: number | null,
): string {
    return customRoleId ? `custom:${customRoleId}` : role;
}

/* Custom roles (Business): the form for adding or editing one */
const editing = ref<CustomRole | null>(null);
const roleForm = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});
const showRoleForm = ref(false);

function startRole(role: CustomRole | null): void {
    editing.value = role;
    roleForm.clearErrors();
    roleForm.name = role?.name ?? '';
    roleForm.description = role?.description ?? '';
    roleForm.permissions = [...(role?.permissions ?? [])];
    showRoleForm.value = true;
}

function saveRole(): void {
    // Workspace routes are relative to the workspace's own subdomain.
    const url = `/settings/roles${editing.value ? `/${editing.value.id}` : ''}`;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showRoleForm.value = false;
        },
    };
    if (editing.value) roleForm.put(url, options);
    else roleForm.post(url, options);
}

function deleteRole(role: CustomRole): void {
    if (
        !window.confirm(
            trans('Delete the :name role? Its members become Viewers.', {
                name: String(role.name),
            }),
        )
    )
        return;
    router.delete(`/settings/roles/${role.id}`, {
        preserveScroll: true,
    });
}

function permissionLabel(value: string): string {
    return props.permissions.find((p) => p.value === value)?.label ?? value;
}

function isRoleAvailable(role: WorkspaceRoleOption): boolean {
    return !role.staff || props.hasStaffRoles;
}

function canEdit(member: WorkspaceMember): boolean {
    return props.canManageMembers && member.role !== 'owner' && !member.isYou;
}

function changeRole(member: WorkspaceMember, event: Event): void {
    const role = (event.target as HTMLSelectElement).value;

    router.patch(
        onWorkspace(
            memberRoutes.update({ workspace: slug.value, member: member.id }),
        ).url,
        { role },
        { preserveScroll: true },
    );
}

function removeMember(member: WorkspaceMember & { scim?: boolean }): void {
    const message = member.isYou
        ? trans("Leave :name? You'll need a new invite to come back.", {
              name: String(props.workspace.name),
          })
        : member.scim
          ? trans(
                'Remove :nameValue from :name2? Your identity provider manages them, so its next sync may add them back. To keep them out, remove them there.',
                {
                    nameValue: String(member.name),
                    name2: String(props.workspace.name),
                },
            )
          : trans('Remove :nameValue from :name2?', {
                nameValue: String(member.name),
                name2: String(props.workspace.name),
            });

    if (!window.confirm(message)) {
        return;
    }

    router.delete(
        onWorkspace(
            memberRoutes.destroy({ workspace: slug.value, member: member.id }),
        ).url,
        { preserveScroll: true },
    );
}

function revoke(invitation: WorkspaceInvitation): void {
    router.delete(
        onWorkspace(
            invitationRoutes.destroy({
                workspace: slug.value,
                invitation: invitation.id,
            }),
        ).url,
        { preserveScroll: true },
    );
}

/* Waiting to join: the role each one is let in as */
const joinRoleOptions = computed<PickerOption<string>[]>(() => [
    ...assignableRoles.value
        .filter((role) => isRoleAvailable(role))
        .map((role) => ({ value: role.value, label: role.label })),
    ...(props.hasCustomRoles
        ? props.customRoles.map((role) => ({
              value: `custom:${role.id}`,
              label: role.name,
          }))
        : []),
]);
const requestRoles = reactive<Record<number, string>>({});
function requestRole(request: JoinRequest): string {
    const fallback = joinRoleOptions.value.some(
        (option) => option.value === props.joinRole,
    )
        ? props.joinRole
        : (joinRoleOptions.value[0]?.value ?? 'admin');
    return requestRoles[request.id] ?? fallback;
}

const deciding = ref<number | null>(null);
function decide(request: JoinRequest, approve: boolean): void {
    deciding.value = request.id;
    router.post(
        `/settings/join-requests/${request.id}/${approve ? 'approve' : 'deny'}`,
        approve ? { role: requestRole(request) } : {},
        {
            preserveScroll: true,
            onFinish: () => (deciding.value = null),
        },
    );
}

const inviteRoute = computed(() =>
    onWorkspace(invitationRoutes.store(slug.value)),
);

const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateFormat.format(new Date(value)) : '';
}

const selectClass =
    'ds-select h-8 cursor-pointer rounded-md border border-input bg-transparent pl-3 text-[13px]/4 text-(--ds-text) shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed dark:bg-input/30';
</script>

<template>
    <Head :title="$t('Members')" />

    <div class="flex flex-col gap-5">
        <Tile
            id="invite"
            v-if="canManageMembers"
            :title="$t('Invite people')"
            :description="
                $t(
                    'They\'ll get an email with a link to join. Links expire after 7 days.',
                )
            "
            :icon="UserPlus"
        >
            <Form
                :action="inviteRoute.url"
                method="post"
                :reset-on-success="['email']"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing, recentlySuccessful }"
                class="flex flex-col gap-2"
            >
                <div class="flex flex-wrap items-end gap-2">
                    <div class="grid min-w-56 grow gap-2">
                        <Label
                            for="invite-email"
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Email address') }}</Label
                        >
                        <Input
                            id="invite-email"
                            type="email"
                            name="email"
                            required
                            :placeholder="$t('sam@company.com')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label
                            for="invite-role"
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Role') }}</Label
                        >
                        <select
                            id="invite-role"
                            name="role"
                            :class="[selectClass, 'h-9 min-w-32']"
                        >
                            <option
                                v-for="role in assignableRoles"
                                :key="role.value"
                                :value="role.value"
                                :disabled="!isRoleAvailable(role)"
                                :selected="
                                    role.value ===
                                    (hasStaffRoles ? 'member' : 'admin')
                                "
                            >
                                {{ role.label
                                }}{{ isRoleAvailable(role) ? '' : ' (Pro)' }}
                            </option>
                            <optgroup
                                v-if="hasCustomRoles && customRoles.length"
                                :label="$t('Your roles')"
                            >
                                <option
                                    v-for="role in customRoles"
                                    :key="role.id"
                                    :value="`custom:${role.id}`"
                                >
                                    {{ role.name }}
                                </option>
                            </optgroup>
                        </select>
                    </div>
                    <SubmitButton
                        :label="$t('Send invite')"
                        busy-label="Sending…"
                        done-label="Sent"
                        size="md"
                        class="h-9"
                        :processing="processing"
                        :done="recentlySuccessful"
                    />
                </div>
                <InputError :message="errors.email" />
                <InputError :message="errors.role" />
                <p
                    v-if="!hasStaffRoles"
                    class="text-xs/4 text-(--ds-text-faint)"
                >
                    {{
                        ' ' +
                        $t(
                            'Member and Viewer roles are on Pro and Business. On your plan, people join as Admins.',
                        ) +
                        ' '
                    }}
                </p>
            </Form>
        </Tile>

        <Tile id="members" :title="$t('Members')" :icon="Users" :padded="false">
            <template #actions>
                <span
                    class="rounded-md bg-(--ds-hover) px-1.75 py-px text-xs/4.5 font-medium text-(--ds-text-secondary) tabular-nums"
                >
                    {{ members.length }}
                </span>
            </template>

            <ul class="ds-tile-rows">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="ds-tile-row flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
                >
                    <Avatar class="size-8 shrink-0 rounded-full">
                        <AvatarImage
                            v-if="member.avatar"
                            :src="member.avatar"
                            :alt="member.name"
                        />
                        <AvatarFallback
                            class="rounded-full bg-(--ds-hover) text-xs text-(--ds-text-secondary)"
                        >
                            {{ getInitials(member.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="flex min-w-0 grow flex-col">
                        <span class="truncate text-[13px]/5 font-medium">
                            {{ member.name }}
                            <span
                                v-if="member.isYou"
                                class="font-normal text-(--ds-text-muted)"
                            >
                                {{ ' ' + $t('(you)') + ' ' }}
                            </span>
                        </span>
                        <span class="truncate text-xs/4 text-(--ds-text-muted)">
                            {{ member.email }}
                            <span
                                v-if="requireTwoFactor && !member.twoFactor"
                                class="ml-1 text-(--ds-amber)"
                                >{{ $t('· needs two-factor') }}</span
                            >
                        </span>
                    </div>

                    <span
                        v-if="member.scim"
                        class="rounded-full border border-(--ds-border) px-1.75 py-px text-[11.5px]/4 text-(--ds-text-muted)"
                        :title="
                            $t(
                                'Added by your identity provider. Its next sync can change a role you set here.',
                            )
                        "
                    >
                        {{ $t('Managed by SCIM') }}
                    </span>

                    <select
                        v-if="canEdit(member)"
                        :class="selectClass"
                        :value="roleValue(member.role, member.customRoleId)"
                        :aria-label="
                            $t('Role for :name', { name: String(member.name) })
                        "
                        @change="changeRole(member, $event)"
                    >
                        <option
                            v-for="role in assignableRoles"
                            :key="role.value"
                            :value="role.value"
                            :disabled="
                                !isRoleAvailable(role) &&
                                role.value !== member.role
                            "
                        >
                            {{ role.label }}
                        </option>
                        <optgroup
                            v-if="hasCustomRoles && customRoles.length"
                            :label="$t('Your roles')"
                        >
                            <option
                                v-for="role in customRoles"
                                :key="role.id"
                                :value="`custom:${role.id}`"
                            >
                                {{ role.name }}
                            </option>
                        </optgroup>
                    </select>
                    <span
                        v-else
                        class="rounded-md bg-(--ds-hover) px-1.75 py-px text-xs/4.5 font-medium text-(--ds-text-secondary)"
                    >
                        {{ roleLabel(member.role, member.customRoleId) }}
                    </span>

                    <AppButton
                        v-if="
                            member.role !== 'owner' &&
                            (member.isYou || canManageMembers)
                        "
                        variant="danger"
                        size="sm"
                        @click="removeMember(member)"
                    >
                        {{ member.isYou ? $t('Leave') : $t('Remove') }}
                    </AppButton>
                </li>
            </ul>
            <div
                v-if="$page.props.errors.member || $page.props.errors.role"
                class="px-5 pb-3"
            >
                <InputError :message="$page.props.errors.member" />
                <InputError :message="$page.props.errors.role" />
            </div>
        </Tile>

        <Tile
            id="join-requests"
            v-if="canManageMembers && joinRequests.length"
            :title="$t('Waiting to join')"
            :description="
                $t(
                    'They signed in with Google on one of your domains and asked to join.',
                )
            "
            :icon="UserPlus"
            :padded="false"
        >
            <ul class="ds-tile-rows">
                <li
                    v-for="request in joinRequests"
                    :key="request.id"
                    class="ds-tile-row flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
                >
                    <Avatar class="size-8 shrink-0 rounded-full">
                        <AvatarImage
                            v-if="request.avatar"
                            :src="request.avatar"
                            :alt="request.name"
                        />
                        <AvatarFallback
                            class="rounded-full bg-(--ds-hover) text-xs text-(--ds-text-secondary)"
                        >
                            {{ getInitials(request.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="flex min-w-0 grow flex-col">
                        <span class="truncate text-[13px]/5 font-medium">
                            {{ request.name }}
                        </span>
                        <span class="truncate text-xs/4 text-(--ds-text-muted)">
                            {{ request.email }}
                            <template v-if="request.askedAt">
                                {{
                                    ' ' +
                                    $t('· asked :date', {
                                        date: formatDate(request.askedAt),
                                    })
                                }}
                            </template>
                        </span>
                    </div>
                    <div class="w-36">
                        <PickerMenu
                            :id="`join-request-role-${request.id}`"
                            :model-value="requestRole(request)"
                            :options="joinRoleOptions"
                            @update:model-value="
                                (value: string) =>
                                    (requestRoles[request.id] = value)
                            "
                        />
                    </div>
                    <div class="flex gap-1.5">
                        <AppButton
                            variant="primary"
                            size="sm"
                            :disabled="deciding === request.id"
                            @click="decide(request, true)"
                        >
                            {{ $t('Let in') }}
                        </AppButton>
                        <AppButton
                            variant="quiet"
                            size="sm"
                            :disabled="deciding === request.id"
                            @click="decide(request, false)"
                        >
                            {{ $t('Turn down') }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </Tile>

        <Tile
            id="invites"
            v-if="invitations.length"
            :title="$t('Pending invites')"
            :icon="Mail"
            :padded="false"
        >
            <ul class="ds-tile-rows">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="ds-tile-row flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                >
                    <div class="flex min-w-0 grow flex-col">
                        <span class="truncate text-[13px]/5 font-medium">
                            {{ invitation.email }}
                        </span>
                        <span class="text-xs/4 text-(--ds-text-muted)">
                            {{
                                roleLabel(
                                    invitation.role,
                                    invitation.customRoleId,
                                )
                            }}
                            <template v-if="invitation.invitedBy">
                                {{
                                    ' ' +
                                    $t('· invited by :invitedBy', {
                                        invitedBy: invitation.invitedBy,
                                    })
                                }}
                            </template>
                            ·
                            <span
                                :class="{
                                    'text-(--ds-amber)': invitation.expired,
                                }"
                            >
                                {{
                                    invitation.expired
                                        ? 'expired'
                                        : `expires ${formatDate(invitation.expiresAt)}`
                                }}
                            </span>
                        </span>
                    </div>
                    <AppButton
                        v-if="canManageMembers"
                        variant="quiet"
                        size="sm"
                        @click="revoke(invitation)"
                    >
                        {{ ' ' + $t('Revoke') + ' ' }}
                    </AppButton>
                </li>
            </ul>
        </Tile>

        <ExtensionSlot
            :parts="slotParts"
            name="workspace/settings/Members/after-invitations"
            :workspace-name="workspace.name"
            :role-options="joinRoleOptions"
        />

        <Tile id="roles" :title="$t('Roles')" :icon="ShieldCheck">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="role in roles"
                    :key="role.value"
                    class="ds-tile-inner px-4 py-3"
                >
                    <dt
                        class="flex items-center gap-2 text-[13px]/5 font-medium"
                    >
                        {{ role.label }}
                        <span
                            v-if="role.staff && !hasStaffRoles"
                            class="rounded-[4px] border border-(--ds-blue-border) bg-(--ds-blue-bg)/70 px-1.5 py-0.5 font-body text-[10px]/3 font-medium tracking-[0.08em] text-(--ds-blue-strong) uppercase"
                        >
                            {{ ' ' + $t('Pro') + ' ' }}
                        </span>
                    </dt>
                    <dd class="text-[13px]/5 text-(--ds-text-muted)">
                        {{ role.description }}
                    </dd>
                </div>
            </dl>

            <!-- Custom roles, on Business -->
            <div
                class="mt-5 flex flex-col gap-3 border-t border-(--ds-divider) pt-5"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-col">
                        <span
                            class="flex items-center gap-2 text-[13px]/5 font-medium"
                        >
                            {{ ' ' + $t('Your roles') }}
                            <span
                                v-if="!hasCustomRoles"
                                class="rounded-[4px] border border-(--ds-blue-border) bg-(--ds-blue-bg)/70 px-1.5 py-0.5 font-body text-[10px]/3 font-medium tracking-[0.08em] text-(--ds-blue-strong) uppercase"
                                >{{ $t('Business') }}</span
                            >
                        </span>
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'Pick exactly what a role can do. If the workspace leaves Business, people with these roles become Viewers.',
                                ) +
                                ' '
                            }}
                        </span>
                    </div>
                    <AppButton
                        v-if="
                            hasCustomRoles && canManageMembers && !showRoleForm
                        "
                        size="sm"
                        @click="startRole(null)"
                    >
                        {{ ' ' + $t('Add role') + ' ' }}
                    </AppButton>
                </div>

                <form
                    v-if="showRoleForm"
                    class="ds-tile-inner flex flex-col gap-4 p-4"
                    @submit.prevent="saveRole"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label
                                for="role-name"
                                class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                                >{{ $t('Name') }}</Label
                            >
                            <Input
                                id="role-name"
                                v-model="roleForm.name"
                                :placeholder="$t('Billing manager')"
                                required
                            />
                            <InputError :message="roleForm.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label
                                for="role-description"
                                class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                                >{{ $t('Description') }}</Label
                            >
                            <Input
                                id="role-description"
                                v-model="roleForm.description"
                                :placeholder="$t('Optional')"
                            />
                        </div>
                    </div>
                    <fieldset class="flex flex-col gap-2">
                        <legend
                            class="mb-1 text-[13px]/5 font-medium text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t('Can do (everyone can view the workspace)') +
                                ' '
                            }}
                        </legend>
                        <label
                            v-for="permission in permissions"
                            :key="permission.value"
                            class="flex cursor-pointer items-center gap-2.5 text-[13px]/5"
                        >
                            <input
                                v-model="roleForm.permissions"
                                type="checkbox"
                                :value="permission.value"
                                class="size-4 accent-[#3B7BFF]"
                            />
                            {{ permission.label }}
                        </label>
                    </fieldset>
                    <div class="flex justify-end gap-2">
                        <AppButton
                            variant="quiet"
                            size="sm"
                            type="button"
                            @click="showRoleForm = false"
                        >
                            {{ ' ' + $t('Cancel') + ' ' }}
                        </AppButton>
                        <AppButton
                            variant="primary"
                            size="sm"
                            type="submit"
                            :disabled="roleForm.processing"
                        >
                            {{ editing ? $t('Save role') : $t('Add role') }}
                        </AppButton>
                    </div>
                </form>

                <ul v-if="customRoles.length" class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="role in customRoles"
                        :key="role.id"
                        class="ds-tile-inner flex flex-col gap-1.5 px-4 py-3"
                        :class="{ 'opacity-60': !hasCustomRoles }"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[13px]/5 font-medium">{{
                                role.name
                            }}</span>
                            <span
                                v-if="hasCustomRoles && canManageMembers"
                                class="flex gap-1"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md p-1 text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                                    :aria-label="
                                        $t('Edit :name', {
                                            name: String(role.name),
                                        })
                                    "
                                    @click="startRole(role)"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md p-1 text-(--ds-text-muted) hover:bg-(--ds-red)/10 hover:text-(--ds-red)"
                                    :aria-label="
                                        $t('Delete :name', {
                                            name: String(role.name),
                                        })
                                    "
                                    @click="deleteRole(role)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </span>
                        </div>
                        <span
                            v-if="role.description"
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ role.description }}
                        </span>
                        <span class="text-xs/4 text-(--ds-text-secondary)">
                            {{
                                role.permissions.length
                                    ? role.permissions
                                          .map(permissionLabel)
                                          .join(' · ')
                                    : $t('View only')
                            }}
                        </span>
                    </li>
                </ul>
            </div>
        </Tile>
    </div>
</template>
