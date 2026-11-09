<script setup lang="ts">
import { accentPair, createShader, playSweep } from 'glimm';
import type { ShaderController } from 'glimm';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The moment a fix is approved: a soft ring of light spreads from the
 * button, glimm's shader band sweeps down the panel, and the panel behind
 * them sinks a little and goes out of focus for a beat.
 *
 * Kept smooth by only ever animating transform and opacity on the GPU: the
 * ring is drawn once at full size and scaled down, the blur is a fixed
 * backdrop-filter layer whose opacity fades, and everything runs on one
 * Web Animations timeline. With reduced motion it does nothing.
 */
const props = defineProps<{ target?: HTMLElement | null }>();

const canvas = ref<HTMLCanvasElement | null>(null);
const ring = ref<HTMLElement | null>(null);
const haze = ref<HTMLElement | null>(null);
let shader: ShaderController | null = null;
let reduced = false;

const DURATION = 1600;
const EASE_OUT = 'cubic-bezier(0.22, 1, 0.36, 1)';
const palette = accentPair('#00E5D6', '#C2FF3D');

onMounted(() => {
    reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!canvas.value || reduced) return;
    shader = createShader({
        canvas: canvas.value,
        palette,
        direction: 'ttb',
        bandTight: 11,
        swellAmount: 0.5,
        waveAmount: 0.6,
        rippleAmount: 0.4,
    });
});

onBeforeUnmount(() => shader?.destroy());

/**
 * Play it, the ring starting at (x, y) inside the panel. `peak` resolves
 * when the wave is at its fullest (send the approval then), `done` when
 * everything has settled.
 */
function play(
    x: number,
    y: number,
): { peak: Promise<void>; done: Promise<void> } {
    if (reduced) {
        return { peak: Promise.resolve(), done: Promise.resolve() };
    }

    const animations: Animation[] = [];
    if (ring.value) {
        ring.value.style.left = `${x}px`;
        ring.value.style.top = `${y}px`;
        animations.push(
            ring.value.animate(
                [
                    {
                        transform: 'translate(-50%, -50%) scale(0.02)',
                        opacity: 0,
                    },
                    {
                        transform: 'translate(-50%, -50%) scale(0.12)',
                        opacity: 1,
                        offset: 0.08,
                    },
                    { transform: 'translate(-50%, -50%) scale(1)', opacity: 0 },
                ],
                { duration: DURATION, easing: EASE_OUT, fill: 'both' },
            ),
        );
    }
    if (haze.value) {
        animations.push(
            haze.value.animate(
                [{ opacity: 0 }, { opacity: 1, offset: 0.28 }, { opacity: 0 }],
                { duration: DURATION, easing: 'ease-in-out', fill: 'both' },
            ),
        );
    }
    if (props.target) {
        animations.push(
            props.target.animate(
                [
                    { transform: 'scale(1)' },
                    { transform: 'scale(0.988)', offset: 0.3 },
                    { transform: 'scale(1)' },
                ],
                { duration: DURATION, easing: 'cubic-bezier(0.45, 0, 0.2, 1)' },
            ),
        );
    }

    const sweep = shader
        ? playSweep(shader, {
              sweepMs: 1500,
              outroMs: 500,
              midpoint: 0.4,
              palette,
              direction: 'ttb',
              easing: 'easeInOutCubic',
              brightness: 0.6,
              peakAlpha: 0.5,
              bandTight: 11,
              waveAmount: 0.6,
          })
        : null;

    const settled = Promise.all([
        ...animations.map((a) => a.finished.catch(() => undefined)),
        sweep?.done ?? Promise.resolve(),
    ]).then(() => {
        // Clear the held end state so nothing lingers on the layers.
        for (const a of animations) a.cancel();
    });

    return {
        peak:
            sweep?.midpoint ??
            new Promise((resolve) => setTimeout(resolve, DURATION * 0.4)),
        done: settled,
    };
}

defineExpose({ play });
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0 z-20 overflow-hidden"
        aria-hidden="true"
    >
        <div ref="haze" class="fix-haze" />
        <canvas ref="canvas" class="relative size-full" />
        <span ref="ring" class="fix-ring" />
    </div>
</template>

<style scoped>
/* A fixed blur that only fades: cheap to composite, unlike animating blur(). */
.fix-haze {
    position: absolute;
    inset: 0;
    opacity: 0;
    -webkit-backdrop-filter: blur(5px) saturate(1.1);
    backdrop-filter: blur(5px) saturate(1.1);
    will-change: opacity;
}

/* Drawn once at its biggest and scaled down, so it never blurs or repaints. */
.fix-ring {
    position: absolute;
    left: 0;
    top: 0;
    width: 160vmax;
    height: 160vmax;
    border-radius: 9999px;
    opacity: 0;
    background: radial-gradient(
        closest-side,
        transparent 86%,
        rgb(194 255 61 / 0.22) 92%,
        rgb(0 229 214 / 0.5) 96.5%,
        rgb(0 229 214 / 0.12) 98.5%,
        transparent 100%
    );
    transform: translate(-50%, -50%) scale(0.02);
    will-change: transform, opacity;
}
</style>
