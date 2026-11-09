/**
 * What the welcome flow's card step (pages/welcome/Welcome.vue) says while
 * it confirms a card trial's card, back from checkout. The package that
 * binds TrialCards (App\Support\Extensions\TrialCards) puts its own words
 * in resources/js/trialCards.ts, naming its checkout; without one, these.
 * The strings are in English and go through $t like the page's own.
 */
export type TrialCardsCopy = {
    /** While the card is checked. */
    checking: string;
    /** When checkout hasn't confirmed it yet. */
    pending: string;
};

const fromPackages = import.meta.glob<{ default: TrialCardsCopy }>(
    '../../../vendor/serveroshq/*/resources/js/trialCards.ts',
    { eager: true },
);

export const trialCardsCopy: TrialCardsCopy = Object.values(fromPackages)[0]
    ?.default ?? {
    checking: 'Checking your card…',
    pending: 'Your card is still being confirmed. Refresh in a moment.',
};
