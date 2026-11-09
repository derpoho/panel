import { onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Animated height for multi-step dialogs. Put `.ds-step-frame` with
 * `:style="{ height }"` around a `ref="<refName>"` wrapper that holds a
 * `<Transition name="ds-step" mode="out-in" @before-leave="freeze"
 * @enter="unfreeze">`. The frame eases between each step's height and holds
 * still while the old step leaves, so it never collapses in between.
 */
export function useStepFrame(refName: string): {
    height: Ref<string | undefined>;
    freeze: () => void;
    unfreeze: () => void;
} {
    const inner = useTemplateRef<HTMLElement>(refName);
    const height = ref<string | undefined>(undefined);
    let frozen = false;
    let observer: ResizeObserver | undefined;

    watch(inner, (el) => {
        observer?.disconnect();
        height.value = undefined;

        if (el && 'ResizeObserver' in window) {
            observer = new ResizeObserver(([entry]) => {
                if (!frozen && entry) {
                    height.value = `${entry.contentRect.height}px`;
                }
            });
            observer.observe(el);
        }
    });

    onBeforeUnmount(() => observer?.disconnect());

    return {
        height,
        freeze: () => (frozen = true),
        unfreeze: () => (frozen = false),
    };
}
