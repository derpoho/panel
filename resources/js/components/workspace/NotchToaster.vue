<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import {
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
    SolarInfo as Info,
    SolarTriangleAlert as TriangleAlert,
} from '@/lib/solarIcons';
import type { Component } from 'vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { currentToast, toast } from '@/lib/toast';
import type { ToastType } from '@/lib/toast';

/**
 * The dashboard's toasts as a notch hanging from the top edge of the
 * window, its shoulders curving into the edge. It drops in, reshapes to
 * fit each new message, waits while the pointer is on it, and slides
 * back up. Mount once per layout.
 */
const icons: Record<ToastType, Component | null> = {
    default: null,
    success: CircleCheck,
    error: CircleAlert,
    warning: TriangleAlert,
    info: Info,
    loading: LoaderCircle,
};

const shown = computed(() => currentToast.value);

/* The width follows the message, measured, so it can glide between them. */
const content = useTemplateRef<HTMLElement>('content');
const width = ref<number | null>(null);
let observer: ResizeObserver | undefined;

function observe(): void {
    observer?.disconnect();
    if (!content.value || !('ResizeObserver' in window)) return;
    observer = new ResizeObserver(([entry]) => {
        width.value =
            entry.borderBoxSize?.[0]?.inlineSize ??
            entry.target.getBoundingClientRect().width;
    });
    observer.observe(content.value);
}

/* Each message waits its duration, paused while the pointer is on it. */
let timer: ReturnType<typeof setTimeout> | undefined;
let left = 0;
let started = 0;
const hovering = ref(false);

function stop(): void {
    clearTimeout(timer);
    timer = undefined;
}

function start(ms: number): void {
    stop();
    if (!Number.isFinite(ms)) return;
    left = ms;
    started = Date.now();
    const id = shown.value?.id;
    timer = setTimeout(() => toast.dismiss(id), ms);
}

watch(
    () => shown.value?.version,
    () => {
        if (!shown.value) {
            stop();
            width.value = null;
            return;
        }
        if (!hovering.value) start(shown.value.duration);
        else left = shown.value.duration;
    },
);

function enter(): void {
    hovering.value = true;
    if (timer) {
        left -= Date.now() - started;
        stop();
    }
}

function leave(): void {
    hovering.value = false;
    if (shown.value && Number.isFinite(shown.value.duration)) {
        start(Math.max(left, 1200));
    }
}

function act(): void {
    shown.value?.action?.onClick();
    toast.dismiss(shown.value?.id);
}

onMounted(observe);
onBeforeUnmount(() => {
    observer?.disconnect();
    stop();
});
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-[100] flex justify-center"
    >
        <Transition name="notch" @after-enter="observe">
            <div
                v-if="shown"
                class="notch pointer-events-auto relative font-ui"
                :role="shown.type === 'error' ? 'alert' : 'status'"
                :aria-live="shown.type === 'error' ? 'assertive' : 'polite'"
                @mouseenter="enter"
                @mouseleave="leave"
            >
                <div
                    class="overflow-hidden transition-[width] duration-[420ms] ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none"
                    :style="
                        width === null ? undefined : { width: `${width}px` }
                    "
                >
                    <div
                        ref="content"
                        class="flex w-max max-w-[min(560px,calc(100vw-48px))] items-center gap-2.5 px-5 py-2.5"
                    >
                        <Transition name="notch-swap">
                            <div
                                :key="shown.version"
                                class="flex min-w-0 items-center gap-2.5"
                            >
                                <component
                                    :is="icons[shown.type]"
                                    v-if="icons[shown.type]"
                                    class="size-4 shrink-0"
                                    :class="[
                                        `notch-${shown.type}`,
                                        {
                                            'animate-spin':
                                                shown.type === 'loading',
                                        },
                                    ]"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-col">
                                    <p
                                        class="text-[14px]/5 font-medium tracking-[-0.01em]"
                                    >
                                        {{ shown.title }}
                                    </p>
                                    <p
                                        v-if="shown.description"
                                        class="notch-muted text-[12.5px]/4.5"
                                    >
                                        {{ shown.description }}
                                    </p>
                                </div>
                                <button
                                    v-if="shown.action"
                                    type="button"
                                    class="notch-action ml-2 h-7 shrink-0 cursor-pointer rounded-full px-3 text-[13px]/5 font-medium"
                                    @click="act"
                                >
                                    {{ shown.action.label }}
                                </button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
