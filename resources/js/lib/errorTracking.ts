import type { App } from 'vue';

/**
 * Front-end errors in the dashboard and admin go to Sentry when
 * VITE_SENTRY_DSN is set; without it nothing loads. Errors only: no
 * session replay, no tracing, and no personal data (no IPs, cookies,
 * form values or query strings; emails and tokens masked), so it doesn't
 * wait on the cookie notice the way analytics does.
 */
const DSN = import.meta.env.VITE_SENTRY_DSN as string | undefined;

/** Masks emails, tokens and secret-shaped values, like Scrub.php on the server. */
const PATTERNS: [RegExp, string][] = [
    [/\bBearer\s+[\w.~+/=-]+/gi, 'Bearer [filtered]'],
    [
        /((?:pass(?:word)?|secret|token|api[_-]?key|auth|cookie|session|dsn|signature)["']?\s*[:=]\s*["']?)[^\s"'&,;)}]+/gi,
        '$1[filtered]',
    ],
    [
        /\b(?:sos[a-z]?_|sk_(?:live|test)_|whsec_|phc_|gh[pousr]_)[\w-]{8,}/g,
        '[filtered]',
    ],
    [/[\w.%+-]+@[\w.-]+\.[a-z]{2,}/gi, '[email]'],
    [/\b[a-f0-9]{40,}\b/gi, '[filtered]'],
];

export function scrub(text: string): string {
    return PATTERNS.reduce(
        (out, [pattern, mask]) => out.replace(pattern, mask),
        text,
    );
}

/** Errors that only say a request didn't come back. */
const quiet = new Set(['HttpNetworkError', 'HttpCancelledError']);

export function initErrorTracking(app: App): void {
    if (!DSN || typeof window === 'undefined') {
        return;
    }

    void import('@sentry/vue').then((Sentry) => {
        Sentry.init({
            app,
            dsn: DSN,
            environment: import.meta.env.MODE,
            release: import.meta.env.VITE_SENTRY_RELEASE as string | undefined,
            dataCollection: {
                userInfo: false,
                cookies: false,
                httpHeaders: false,
                httpBodies: [],
                urlQueryParams: false,
                stackFrameVariables: false,
            },
            tracesSampleRate: 0,
            // Seline's analytics script lets its page-view POST reject when an
            // ad blocker or a flaky network stops it ("Failed to fetch"). That's
            // theirs, not ours, and harmless to the visitor.
            denyUrls: [/cdn\.seline\.com/, /\/vendor\/seline\.js/],
            beforeSend(event) {
                // Inertia's requests that never got an answer: the visitor's
                // connection dropped, or the page was left (a reload, a link)
                // while a background refresh was still out. Nothing for us
                // to fix, and the next refresh or click retries.
                if (
                    event.exception?.values?.some((exception) =>
                        quiet.has(exception.type ?? ''),
                    )
                ) {
                    return null;
                }
                event.message &&= scrub(event.message);
                for (const exception of event.exception?.values ?? []) {
                    exception.value &&= scrub(exception.value);
                }
                if (event.request?.url) {
                    event.request.url = event.request.url.split('?')[0];
                }
                delete event.user;

                return event;
            },
            beforeBreadcrumb(crumb) {
                crumb.message &&= scrub(crumb.message);
                if (typeof crumb.data?.url === 'string') {
                    crumb.data.url = crumb.data.url.split('?')[0];
                }

                return crumb;
            },
        });
    });
}
