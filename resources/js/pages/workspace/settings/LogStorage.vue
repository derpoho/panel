<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarDatabase as Database, SolarCopy as Copy } from '@/lib/solarIcons';
import { timeAgo } from '@/lib/timeAgo';
import type { Workspace } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

/**
 * Settings › Log storage (Business): a Postgres the workspace owns, where
 * new log lines go instead of ours. Save the connection, test it, then
 * turn it on.
 */
const props = defineProps<{
    workspace: Workspace;
    canManage: boolean;
    planAllows: boolean;
    /** Where our connections come from, for their firewall. */
    egressIp: string | null;
    retentionDays: number;
    store: {
        host: string;
        port: number;
        database: string;
        username: string;
        hasPassword: boolean;
        enabled: boolean;
        prune: boolean;
        status: 'ok' | 'unreachable';
        lastError: string | null;
        lastOkAt: string | null;
    } | null;
}>();

const form = useForm({
    host: props.store?.host ?? '',
    port: props.store?.port ?? 5432,
    database: props.store?.database ?? '',
    username: props.store?.username ?? '',
    password: '',
    prune: props.store?.prune ?? true,
});

/** Turning off is still allowed after leaving Business. */
const editable = computed(() => props.canManage && props.planAllows);

function save(): void {
    form.put('/settings/log-storage', {
        preserveScroll: true,
        onSuccess: () => {
            form.password = '';
            form.defaults();
        },
    });
}

/** Why the last test, save or switch didn't work, from any request. */
const page = usePage();
const connectionError = computed(() => page.props.errors.connection);

const busy = ref<'test' | 'enable' | 'disable' | 'remove' | null>(null);

/** A passing test, said beside the buttons (a toast would sit on top of them). */
const tested = ref(false);
watch(
    () => form.data(),
    () => (tested.value = false),
    { deep: true },
);

function send(
    action: 'test' | 'enable' | 'disable' | 'remove',
    onSuccess?: () => void,
): void {
    form.clearErrors();
    tested.value = false;
    const options = {
        preserveScroll: true,
        onStart: () => (busy.value = action),
        onFinish: () => (busy.value = null),
        onError: (errors: Record<string, string>) => form.setError(errors),
        onSuccess,
    };
    if (action === 'remove') {
        router.delete('/settings/log-storage', options);
    } else if (action === 'test') {
        const { prune: _prune, ...connection } = form.data();
        router.post('/settings/log-storage/test', connection, options);
    } else {
        router.post(`/settings/log-storage/${action}`, {}, options);
    }
}

const confirming = ref<'disable' | 'remove' | null>(null);
// What the dialog says, kept while it fades out after `confirming` clears.
const dialogKind = ref<'disable' | 'remove'>('disable');

function ask(kind: 'disable' | 'remove'): void {
    dialogKind.value = kind;
    confirming.value = kind;
}

function confirm(): void {
    if (confirming.value) {
        send(confirming.value, () => (confirming.value = null));
    }
}

/** On, but the plan no longer includes it: new lines go to ours until it does. */
const paused = computed(
    () => Boolean(props.store?.enabled) && !props.planAllows,
);

const status = computed(() => {
    if (paused.value) {
        return {
            label: trans('Paused'),
            tone: 'bg-(--ds-amber-bg) text-(--ds-amber)',
        };
    }
    if (!props.store?.enabled) {
        return {
            label: trans('Off'),
            tone: 'bg-(--ds-active) text-(--ds-text-muted)',
        };
    }
    return props.store.status === 'ok'
        ? {
              label: trans('Working'),
              tone: 'bg-(--ds-green-bg) text-(--ds-green)',
          }
        : {
              label: trans('Can’t reach it'),
              tone: 'bg-(--ds-red-bg) text-(--ds-red)',
          };
});

const statusHint = computed(() => {
    if (!props.store) {
        return trans('Not set up. Logs are kept by ServerOS.');
    }
    if (!props.store.enabled) {
        return trans('Off. New lines go to ServerOS’s storage.');
    }
    if (paused.value) {
        return trans(
            'Paused while the workspace isn’t on Business. New lines go to ServerOS’s storage.',
        );
    }
    if (props.store.status === 'unreachable') {
        return trans('ServerOS can’t reach it right now.');
    }
    return props.store.lastOkAt
        ? trans('New lines go to your database. Last worked :when.', {
              when: timeAgo(props.store.lastOkAt),
          })
        : trans('New lines go to your database.');
});

