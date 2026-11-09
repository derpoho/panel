import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { Workspace } from '@/types';

export type SubscriptionNotice = {
    text: string;
    action: string;
    /** Billing with the trial's plan's checkout open, on the last day. */
    choose: string | null;
    /** Calm (a reminder) or urgent (it's about to stop, or has). */
    tone: 'calm' | 'urgent';
    /** Can be hidden until tomorrow. */
    dismissible: boolean;
    /** The running promo code, for someone yet to pay for a plan. */
    promo: { code: string; offer: string } | null;
    /** Where its button goes, when it isn't Billing. */
    href?: string;
    /** Hides it, when that isn't "until tomorrow". */
    dismiss?: () => void;
};

/** Trials stay quiet until this many days are left. */
const QUIET_UNTIL_DAYS = 7;

/** From this many days left, the reminder is urgent and can't be hidden. */
const URGENT_FROM_DAYS = 3;

/* Hidden until tomorrow, per workspace, in this browser */

const dismissedOn = ref<Record<number, string>>({});

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function storageKey(workspaceId: number): string {
    return `subscription-notice-dismissed:${workspaceId}`;
}

function readDismissal(workspaceId: number): string | null {
    if (workspaceId in dismissedOn.value) return dismissedOn.value[workspaceId];
    try {
        return localStorage.getItem(storageKey(workspaceId));
    } catch {
        return null;
    }
}

/* The Cloud credit, once hidden, stays hidden in this browser. */

const creditHidden = ref<Record<number, boolean>>({});

function creditKey(workspaceId: number): string {
    return `cloud-credit-hidden:${workspaceId}`;
}

function isCreditHidden(workspaceId: number): boolean {
    if (workspaceId in creditHidden.value)
        return creditHidden.value[workspaceId];
    try {
        return localStorage.getItem(creditKey(workspaceId)) !== null;
    } catch {
        return false;
    }
}

function hideCredit(workspaceId: number): void {
    creditHidden.value = { ...creditHidden.value, [workspaceId]: true };
    try {
        localStorage.setItem(creditKey(workspaceId), today());
    } catch {
        // Private mode: hidden for this visit only.
    }
}

export function dismissSubscriptionNotice(workspaceId: number): void {
    dismissedOn.value = { ...dismissedOn.value, [workspaceId]: today() };
    try {
        localStorage.setItem(storageKey(workspaceId), today());
    } catch {
        // Private mode: hidden for this visit only.
    }
}

/**
 * The strip above the dashboard about the workspace's plan. A failed
 * payment, or no plan at all, is urgent. A trial stays quiet for its first
 * week (the switcher and Billing show it), then reminds calmly and can be
 * hidden for a day, then for its last days says what stops and can't be.
 * Not shown on the Billing page itself.
 */
export function useSubscriptionNotice(): ComputedRef<SubscriptionNotice | null> {
    const page = usePage<{ workspace: Workspace }>();
    const promo = computed(() => page.props.promo ?? null);

    return computed(() => {
        if (page.component === 'workspace/Billing') {
            return null;
        }

        const workspace = page.props.workspace;
        const billing = workspace.billing;

        // A self-hosted install has nothing to pay for.
        if (billing.selfHosted) {
            return null;
        }

        if (billing.graceUntil) {
            const until = new Date(billing.graceUntil).toLocaleDateString(
                undefined,
                { day: 'numeric', month: 'long' },
            );

            return {
                text: trans(
                    "Your last payment didn't go through. Update your card by :until to keep monitors, alerts and workflows running.",
                    { until: String(until) },
                ),
                action: trans('Update payment'),
                choose: null,
                tone: 'urgent',
                dismissible: false,
                promo: null,
            };
        }

        if (billing.onTrial) {
            const days = billing.trialDaysLeft ?? 0;
            const plan = billing.planLabel ?? 'Pro';

            if (days > QUIET_UNTIL_DAYS) return null;

            if (days > URGENT_FROM_DAYS) {
                if (readDismissal(workspace.id) === today()) return null;

                return {
                    text: trans('Your :plan trial ends in :count days.', {
                        count: String(days),
                        plan,
                    }),
                    action: trans('Choose a plan'),
                    choose: null,
                    tone: 'calm',
                    dismissible: true,
                    promo: null,
                };
            }

            return {
                text: trans(
                    days === 1
                        ? ':plan ends tomorrow. Choose a plan to keep your monitors and alerts running.'
                        : ':plan ends in :count days. Choose a plan to keep your monitors and alerts running.',
                    { count: String(days), plan },
                ),
                action:
                    days === 1
                        ? trans('Keep :plan', { plan })
                        : trans('Choose a plan'),
                choose: days === 1 ? billing.plan : null,
                tone: 'urgent',
                dismissible: false,
                promo: promo.value,
            };
        }

        if (billing.plan === null) {
            return {
                text: trans("You don't have an active subscription."),
                action: trans('Subscribe'),
                choose: null,
                tone: 'urgent',
                dismissible: false,
                promo: promo.value,
            };
        }

        // A paying workspace that hasn't ordered from Cloud yet: its credit,
        // applied on its own in the New server dialog.
        const credit = page.props.cloudCredit;
        if (
            credit &&
            page.component !== 'workspace/Cloud' &&
            !isCreditHidden(workspace.id)
        ) {
            return {
                text: trans(
                    'Your plan comes with :amount off your first Cloud server.',
                    { amount: credit },
                ),
                action: trans('Get :amount off', { amount: credit }),
                choose: null,
                // The same strip as the plan's offer.
                tone: 'urgent',
                dismissible: true,
                promo: null,
                href: '/cloud?new',
                dismiss: () => hideCredit(workspace.id),
            };
        }

        return null;
    });
}
