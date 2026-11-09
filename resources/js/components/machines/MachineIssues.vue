<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AskAiButton from '@/components/workspace/AskAiButton.vue';
import type { HealthIssue } from '@/lib/healthIssues';

/**
 * What's wrong with this machine that Ask AI can look into and fix with
 * you, at the top of its page: a database the internet can reach, a full
 * disk, a service that keeps crashing. The same problems Home lists, so
 * you meet them where you'd fix them. Others (offline, maintenance) have
 * banners of their own.
 */
const props = defineProps<{ issues: HealthIssue[] }>();

const shown = computed(() =>
    props.issues.filter((issue) => issue.ask && issue.severity !== 'notice'),
);

const dot: Record<HealthIssue['severity'], string> = {
    critical: 'bg-(--ds-red)',
    warning: 'bg-(--ds-amber)',
    notice: 'bg-(--ds-text-faint)',
};

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <section
        v-if="shown.length"
        class="ds-tile divide-y divide-(--ds-divider)"
        :aria-label="$t('Needs attention')"
    >
        <div
            v-for="issue in shown"
            :key="issue.id"
            class="flex items-center gap-3 px-4 py-3 max-sm:flex-col max-sm:items-start"
        >
            <span
                class="size-2 shrink-0 rounded-full max-sm:hidden"
                :class="dot[issue.severity]"
                :aria-label="issue.severity"
            />
            <div class="min-w-0 grow">
                <p class="text-[13px]/5 font-medium text-(--ds-text)">
                    {{ issue.title }}
                </p>
                <p
                    class="line-clamp-1 text-[13px]/5 text-(--ds-text-secondary)"
                    :title="issue.detail"
                >
                    {{ issue.detail }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <AskAiButton :prompt="issue.ask!" />
                <Link
                    :href="issue.href"
                    class="ds-raised-button shrink-0 text-[13px]!"
                    :class="focusRing"
                >
                    {{ issue.action }}
                </Link>
            </div>
        </div>
    </section>
</template>
