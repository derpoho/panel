<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import {
    SolarBell as Bell,
    SolarGitBranch as GitBranch,
    SolarMoon as Moon,
    SolarArchive as Archive,
    SolarClock as Clock,
    SolarGlobe as Globe,
    SolarHeartPulse as HeartPulse,
    SolarMailCheck as MailCheck,
    SolarOctagonX as OctagonX,
    SolarRocket as Rocket,
    SolarSiren as Siren,
    SolarSparkles as Sparkles,
    SolarSunrise as Sunrise,
    SolarTerminal as Terminal,
    SolarRefreshCw as RefreshCw,
    SolarFolderGit2 as FolderGit,
    SolarDatabaseBackup as DatabaseBackup,
    SolarCircleArrowUp as CircleArrowUp,
    SolarSquarePlus as SquarePlus,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import type { Component } from 'vue';

export type Template = {
    key: string;
    name: string;
    description: string;
    audience: string;
    for: string;
};

/**
 * Starting points for a workflow, by who they're for: someone new to
 * servers sees plain recipes first, a team sees on-call and hours.
 */
const props = defineProps<{
    templates: Template[];
    disabled?: boolean;
    creating?: string | null;
    /** Tighter cards, for the dialog. */
    compact?: boolean;
}>();

const emit = defineEmits<{ pick: [key: string] }>();

const audiences = [
    { value: 'new', label: trans('New to servers') },
    { value: 'developers', label: trans('Developers') },
    { value: 'agencies', label: trans('Agencies') },
    { value: 'teams', label: trans('Teams at scale') },
];

const STORAGE = 'serveros:workflow-audience';
function remembered(): string {
    try {
        const v = localStorage.getItem(STORAGE);

        return audiences.some((a) => a.value === v) ? (v as string) : 'new';
    } catch {
        return 'new';
    }
}
const audience = ref(remembered());
function choose(value: string): void {
    audience.value = value;
    try {
        localStorage.setItem(STORAGE, value);
    } catch {
        /* Private mode: fine to forget. */
    }
}

const shown = computed(() =>
    props.templates.filter(
        (t) => t.audience === audience.value || t.audience === 'everyone',
    ),
);

const icons: Record<string, Component> = {
    'site-down': HeartPulse,
    'restart-crashed': RefreshCw,
    'nightly-backup': Archive,
    'nightly-restart': Moon,
    'ci-deploy': Rocket,
    'migrate-after-deploy': Terminal,
    'smoke-test': Globe,
    'deploy-notify': Bell,
    'client-site-down': MailCheck,
    'backup-all': Clock,
    'client-deploy-notice': MailCheck,
    'page-oncall': Siren,
    'business-hours': Sunrise,
    'crash-loop': OctagonX,
    'laravel-push': FolderGit,
    'staging-refresh': DatabaseBackup,
    'staging-promote': CircleArrowUp,
    'agent-fix-deploy': Sparkles,
    'agent-issues': Sparkles,
    blank: SquarePlus,
};

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div class="flex min-h-0 flex-col gap-4">
        <div class="max-w-full shrink-0 overflow-x-auto">
            <div
                class="ds-segments"
                role="group"
                :aria-label="$t('Who it’s for')"
            >
                <button
                    v-for="a in audiences"
                    :key="a.value"
                    type="button"
                    class="cursor-pointer"
                    :class="focusRing"
                    :aria-pressed="audience === a.value"
                    @click="choose(a.value)"
                >
                    {{ a.label }}
                </button>
            </div>
        </div>

        <ul
            class="grid gap-3"
            :class="
                compact
                    ? '-mx-1 min-h-0 overflow-y-auto overscroll-contain px-1 pb-1 sm:grid-cols-2'
                    : 'sm:grid-cols-2 lg:grid-cols-3'
            "
        >
            <li v-for="t in shown" :key="t.key">
                <button
                    type="button"
                    class="flex h-full w-full cursor-pointer flex-col items-start gap-2 text-left transition-colors hover:bg-(--ds-row-hover) disabled:cursor-default disabled:opacity-60"
                    :class="[
                        focusRing,
                        compact
                            ? 'rounded-[10px] border border-(--ds-border) p-3.5'
                            : 'ds-tile p-4',
                    ]"
                    :disabled="disabled || (creating ?? null) !== null"
                    @click="emit('pick', t.key)"
                >
                    <span class="flex w-full items-start gap-2.5">
                        <span class="ds-icon-chip size-8!" aria-hidden="true">
                            <component
                                :is="icons[t.key] ?? GitBranch"
                                class="size-4"
                                :stroke-width="2"
                            />
                        </span>
                        <span class="pt-1.5 text-sm/5 font-semibold">{{
                            t.name
                        }}</span>
                    </span>
                    <span
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        :class="compact ? 'line-clamp-3' : ''"
                        :title="compact ? t.description : undefined"
                        >{{ t.description }}</span
                    >
                    <span
                        v-if="creating === t.key"
                        class="text-xs text-(--ds-text-faint)"
                        >{{ $t('Opening…') }}</span
                    >
                </button>
            </li>
        </ul>
    </div>
</template>
