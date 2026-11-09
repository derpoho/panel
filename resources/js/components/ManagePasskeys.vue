<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { SolarKeyRound as KeyRound } from '@/lib/solarIcons';
import type { Passkey } from '@/types/auth';
import PasskeyItem from '@/components/PasskeyItem.vue';
import PasskeyRegister from '@/components/PasskeyRegister.vue';
import Tile from '@/components/workspace/Tile.vue';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

withDefaults(defineProps<Props>(), {
    canManagePasskeys: false,
    passkeys: () => [],
});

const handleDelete = (id: number, onError: () => void) => {
    router.delete(destroy.url(id), {
        preserveScroll: true,
        onError,
    });
};

const handleRegisterSuccess = () => {
    router.reload();
};
</script>

<template>
    <Tile
        v-if="canManagePasskeys"
        :title="$t('Passkeys')"
        :description="
            $t(
                'Sign in with Touch ID, Face ID, Windows Hello or a security key instead of a password.',
            )
        "
        :icon="KeyRound"
        :padded="false"
    >
        <div v-if="passkeys.length" class="divide-y divide-(--ds-divider)">
            <PasskeyItem
                v-for="passkey in passkeys"
                :key="passkey.id"
                :passkey="passkey"
                @remove="handleDelete"
            />
        </div>
        <p v-else class="px-4.5 py-6 text-[13px]/5 text-(--ds-text-muted)">
            {{
                ' ' +
                $t('No passkeys yet. Add one to sign in without a password.') +
                ' '
            }}
        </p>

        <div class="border-t border-(--ds-divider) px-4.5 py-4">
            <PasskeyRegister @success="handleRegisterSuccess" />
        </div>
    </Tile>
</template>
