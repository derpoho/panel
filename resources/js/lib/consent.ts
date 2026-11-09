/**
 * Cookie consent, kept to what UK PECR and the EU ePrivacy Directive (read
 * with UK/EU GDPR) ask for:
 *
 * - Strictly necessary cookies (session, CSRF, the theme you picked) need no
 *   consent and are always on.
 * - Anything else stays off until someone opts in. Nothing is pre-ticked,
 *   and rejecting is as easy as accepting.
 * - The choice is recorded with when it was made and which version of the
 *   policy it was made against, and can be changed or withdrawn any time
 *   from "Cookie settings" in the footer.
 *
 * Any optional script added later (analytics and the like) must check
 * hasConsent() first, and listen for CONSENT_EVENT to start or stop when
 * the choice changes.
 */
export type Category = 'analytics' | 'marketing';

export type Consent = {
    /** Which version of the cookie policy this choice was made against. */
    version: number;
    /** When the choice was made, ISO 8601. */
    at: string;
    analytics: boolean;
    marketing: boolean;
};

/** Bump when the categories or what they cover change, so everyone is asked again. */
// 2: analytics covers PostHog (11 October 2026).
export const CONSENT_VERSION = 2;

/** Fired on window with the new Consent whenever it is saved. */
export const CONSENT_EVENT = 'serveros:consent';

/** Fired on window to open the settings again (the footer link does this). */
export const OPEN_SETTINGS_EVENT = 'serveros:cookie-settings';

const COOKIE = 'cookie_consent';

/** Six months, after which people are asked again. */
const MAX_AGE = 60 * 60 * 24 * 182;

/**
 * The site, the docs and sign-up live on serveros.com and its subdomains, so
 * the choice is shared across them: one answer, asked once.
 */
function shared(): string {
    const host = window.location.hostname;
    const parts = host.split('.');
    if (parts.length < 2 || /^[\d.]+$/.test(host)) return '';

    return `;domain=.${parts.slice(-2).join('.')}`;
}

export function readConsent(): Consent | null {
    try {
        const raw = document.cookie
            .split('; ')
            .find((part) => part.startsWith(`${COOKIE}=`))
            ?.slice(COOKIE.length + 1);
        if (!raw) return null;
        const consent = JSON.parse(decodeURIComponent(raw)) as Consent;
        return consent.version === CONSENT_VERSION ? consent : null;
    } catch {
        return null;
    }
}

export function saveConsent(choice: Record<Category, boolean>): Consent {
    const consent: Consent = {
        version: CONSENT_VERSION,
        at: new Date().toISOString(),
        ...choice,
    };
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${COOKIE}=${encodeURIComponent(JSON.stringify(consent))};path=/;max-age=${MAX_AGE};SameSite=Lax${shared()}${secure}`;
    window.dispatchEvent(new CustomEvent(CONSENT_EVENT, { detail: consent }));
    return consent;
}

/** Whether optional cookies in this category may be used right now. */
export function hasConsent(category: Category): boolean {
    return readConsent()?.[category] === true;
}

export function openCookieSettings(): void {
    window.dispatchEvent(new Event(OPEN_SETTINGS_EVENT));
}
