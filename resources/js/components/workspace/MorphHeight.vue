<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

/**
 * Grows and shrinks to fit what's inside, so a dialog that swaps one view
 * for another changes size smoothly instead of jumping. Pair the swap with
 * a <Transition> inside; this only animates the height.
 */
const inner = useTemplateRef<HTMLElement>('inner');
const height = ref<number | null>(null);
const ready = ref(false);

let observer: ResizeObserver | undefined;

onMounted(() => {
    if (!inner.value || !('ResizeObserver' in window)) return;
    observer = new ResizeObserver(([entry]) => {
        height.value =
            entry.borderBoxSize?.[0]?.blockSize ??
            entry.target.getBoundingClientRect().height;
    });
    observer.observe(inner.value);
    requestAnimationFrame(() => (ready.value = true));
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <!-- Padded a little each way so focus rings aren't clipped. -->
    <div
        class="-m-1 overflow-hidden"
        :class="{
            'transition-[height] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none':
                ready,
        }"
        :style="height === null ? undefined : { height: `${height}px` }"
    >
        <div ref="inner" class="p-1">
            <slot />
        </div>
    </div>
</template>
