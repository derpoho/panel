<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarLink2 as Link2 } from '@/lib/solarIcons';

export type ConnectedAccount = {
    provider: string;
    label: string;
    connected: boolean;
    email: string | null;
    connectedAt: string | null;
    canDisconnect: boolean;
};

/**
 * Google and GitHub accounts you can sign in with: connect one (off to the
 * provider and back) or disconnect it, never leaving no way to sign in.
 */
defineProps<{
    accounts: ConnectedAccount[];
    hasPassword: boolean;
}>();

const busy = ref<string | null>(null);

function disconnect(account: ConnectedAccount): void {
    busy.value = account.provider;
    router.delete(`/settings/connections/${account.provider}`, {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}

function since(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';
}
</script>

<template>
    <Tile
        v-if="accounts.length"
        :title="$t('Connected accounts')"
        :description="
            $t('Sign in with Google or GitHub instead of a password.')
        "
        :icon="Link2"
    >
        <ul class="flex flex-col divide-y divide-(--ds-divider)">
            <li
                v-for="account in accounts"
                :key="account.provider"
                class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-(--ds-border) bg-(--ds-panel)"
                >
                    <ProviderLogo
                        :provider="account.provider"
                        class="size-4.5"
                    />
                </span>
                <div class="min-w-0 grow">
                    <p class="text-[13.5px]/5 font-medium">
                        {{ account.label }}
                    </p>
                    <p
                        class="truncate text-[12.5px]/4.5 text-(--ds-text-muted)"
                    >
                        {{
                            account.connected
                                ? `${account.email ?? 'Connected'} · since ${since(account.connectedAt)}`
                                : $t('Not connected')
                        }}
                    </p>
                </div>
                <!-- A plain link: the flow leaves for the provider and comes back. -->
                <a
                    v-if="!account.connected"
                    :href="`/settings/connections/${account.provider}`"
                    :class="appButtonClasses('secondary', 'sm')"
                >
                    {{ ' ' + $t('Connect') + ' ' }}
                </a>
                <button
                    v-else
                    type="button"
                    :class="appButtonClasses('secondary', 'sm')"
                    :disabled="
                        !account.canDisconnect || busy === account.provider
                    "
                    :title="
                        account.canDisconnect
                            ? undefined
                            : $t(
                                  'It’s your only way to sign in. Set a password or connect another account first.',
                              )
                    "
                    @click="disconnect(account)"
                >
                    {{
                        busy === account.provider
                            ? $t('Disconnecting…')
                            : $t('Disconnect')
                    }}
                </button>
            </li>
        </ul>
        <p
            v-if="!hasPassword"
            class="mt-4 text-[12.5px]/5 text-(--ds-text-muted)"
        >
            {{
                ' ' +
                $t(
                    'You signed up with :value and haven’t set a password. Set one above to sign in without it.',
                    {
                        value:
                            accounts.find((a) => a.connected)?.label ??
                            $t('an account'),
                    },
                ) +
                ' '
            }}
        </p>
    </Tile>
</template>
