<script setup lang="ts">
import Tile from '@/components/workspace/Tile.vue';
import { SolarHistory as History } from '@/lib/solarIcons';
import { shortDate, timeAgo } from '@/lib/timeAgo';

export type SecurityEvent = {
    id: number;
    text: string;
    /** ServerOS Support, when staff did it. */
    by: string | null;
    device: string | null;
    ip: string | null;
    tone: 'good' | 'warn' | 'bad' | 'neutral';
    createdAt: string;
};

/**
 * Recent sign-ins and changes to how you sign in, newest first, so
 * something you didn't do stands out.
 */
defineProps<{ events: SecurityEvent[] }>();

const dot: Record<SecurityEvent['tone'], string> = {
    good: 'bg-(--ds-green)',
    warn: 'bg-(--ds-amber)',
    bad: 'bg-(--ds-red)',
    neutral: 'bg-(--ds-text-muted)/50',
};
</script>

<template>
    <Tile
        :title="$t('Recent security activity')"
        :description="
            $t(
                'Sign-ins and changes to how you sign in. If something here wasn’t you, change your password and sign out your other devices.',
            )
        "
        :icon="History"
    >
        <ul class="flex flex-col divide-y divide-(--ds-divider)">
            <li
                v-for="event in events"
                :key="event.id"
                class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0"
            >
                <span
                    class="mt-2 size-1.5 shrink-0 rounded-full"
                    :class="dot[event.tone]"
                    aria-hidden="true"
                />
                <div class="min-w-0 grow">
                    <p class="text-[13.5px]/5">{{ event.text }}</p>
                    <p
                        class="truncate text-[12.5px]/4.5 text-(--ds-text-muted)"
                    >
                        {{
                            event.by
                                ? $t('By :name', { name: event.by })
                                : [event.device, event.ip]
                                      .filter(Boolean)
                                      .join(' · ')
                        }}
                    </p>
                </div>
                <span
                    class="shrink-0 text-xs/5 text-(--ds-text-muted)"
                    :title="shortDate(event.createdAt)"
                    >{{ timeAgo(event.createdAt) }}</span
                >
            </li>
        </ul>
        <p
            v-if="events.length === 0"
            class="text-[13px]/5 text-(--ds-text-muted)"
        >
            {{ $t('Nothing yet. Sign-ins and changes will show here.') }}
        </p>
    </Tile>
</template>
