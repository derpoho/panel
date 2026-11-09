<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarMonitor as Monitor } from '@/lib/solarIcons';
import { timeAgo } from '@/lib/timeAgo';

export type SignedInDevice = {
    key: string;
    device: string | null;
    ip: string | null;
    lastActiveAt: string;
    current: boolean;
};

/**
 * The browsers signed in to your account, this one first, and signing
 * one or all the others out.
 */
defineProps<{ devices: SignedInDevice[] }>();

const busy = ref<string | null>(null);

function signOut(key: string | null): void {
    busy.value = key ?? 'others';
    router.delete(key ? `/settings/sessions/${key}` : '/settings/sessions', {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}
</script>

<template>
    <Tile
        :title="$t('Signed-in devices')"
        :description="
            $t(
                'Where your account is signed in now. Sign out anything you don’t recognise, then change your password.',
            )
        "
        :icon="Monitor"
    >
        <template v-if="devices.some((d) => !d.current)" #actions>
            <button
                type="button"
                :class="appButtonClasses('secondary', 'sm')"
                :disabled="busy !== null"
                @click="signOut(null)"
            >
                {{
                    busy === 'others'
                        ? $t('Signing out…')
                        : $t('Sign out everywhere else')
                }}
            </button>
        </template>
        <ul class="flex flex-col divide-y divide-(--ds-divider)">
            <li
                v-for="device in devices"
                :key="device.key"
                class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
            >
                <div class="min-w-0 grow">
                    <p class="text-[13.5px]/5 font-medium">
                        {{ device.device ?? $t('Unknown device') }}
                        <span
                            v-if="device.current"
                            class="ml-1.5 rounded-full bg-(--ds-green)/15 px-1.5 py-0.5 text-[11px] font-medium text-(--ds-green)"
                            >{{ $t('This device') }}</span
                        >
                    </p>
                    <p
                        class="truncate text-[12.5px]/4.5 text-(--ds-text-muted)"
                    >
                        {{ device.ip ?? '' }}
                        {{ device.ip ? '·' : '' }}
                        {{
                            device.current
                                ? $t('Active now')
                                : $t('Last active :time', {
                                      time: timeAgo(device.lastActiveAt),
                                  })
                        }}
                    </p>
                </div>
                <button
                    v-if="!device.current"
                    type="button"
                    :class="appButtonClasses('secondary', 'sm')"
                    :disabled="busy !== null"
                    @click="signOut(device.key)"
                >
                    {{
                        busy === device.key
                            ? $t('Signing out…')
                            : $t('Sign out')
                    }}
                </button>
            </li>
        </ul>
        <p
            v-if="devices.length === 0"
            class="text-[13px]/5 text-(--ds-text-muted)"
        >
            {{ $t('No other devices are signed in.') }}
        </p>
    </Tile>
</template>
