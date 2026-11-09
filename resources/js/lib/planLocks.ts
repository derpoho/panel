import { trans } from 'laravel-vue-i18n';
/**
 * What each plan includes, side by side, for the upgrade dialog shown on
 * features a workspace's plan doesn't include. Matches app/Enums/Plan.php.
 */
export type LockedPlan = 'pro' | 'business';
export type PlanKey = 'starter' | LockedPlan;

export const planNames: Record<PlanKey, string> = {
    starter: trans('Essential'),
    pro: trans('Pro'),
    business: trans('Business'),
};

export const planPrices: Record<PlanKey, string> = {
    starter: '£5',
    pro: '£9',
    business: '£19',
};

/** Rows of the comparison: what differs between the plans. */
export const planRows: { label: string; values: Record<PlanKey, string> }[] = [
    {
        label: trans('Machines'),
        values: {
            starter: '1',
            pro: trans('Up to 5'),
            business: trans('Unlimited'),
        },
    },
    {
        label: trans('Workflows'),
        values: {
            starter: trans('No'),
            pro: trans('Yes'),
            business: trans('Yes'),
        },
    },
    {
        label: trans('Status pages'),
        values: {
            starter: trans('No'),
            pro: trans('One per machine'),
            business: trans('Per machine, and one for all'),
        },
    },
    {
        label: trans('Logs kept'),
        values: { starter: '3 days', pro: '14 days', business: '30 days' },
    },
    {
        label: trans('Uptime monitors'),
        values: { starter: '10', pro: '50', business: '250' },
    },
    {
        label: trans('Roles'),
        values: {
            starter: trans('Owner, Admin'),
            pro: trans('Staff roles'),
            business: trans('Custom roles'),
        },
    },
    {
        label: trans('Single sign-on'),
        values: {
            starter: trans('No'),
            pro: trans('No'),
            business: trans('SAML and OIDC'),
        },
    },
];
