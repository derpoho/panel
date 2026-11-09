<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Circle, Minus, X } from '@lucide/vue';
import {
    SolarEyeOff as EyeOff,
    SolarLock as Lock,
    SolarCircleCheck as CircleCheck,
} from '@/lib/solarIcons';
import { computed, onMounted, ref, watch } from 'vue';
import type { SetupGuide } from '@/types';
import { onWorkspace } from '@/lib/workspaceRoute';
import onboardingRoutes from '@/routes/workspace/onboarding';

/**
 * The setup guide: a button in the top bar with a ring that fills as steps
 * get done, and a card in the bottom corner styled like the account menu.
 * The next step is the only one spelled out. The card can shrink to its
 * header or close; "Hide the guide" puts it away for good (the home page
 * brings it back).
 */
const page = usePage<{
    setupGuide: SetupGuide | null;
    workspace: { slug: string };
}>();
const guide = computed(() => page.props.setupGuide);

const steps = computed(() =>
    (guide.value?.groups ?? []).flatMap((group) =>
        group.steps.map((step) => ({ ...step, locked: group.locked })),
    ),
);
const doneCount = computed(
    () => steps.value.filter((step) => step.done).length,
);
const nextKey = computed(
    () => steps.value.find((step) => !step.done && !step.locked)?.key ?? null,
);

const STORAGE_KEY = 'setup-guide';
const open = ref(false);
const minimised = ref(false);

onMounted(() => {
    try {
        const saved = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '{}',
        ) as { open?: boolean; minimised?: boolean };
        open.value = saved.open === true;
        minimised.value = saved.minimised === true;
    } catch {
        // Storage may be unavailable (private mode, blocked site data).
    }
});

watch([open, minimised], () => {
    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ open: open.value, minimised: minimised.value }),
        );
    } catch {
        // Storage may be unavailable (private mode, blocked site data).
    }
});

function toggle(): void {
    if (open.value && !minimised.value) {
        open.value = false;

        return;
    }
    open.value = true;
    minimised.value = false;
}

function hide(): void {
    open.value = false;
    router.post(
        onWorkspace(onboardingRoutes.dismiss(page.props.workspace.slug)).url,
        {},
        { preserveScroll: true },
    );
}

/* The ring around the button: 2πr for r = 7. */
const CIRCUMFERENCE = 2 * Math.PI * 7;

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const iconButton =
    'flex size-6 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)';
</script>

