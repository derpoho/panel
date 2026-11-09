<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { ref } from 'vue';
import NotchToaster from '@/components/workspace/NotchToaster.vue';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import CommandPalette from '@/components/workspace/CommandPalette.vue';
import { useIsDark } from '@/composables/useAppearance';
import { panelShadow } from '@/lib/workspacePanel';

const page = usePage();
const isDark = useIsDark();
const searching = ref(false);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="ds-root flex h-dvh flex-col overflow-hidden bg-(--ds-frame) font-ui text-(--ds-text) antialiased"
    >
        <a
            href="#main"
            class="sr-only z-50 rounded-[6px] bg-(--ds-hover) px-3 py-1.5 text-xs focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:outline-2 focus:outline-(--ds-ring)"
        >
            {{ ' ' + $t('Skip to content') + ' ' }}
        </a>

        <header class="flex h-14 shrink-0 items-center gap-2 px-4 sm:px-5">
            <a
                :href="page.props.appUrl"
                class="flex min-w-0 items-center gap-2.25 rounded-md"
                :class="focusRing"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-4.5 shrink-0 object-contain invert dark:invert-0"
                />
                <span class="text-[15px]/4.5 font-semibold tracking-[-0.01em]">
                    {{ ' ' + $t('ServerOS') + ' ' }}
                </span>
            </a>

            <button
                type="button"
                class="ml-auto flex size-10 cursor-pointer items-center justify-center rounded-[10px] text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text) sm:h-9 sm:w-56 sm:justify-start sm:gap-2 sm:border sm:border-(--ds-border-strong) sm:bg-(--ds-panel) sm:px-3 sm:text-[13px]/4 sm:text-(--ds-text-faint) sm:hover:bg-(--ds-panel)"
                :class="focusRing"
                aria-haspopup="dialog"
                :aria-label="$t('Search (⌘K)')"
                @click="searching = true"
            >
                <Search
                    class="size-4 shrink-0 sm:size-3.5 sm:text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="hidden sm:inline">{{ $t('Search') }}</span>
                <kbd
                    class="ml-auto hidden rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px font-ui text-[11px]/3.5 text-(--ds-text-muted) sm:inline"
                    aria-hidden="true"
                >
                    ⌘K
                </kbd>
            </button>

            <AccountMenu display="avatar" side="bottom" align="end" />
        </header>

        <div class="flex min-h-0 grow px-2 pb-2">
            <main
                id="main"
                class="min-w-0 grow overflow-hidden rounded-[10px] bg-(--ds-panel)"
                :style="{ boxShadow: panelShadow }"
            >
                <div class="h-full overflow-y-auto overscroll-contain">
                    <slot />
                </div>
            </main>
        </div>

        <CommandPalette v-model:open="searching" />
        <NotchToaster />
    </div>
</template>
