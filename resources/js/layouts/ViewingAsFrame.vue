<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { SolarEye as Eye } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import { appButtonClasses } from '@/lib/appButton';

/**
 * Wraps every signed-in page. While an admin is viewing as someone, a bar
 * at the bottom says whose account this is and leads back.
 */
const page = usePage<{
    viewingAs: { name: string; email: string; admin: string | null } | null;
}>();
const viewingAs = computed(() => page.props.viewingAs);
const leaving = ref(false);

function leave(): void {
    router.post(
        '/admin/return',
        {},
        {
            onStart: () => (leaving.value = true),
            onFinish: () => (leaving.value = false),
        },
    );
}
</script>

<template>
    <slot />
    <div
        v-if="viewingAs"
        class="fixed inset-x-0 bottom-4 z-[60] flex justify-center px-4"
        role="status"
    >
        <div
            class="ds-root flex max-w-full items-center gap-3 rounded-[10px] border border-(--ds-border-strong) bg-(--ds-panel) py-2 pr-2 pl-3.5 font-ui text-[13px]/5 text-(--ds-text) shadow-lg"
        >
            <Eye
                class="size-4 shrink-0 text-(--ds-amber)"
                :stroke-width="1.75"
            />
            <span class="min-w-0 truncate">
                {{ ' ' + $t('Viewing as') }}
                <span class="font-medium">{{ viewingAs.name }}</span>
                <span class="text-(--ds-text-muted)">
                    · {{ viewingAs.email }}</span
                >
            </span>
            <button
                type="button"
                :class="appButtonClasses('primary', 'sm')"
                :disabled="leaving"
                @click="leave"
            >
                {{ leaving ? $t('Returning…') : $t('Back to admin') }}
            </button>
        </div>
    </div>
</template>
