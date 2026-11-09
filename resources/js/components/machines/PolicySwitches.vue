<script setup lang="ts">
import { computed } from 'vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import type {
    IpMasking,
    PolicySettings,
    UpdateMode,
} from '@/lib/machinePolicies';

/**
 * The switches of a machine policy, one row each: what the daemon looks
 * for, sends and how it updates. Health is always on, so it's shown as
 * such rather than as a switch that does nothing. Used by the welcome
 * flow and Settings › Machine policies.
 */
const props = defineProps<{
    disabled?: boolean;
    /** Releases it can stay on; none means pinning isn't offered (the welcome flow). */
    releases?: string[];
}>();

const settings = defineModel<PolicySettings>({ required: true });

function set<K extends keyof PolicySettings>(
    key: K,
    value: PolicySettings[K],
): void {
    settings.value = { ...settings.value, [key]: value };
}

const maskings: { key: IpMasking; label: string }[] = [
    { key: 'off', label: 'As they are' },
    { key: 'partial', label: 'Partly hidden' },
    { key: 'hash', label: 'Coded' },
];
const updateModes = computed<{ key: UpdateMode; label: string }[]>(() => [
    { key: 'automatic', label: 'Automatic' },
    { key: 'approve', label: 'Ask first' },
    ...(props.releases?.length
        ? [{ key: 'pinned' as const, label: 'Stay on one' }]
        : []),
]);

function setUpdates(mode: UpdateMode): void {
    settings.value = {
        ...settings.value,
        updates: mode,
        pinned_version:
            mode === 'pinned'
                ? (settings.value.pinned_version ?? props.releases?.[0] ?? null)
                : null,
    };
}

const row = 'flex items-center gap-4 px-3.5 py-3';
const title = 'text-[14px]/5 font-medium text-(--ds-text)';
const body = 'text-[13px]/4.5 text-(--ds-text-muted)';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-panel)"
    >
        <div :class="row">
            <div class="flex grow flex-col">
                <span :class="title">{{ $t('Find what’s running') }}</span>
                <span :class="body">{{
                    $t(
                        'Reads services and ports when it connects, then every hour. Nothing restarts.',
                    )
                }}</span>
            </div>
            <ToggleSwitch
                :model-value="settings.discovery"
                :label="$t('Find what’s running')"
                :disabled="disabled"
                @update:model-value="set('discovery', $event)"
            />
        </div>

        <div :class="row">
            <div class="flex grow flex-col">
                <span :class="title">{{ $t('Health and uptime') }}</span>
                <span :class="body">{{
                    $t(
                        'CPU, memory, disk and network. It’s how ServerOS knows the server is up.',
                    )
                }}</span>
            </div>
            <span
                class="shrink-0 text-[12.5px]/4 font-medium whitespace-nowrap text-(--ds-text-faint)"
            >
                {{ $t('Always on') }}
            </span>
        </div>

        <div class="flex flex-col gap-2.5 px-3.5 py-3">
            <div class="flex items-center gap-4">
                <div class="flex grow flex-col">
                    <span :class="title">{{ $t('Collect logs') }}</span>
                    <span :class="body">{{
                        $t(
                            'Searchable service logs, kept for your plan’s days.',
                        )
                    }}</span>
                </div>
                <ToggleSwitch
                    :model-value="settings.logs"
                    :label="$t('Collect logs')"
                    :disabled="disabled"
                    @update:model-value="set('logs', $event)"
                />
            </div>
            <div
                v-if="settings.logs"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5"
            >
                <span class="text-[13px]/4.5 text-(--ds-text-secondary)">
                    {{ $t('IP addresses in them') }}
                </span>
                <div
                    class="ds-segments ds-segments--sm"
                    role="group"
                    :aria-label="$t('IP addresses in them')"
                >
                    <button
                        v-for="option in maskings"
                        :key="option.key"
                        type="button"
                        class="cursor-pointer"
                        :class="focusRing"
                        :disabled="disabled"
                        :aria-pressed="settings.log_ip_masking === option.key"
                        @click="set('log_ip_masking', option.key)"
                    >
                        {{ $t(option.label) }}
                    </button>
                </div>
            </div>
        </div>

        <div :class="row">
            <div class="flex grow flex-col">
                <span :class="title">{{ $t('Security checks') }}</span>
                <span :class="body">{{
                    $t('SSH settings, failed sign-ins and security updates.')
                }}</span>
            </div>
            <ToggleSwitch
                :model-value="settings.security_watch"
                :label="$t('Security checks')"
                :disabled="disabled"
                @update:model-value="set('security_watch', $event)"
            />
        </div>

        <div :class="row">
            <div class="flex grow flex-col">
                <span :class="title">{{ $t('Error reports') }}</span>
                <span :class="body">{{
                    $t(
                        'When the daemon crashes or hits an error, it tells us so we can fix it. Secrets, hostnames and IPs are removed first.',
                    )
                }}</span>
            </div>
            <ToggleSwitch
                :model-value="settings.error_reports"
                :label="$t('Error reports')"
                :disabled="disabled"
                @update:model-value="set('error_reports', $event)"
            />
        </div>

        <div :class="row">
            <div class="flex grow flex-col">
                <span :class="title">{{
                    $t('Add new services to status pages')
                }}</span>
                <span :class="body">{{
                    settings.status_page_new
                        ? $t(
                              'A new status page shows services as they’re found.',
                          )
                        : $t('Off: a status page shows only what you pick.')
                }}</span>
            </div>
            <ToggleSwitch
                :model-value="settings.status_page_new"
                :label="$t('Add new services to status pages')"
                :disabled="disabled"
                @update:model-value="set('status_page_new', $event)"
            />
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3.5 py-3">
            <div class="flex min-w-48 grow basis-0 flex-col">
                <span :class="title">{{ $t('Daemon updates') }}</span>
                <span :class="body">{{
                    settings.updates === 'pinned'
                        ? $t(
                              'Stays on this release, even when a newer one is out.',
                          )
                        : settings.updates === 'approve'
                          ? $t('Nothing installs until you approve it.')
                          : $t('Installs new releases on its own.')
                }}</span>
            </div>
            <div
                class="ds-segments ds-segments--sm shrink-0"
                role="group"
                :aria-label="$t('Daemon updates')"
            >
                <button
                    v-for="option in updateModes"
                    :key="option.key"
                    type="button"
                    class="cursor-pointer"
                    :class="focusRing"
                    :disabled="disabled"
                    :aria-pressed="settings.updates === option.key"
                    @click="setUpdates(option.key)"
                >
                    {{ $t(option.label) }}
                </button>
            </div>
            <select
                v-if="settings.updates === 'pinned' && releases?.length"
                :value="settings.pinned_version ?? ''"
                class="ds-select ds-raised-button h-8 w-full cursor-pointer appearance-none pr-8! text-[13px]"
                :aria-label="$t('Release to stay on')"
                :disabled="disabled"
                @change="
                    set(
                        'pinned_version',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option
                    v-for="(release, index) in releases"
                    :key="release"
                    :value="release"
                >
                    {{
                        index === 0
                            ? $t(':version (newest)', { version: release })
                            : release
                    }}
                </option>
            </select>
        </div>
    </div>
</template>
