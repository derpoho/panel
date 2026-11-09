<script setup lang="ts">
/**
 * The landing page's aurora (serveros.com's HeroBackdrop.tsx), for the
 * Vue auth pages: soft beams drifting up from the bottom right over a faint
 * glow and grain, in light or dark. The beams slide with a transform only,
 * so it stays cheap to run.
 */
const beams = {
    backgroundImage: [
        'repeating-linear-gradient(100deg, var(--beam-band) 0%, var(--beam-band) 3%, color-mix(in srgb, var(--beam-band) 70%, transparent) 5%, color-mix(in srgb, var(--beam-band) 70%, transparent) 7%, transparent 10%, transparent 12%, color-mix(in srgb, var(--beam-band) 70%, transparent) 14%, var(--beam-band) 16%)',
        'repeating-linear-gradient(100deg, var(--beam-lit) 0%, var(--beam-lit) 1.5%, color-mix(in srgb, var(--beam-lit) 80%, transparent) 2%, var(--beam-dim) 3%, var(--beam-dim) 4%, color-mix(in srgb, var(--beam-lit) 80%, transparent) 4.5%, var(--beam-lit) 5%)',
    ].join(', '),
    backgroundSize: '42.857% 100%, 28.571% 100%',
    backgroundPosition: '0 0, 0 0',
    animation: 'so-beams 60s linear infinite',
};
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0 -z-10 overflow-hidden [contain:strict] [--beam-band:#F7F7F6] [--beam-dim:#8FA3C7] [--beam-glow:rgb(196_214_247/0.9)] [--beam-lit:#B9C8E4] dark:[--beam-band:#0C0C0F] dark:[--beam-dim:#33405C] dark:[--beam-glow:rgb(30_52_100/0.9)] dark:[--beam-lit:#5D6F96]"
        aria-hidden="true"
    >
        <div
            class="absolute inset-0 bg-[radial-gradient(56%_50%_at_50%_58%,var(--beam-glow)_0%,transparent_100%)] opacity-70"
        />
        <div
            class="absolute inset-0 mask-[radial-gradient(ellipse_at_100%_100%,black_20%,transparent_80%)] opacity-90"
        >
            <div
                class="so-beams absolute -top-5 -bottom-5 left-0 w-[700%] blur-[30px] will-change-transform"
                :style="beams"
            />
        </div>
        <div
            class="absolute inset-0 bg-size-[180px_180px] bg-repeat opacity-[0.07] mix-blend-overlay dark:opacity-[0.12]"
            :style="{ backgroundImage: 'url(/images/landing/grain.webp)' }"
        />
    </div>
</template>

<style>
@keyframes so-beams {
    from {
        transform: translate3d(0, 0, 0);
    }
    to {
        transform: translate3d(-85.714%, 0, 0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .so-beams {
        animation: none !important;
    }
}
</style>