/*
 * The notch: ink on the light dashboard, paper on the dark one, with
 * concave shoulders where it meets the top edge.
 */
.notch {
    --notch-bg: #161615;
    --notch-text: #f6f5f1;
    --notch-muted: rgb(246 245 241 / 0.6);
    --notch-ok: #4ade80;
    --notch-bad: #f87171;
    --notch-warn: #fbbf24;
    --notch-info: #93c5fd;
    --shoulder: 14px;

    border-radius: 0 0 20px 20px;
    background: var(--notch-bg);
    color: var(--notch-text);
    box-shadow:
        0 12px 32px -8px rgb(0 0 0 / 0.35),
        0 4px 10px -4px rgb(0 0 0 / 0.2);
}

:global(.dark .notch) {
    --notch-bg: #f6f5f1;
    --notch-text: #161615;
    --notch-muted: rgb(22 22 21 / 0.6);
    --notch-ok: #16a34a;
    --notch-bad: #dc2626;
    --notch-warn: #d97706;
    --notch-info: #2563eb;
}

/* The shoulders: a square of ink with a quarter circle cut from it. */
.notch::before,
.notch::after {
    content: '';
    position: absolute;
    top: 0;
    width: var(--shoulder);
    height: var(--shoulder);
    background: var(--notch-bg);
}

.notch::before {
    right: 100%;
    mask: radial-gradient(
        circle at 0 100%,
        transparent calc(var(--shoulder) - 0.5px),
        black var(--shoulder)
    );
}

.notch::after {
    left: 100%;
    mask: radial-gradient(
        circle at 100% 100%,
        transparent calc(var(--shoulder) - 0.5px),
        black var(--shoulder)
    );
}

.notch-muted {
    color: var(--notch-muted);
}

.notch-success {
    color: var(--notch-ok);
}

.notch-error {
    color: var(--notch-bad);
}

.notch-warning {
    color: var(--notch-warn);
}

.notch-info,
.notch-loading {
    color: var(--notch-info);
}

.notch-action {
    background: var(--notch-text);
    color: var(--notch-bg);
}

/* In: drops from behind the top edge. Out: back up behind it. */
.notch-enter-active {
    transition: translate 460ms cubic-bezier(0.32, 0.72, 0, 1);
}

.notch-leave-active {
    transition: translate 280ms cubic-bezier(0.4, 0, 1, 1);
}

.notch-enter-from,
.notch-leave-to {
    translate: 0 calc(-100% - 24px);
}

/* A new message in the same notch: the old blurs out, the new settles. */
.notch-swap-enter-active {
    transition:
        opacity 200ms ease-out,
        filter 200ms ease-out,
        translate 260ms cubic-bezier(0.32, 0.72, 0, 1);
}

.notch-swap-leave-active {
    display: none;
}

.notch-swap-enter-from {
    opacity: 0;
    filter: blur(4px);
    translate: 0 4px;
}

@media (prefers-reduced-motion: reduce) {
    .notch-enter-active,
    .notch-leave-active {
        transition: opacity 150ms ease;
    }

    .notch-enter-from,
    .notch-leave-to {
        translate: none;
        opacity: 0;
    }

    .notch-swap-enter-active {
        transition: opacity 120ms ease;
    }

    .notch-swap-enter-from {
        filter: none;
        translate: none;
    }
}
</style>