const copied = ref(false);
async function copyIp(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.egressIp ?? '');
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard blocked; the address is still on screen.
    }
}
</script>

<template>
    <Head :title="$t('Log storage')" />

    <form class="flex flex-col gap-5" @submit.prevent="save">
        <div
            v-if="!planAllows"
            class="ds-tile flex flex-wrap items-center justify-between gap-3 px-5 py-4"
        >
            <p class="max-w-xl text-[13px]/5 text-(--ds-text-secondary)">
                {{
                    paused
                        ? $t(
                              'Your plan doesn’t include your own log storage any more, so new lines go to ServerOS’s storage, with its daily limit. Lines already in your database stay there. Move to Business and new lines go to your database again.',
                          )
                        : $t(
                              'Keeping logs in a Postgres database you own comes with Business.',
                          )
                }}
            </p>
            <Link
                v-if="upgrade"
                :href="upgrade.href"
                :class="appButtonClasses('primary', 'sm')"
            >
                {{ $t('See plans') }}
            </Link>
        </div>

        <Tile
            :title="$t('Log storage')"
            :description="
                $t(
                    'Keep your logs in a Postgres database you own, instead of ours.',
                )
            "
            :icon="Database"
            :padded="false"
        >
            <template #actions>
                <span
                    class="rounded-full px-2 py-px text-[11.5px]/4 font-medium"
                    :class="status.tone"
                    >{{ status.label }}</span
                >
                <template v-if="canManage && store">
                    <AppButton
                        v-if="store.enabled"
                        size="sm"
                        :disabled="busy !== null"
                        @click="ask('disable')"
                    >
                        {{ $t('Turn off') }}
                    </AppButton>
                    <AppButton
                        v-else
                        size="sm"
                        variant="primary"
                        :disabled="!editable || form.isDirty || busy !== null"
                        :title="
                            form.isDirty
                                ? $t('Save your changes first.')
                                : undefined
                        "
                        @click="send('enable')"
                    >
                        {{
                            busy === 'enable'
                                ? $t('Turning on…')
                                : $t('Turn on')
                        }}
                    </AppButton>
                </template>
            </template>

            <fieldset
                :disabled="!editable"
                :class="{ 'opacity-60': !editable }"
            >
                <legend class="sr-only">{{ $t('Connection') }}</legend>
                <div class="ds-tile-rows">
                    <SettingsLine :label="$t('Status')" :hint="statusHint">
                        <p
                            v-if="
                                store?.enabled && store.status === 'unreachable'
                            "
                            class="text-[13px]/5 text-(--ds-red)"
                        >
                            {{ store.lastError }}
                        </p>
                        <InputError :message="connectionError" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Host')"
                        :hint="$t('Its address on the internet, and the port.')"
                        input-id="log-host"
                    >
                        <div class="flex max-w-xl gap-2">
                            <Input
                                id="log-host"
                                v-model="form.host"
                                class="min-w-0 grow font-ui-mono md:text-[13px]"
                                placeholder="db.example.com"
                                spellcheck="false"
                                autocomplete="off"
                                maxlength="253"
                            />
                            <Input
                                v-model.number="form.port"
                                type="number"
                                min="1"
                                max="65535"
                                class="w-24 font-ui-mono md:text-[13px]"
                                :aria-label="$t('Port')"
                            />
                        </div>
                        <InputError
                            :message="form.errors.host || form.errors.port"
                        />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Database')"
                        input-id="log-database"
                    >
                        <Input
                            id="log-database"
                            v-model="form.database"
                            class="max-w-md font-ui-mono md:text-[13px]"
                            placeholder="serveros_logs"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="63"
                        />
                        <InputError :message="form.errors.database" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('User')"
                        :hint="$t('It needs CREATE on the public schema.')"
                        input-id="log-username"
                    >
                        <Input
                            id="log-username"
                            v-model="form.username"
                            class="max-w-md font-ui-mono md:text-[13px]"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="63"
                        />
                        <InputError :message="form.errors.username" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Password')"
                        :hint="
                            store?.hasPassword
                                ? $t('Saved. Leave it blank to keep it.')
                                : undefined
                        "
                        input-id="log-password"
                    >
                        <Input
                            id="log-password"
                            v-model="form.password"
                            type="password"
                            class="max-w-md"
                            autocomplete="new-password"
                            maxlength="200"
                            :placeholder="store?.hasPassword ? '••••••••' : ''"
                        />
                        <InputError :message="form.errors.password" />
                    </SettingsLine>

                    <SettingsLine
                        :label="$t('Old lines')"
                        :hint="
                            form.prune
                                ? $t(
                                      'Delete lines after :days days, like the plan.',
                                      {
                                          days: String(retentionDays),
                                      },
                                  )
                                : $t(
                                      'Keep them. Deleting old lines is up to you.',
                                  )
                        "
                    >
                        <div class="flex h-9 items-center gap-3">
                            <ToggleSwitch
                                v-model="form.prune"
                                :label="$t('Delete old lines')"
                            />
                            <span class="text-[13px]/5 text-(--ds-text-muted)">
                                {{ form.prune ? $t('Delete') : $t('Keep') }}
                            </span>
                        </div>
                    </SettingsLine>

                    <SettingsLine
                        v-if="egressIp"
                        :label="$t('Allow this address')"
                        :hint="
                            $t(
                                'ServerOS connects from here. Let it through your firewall on the port above.',
                            )
                        "
                    >
                        <button
                            type="button"
                            class="flex h-9 w-fit cursor-pointer items-center gap-2 font-ui-mono text-[13px] hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                            :title="$t('Copy')"
                            @click="copyIp"
                        >
                            {{ egressIp }}
                            <component
                                :is="copied ? Check : Copy"
                                class="size-3.5 text-(--ds-text-faint)"
                            />
                        </button>
                    </SettingsLine>
                </div>
            </fieldset>

            <div
                class="flex flex-col gap-1.5 border-t border-(--ds-divider) px-5 py-3 text-[12.5px]/5 text-(--ds-text-muted)"
            >
                <p>
                    {{
                        $t(
                            'Once it’s on, new lines are written to your database and never to ours. Lines you already have stay with us until the plan’s retention removes them. The daily line allowance doesn’t apply.',
                        )
                    }}
                </p>
                <p>
                    {{
                        $t(
                            'You need Postgres 13 or newer, reachable over SSL, and a user that can CREATE in the public schema. ServerOS makes two tables there: log_entries and log_counts.',
                        )
                    }}
                </p>
            </div>

            <template v-if="canManage" #footer>
                <AppButton
                    v-if="store && !store.enabled"
                    size="sm"
                    variant="quiet"
                    class="mr-auto"
                    :disabled="busy !== null"
                    @click="ask('remove')"
                >
                    {{ $t('Remove') }}
                </AppButton>
                <AppButton
                    size="sm"
                    :disabled="!editable || busy !== null || !form.host"
                    @click="send('test', () => (tested = true))"
                >
                    {{
                        busy === 'test' ? $t('Testing…') : $t('Test connection')
                    }}
                </AppButton>
                <span
                    v-if="tested"
                    class="text-[13px]/5 text-(--ds-green)"
                    role="status"
                    >{{ $t('Connected. It’s ready to use.') }}</span
                >
                <SubmitButton
                    :label="$t('Save')"
                    :busy-label="$t('Saving…')"
                    :done="form.recentlySuccessful"
                    :processing="form.processing"
                    :disabled="!editable || !form.isDirty"
                />
            </template>
        </Tile>
    </form>

    <Dialog
        :open="confirming !== null"
        @update:open="(open) => !open && (confirming = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-base/6 font-semibold">
                    {{
                        dialogKind === 'remove'
                            ? $t('Remove the connection?')
                            : $t('Turn off your log storage?')
                    }}
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        dialogKind === 'remove'
                            ? $t(
                                  'ServerOS forgets how to reach it. Lines already in that database stay there.',
                              )
                            : $t(
                                  'New lines go back to ServerOS’s storage. Lines already in your database stay there, but ServerOS can’t show them until you turn it on again.',
                              )
                    }}
                </DialogDescription>
            </div>
            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
            >
                <AppButton size="sm" variant="quiet" @click="confirming = null">
                    {{ $t('Cancel') }}
                </AppButton>
                <form @submit.prevent="confirm">
                    <DangerButton
                        :label="
                            dialogKind === 'remove'
                                ? $t('Remove')
                                : $t('Turn off')
                        "
                        :busy-label="
                            dialogKind === 'remove'
                                ? $t('Removing…')
                                : $t('Turning off…')
                        "
                        :processing="busy === 'disable' || busy === 'remove'"
                        :error="connectionError"
                    />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