<template>
    <template v-if="guide">
        <button
            type="button"
            class="ds-raised-button hidden cursor-pointer sm:inline-flex"
            :class="focusRing"
            :aria-expanded="open && !minimised"
            aria-controls="setup-guide"
            @click="toggle"
        >
            <!-- Pops on hover, and the arc fills again from empty. -->
            <svg
                viewBox="0 0 18 18"
                class="mi-ring size-3.5"
                :style="{ '--ring': CIRCUMFERENCE }"
                aria-hidden="true"
            >
                <g transform="rotate(-90 9 9)">
                    <circle
                        cx="9"
                        cy="9"
                        r="7"
                        fill="none"
                        stroke="var(--ds-border-stronger)"
                        stroke-width="2.5"
                    />
                    <circle
                        cx="9"
                        cy="9"
                        r="7"
                        fill="none"
                        stroke="var(--ds-green)"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        :stroke-dasharray="CIRCUMFERENCE"
                        :stroke-dashoffset="
                            CIRCUMFERENCE * (1 - guide.percent / 100)
                        "
                        class="mi-ring-fill transition-[stroke-dashoffset] duration-500"
                    />
                </g>
            </svg>
            {{ $t('Setup guide') + ' ' }}
        </button>

        <Teleport to="body">
            <Transition
                enter-from-class="translate-y-2 opacity-0"
                leave-to-class="translate-y-2 opacity-0"
                enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
                leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
            >
                <section
                    v-if="open"
                    id="setup-guide"
                    aria-labelledby="setup-guide-title"
                    class="ds-modal fixed right-4 bottom-4 z-40 w-[min(300px,calc(100vw-2rem))] p-1 font-ui text-(--ds-text) antialiased"
                    @keydown.esc="open = false"
                >
                    <!-- Laid out like the account menu: a header, then rows. -->
                    <header
                        class="flex min-w-0 items-center gap-2.5 px-2 py-1.5"
                    >
                        <svg
                            viewBox="0 0 32 32"
                            class="size-8 shrink-0 -rotate-90"
                            :style="{ '--ring': 2 * Math.PI * 13 }"
                            aria-hidden="true"
                        >
                            <circle
                                cx="16"
                                cy="16"
                                r="13"
                                fill="none"
                                stroke="var(--ds-border-stronger)"
                                stroke-width="3"
                            />
                            <circle
                                cx="16"
                                cy="16"
                                r="13"
                                fill="none"
                                stroke="var(--ds-green)"
                                stroke-width="3"
                                stroke-linecap="round"
                                :stroke-dasharray="2 * Math.PI * 13"
                                :stroke-dashoffset="
                                    2 * Math.PI * 13 * (1 - guide.percent / 100)
                                "
                                class="mi-ring-fill transition-[stroke-dashoffset] duration-500"
                            />
                        </svg>
                        <span
                            class="flex min-w-0 grow flex-col gap-0.5 text-xs/4"
                        >
                            <span
                                id="setup-guide-title"
                                class="truncate font-medium text-(--ds-text)"
                            >
                                {{ ' ' + $t('Setup guide') + ' ' }}
                            </span>
                            <span
                                class="truncate text-(--ds-text-muted) tabular-nums"
                            >
                                {{
                                    $t(':doneCount of :length done', {
                                        doneCount: String(doneCount ?? ''),
                                        length: String(steps.length ?? ''),
                                    }) + ' '
                                }}
                            </span>
                        </span>
                        <button
                            type="button"
                            :class="[iconButton, focusRing]"
                            :aria-label="
                                minimised ? $t('Expand') : $t('Minimise')
                            "
                            :aria-expanded="!minimised"
                            @click="minimised = !minimised"
                        >
                            <Minus
                                class="mi-dip size-3.5"
                                :class="{ 'mi-dip-up': minimised }"
                                :stroke-width="1.75"
                            />
                        </button>
                        <button
                            type="button"
                            :class="[iconButton, focusRing]"
                            :aria-label="$t('Close')"
                            @click="open = false"
                        >
                            <X class="mi-close size-3.5" :stroke-width="1.75" />
                        </button>
                    </header>

                    <template v-if="!minimised">
                        <template
                            v-for="group in guide.groups"
                            :key="group.key"
                        >
                            <div
                                class="-mx-1 my-1 h-px bg-border"
                                role="separator"
                            />
                            <p
                                class="px-2 pt-1 pb-0.5 text-xs/4 text-(--ds-text-muted)"
                            >
                                {{ group.title }}
                            </p>
                            <ul>
                                <li v-for="step in group.steps" :key="step.key">
                                    <div
                                        v-if="group.locked && !step.done"
                                        class="flex items-center gap-2 px-2 py-1.5 text-sm text-(--ds-text-faint)"
                                        :title="group.locked"
                                    >
                                        <Lock
                                            class="size-4"
                                            :stroke-width="1.75"
                                        />
                                        {{ step.title }}
                                        <span class="ml-auto text-xs">
                                            {{
                                                ' ' +
                                                $t('After a machine') +
                                                ' '
                                            }}
                                        </span>
                                    </div>
                                    <Link
                                        v-else
                                        :href="step.href"
                                        class="flex items-start gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground"
                                        :class="focusRing"
                                    >
                                        <CircleCheck
                                            v-if="step.done"
                                            class="mt-0.5 size-4 shrink-0 text-(--ds-green)"
                                            :stroke-width="1.75"
                                        />
                                        <Circle
                                            v-else
                                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            :stroke-width="1.75"
                                        />
                                        <span class="min-w-0 grow">
                                            <span
                                                class="block"
                                                :class="{
                                                    'text-(--ds-text-faint)':
                                                        step.done,
                                                }"
                                            >
                                                {{ step.title }}
                                            </span>
                                            <span
                                                v-if="step.key === nextKey"
                                                class="block text-xs/4 text-(--ds-text-muted)"
                                            >
                                                {{ step.body }}
                                            </span>
                                        </span>
                                        <ArrowRight
                                            v-if="step.key === nextKey"
                                            class="mi-arrow mt-0.75 size-3.5 shrink-0 text-(--ds-text-faint)"
                                            :stroke-width="1.75"
                                        />
                                    </Link>
                                </li>
                            </ul>
                        </template>

                        <div
                            class="-mx-1 my-1 h-px bg-border"
                            role="separator"
                        />
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground"
                            :class="focusRing"
                            @click="hide"
                        >
                            <EyeOff
                                class="mi-tilt size-4 text-muted-foreground"
                                :stroke-width="1.75"
                            />
                            {{ $t('Hide the guide') + ' ' }}
                        </button>
                    </template>
                </section>
            </Transition>
        </Teleport>
    </template>
</template>
