import { router } from '@inertiajs/vue3';

/**
 * Switch language: saved on the account (and in a cookie for the marketing
 * site), then a full reload so everything, server messages included,
 * comes back in the new language at once.
 */
export function changeLocale(locale: string): void {
    router.post(
        '/locale',
        { locale },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => window.location.reload(),
        },
    );
}
