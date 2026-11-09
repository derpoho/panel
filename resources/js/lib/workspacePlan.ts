import { trans } from 'laravel-vue-i18n';
import type { WorkspaceBilling } from '@/types';

export type PlanBadgeTone = 'neutral' | 'trial' | 'none';

/** The short plan label shown in badges, e.g. PRO, TRIAL or NO PLAN. */
export function planBadge(billing: WorkspaceBilling): {
    label: string;
    tone: PlanBadgeTone;
} {
    if (billing.selfHosted) {
        return { label: trans('SELF-HOSTED'), tone: 'neutral' };
    }

    if (billing.onTrial) {
        return { label: trans('TRIAL'), tone: 'trial' };
    }

    if (billing.plan === null || billing.planLabel === null) {
        return { label: trans('NO PLAN'), tone: 'none' };
    }

    return { label: billing.planLabel.toUpperCase(), tone: 'neutral' };
}

/** Badge colours: neutral for paid plans, blue on trial, amber with none. */
export const planBadgeTones: Record<PlanBadgeTone, string> = {
    neutral:
        'border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text-secondary)',
    trial: 'border-(--ds-blue-border) bg-(--ds-blue-bg)/70 text-(--ds-blue-strong)',
    none: 'border-(--ds-amber-border) bg-(--ds-amber-bg)/60 text-(--ds-amber)',
};

/**
 * A quiet one-line plan summary for the sidebar switcher, e.g. "Business",
 * "Pro trial · 12 days left" (or whichever plan the trial runs on) or "No plan".
 */
export function planSummary(billing: WorkspaceBilling): {
    label: string;
    tone: PlanBadgeTone;
} {
    if (billing.selfHosted) {
        return { label: trans('Self-hosted'), tone: 'neutral' };
    }

    if (billing.onTrial) {
        const days = billing.trialDaysLeft ?? 0;

        return {
            label: trans(
                days === 1
                    ? ':plan trial · :count day left'
                    : ':plan trial · :count days left',
                { count: String(days), plan: billing.planLabel ?? 'Pro' },
            ),
            tone: 'trial',
        };
    }

    if (billing.plan === null || billing.planLabel === null) {
        return { label: trans('No plan'), tone: 'none' };
    }

    return { label: billing.planLabel, tone: 'neutral' };
}
