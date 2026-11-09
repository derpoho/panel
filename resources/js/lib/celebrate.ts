import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import confetti from 'canvas-confetti';
import { toast } from '@/lib/toast';

/**
 * Confetti: two bursts from the bottom corners in the panel's blues and
 * greens, once when someone first lands in their workspace after signing
 * up, and when they upgrade. Skipped for anyone who prefers reduced motion.
 */
export function burst(): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const colors = ['#3B7BFF', '#8DB4FF', '#1C4BB0', '#22C55E', '#F5F5F5'];
    const shared = {
        particleCount: 90,
        spread: 70,
        startVelocity: 55,
        ticks: 220,
        gravity: 1.05,
        scalar: 0.9,
        colors,
        disableForReducedMotion: true,
    };

    void confetti({ ...shared, angle: 60, origin: { x: 0, y: 0.9 } });
    void confetti({ ...shared, angle: 120, origin: { x: 1, y: 0.9 } });
    window.setTimeout(() => {
        void confetti({
            ...shared,
            particleCount: 60,
            spread: 110,
            startVelocity: 35,
            origin: { x: 0.5, y: 0.6 },
        });
    }, 280);
}

export function initializeCelebrate(): void {
    // Fires for the first page load too. Wait a beat so the page (and its
    // toaster) has mounted before the burst.
    router.on('navigate', (event) => {
        if (event.detail.page.props.celebrate !== true) return;
        window.setTimeout(() => {
            burst();
            const plan = (
                event.detail.page.props.workspace as
                    | { billing?: { planLabel?: string | null } }
                    | undefined
            )?.billing?.planLabel;
            toast.success(
                trans('Your :plan trial has started. Welcome to ServerOS.', {
                    plan: plan ?? 'Pro',
                }),
            );
        }, 450);
    });
}
