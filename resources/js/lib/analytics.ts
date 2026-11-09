import type { PostHog } from 'posthog-js';
import { CONSENT_EVENT, hasConsent } from '@/lib/consent';
import type { Consent } from '@/lib/consent';

/**
 * Product analytics (PostHog, EU cloud) for serveros.com and sign-up: page
 * views, how far people scroll before they leave, heatmaps, session replay
 * with every input masked, and a few named events along the sign-up path.
 *
 * Only for people who said yes to Analytics in the cookie notice: PostHog
 * isn't even downloaded before that, and saying no later stops it and
 * forgets them. Without VITE_POSTHOG_KEY nothing runs at all.
 */
const KEY = import.meta.env.VITE_POSTHOG_KEY as string | undefined;

let client: PostHog | null = null;
let loading: Promise<PostHog | null> | null = null;
/** Events sent before PostHog finished loading, so none are lost. */
const queued: [string, Record<string, unknown>][] = [];

async function start(): Promise<PostHog | null> {
    if (!KEY) return null;
    if (client) {
        client.opt_in_capturing();
        return client;
    }
    loading ??= import('posthog-js').then(({ default: posthog }) => {
        posthog.init(KEY, {
            // PostHog's EU cloud, or VITE_POSTHOG_HOST: serveros.com's own
            // subdomain (PostHog's managed proxy), so ad blockers that drop
            // posthog.com don't drop it too.
            api_host:
                (import.meta.env.VITE_POSTHOG_HOST as string | undefined) ??
                'https://eu.i.posthog.com',
            ui_host:
                (import.meta.env.VITE_POSTHOG_UI_HOST as string | undefined) ??
                'https://eu.posthog.com',
            // Inertia moves between pages with pushState.
            capture_pageview: 'history_change',
            capture_pageleave: true,
            enable_heatmaps: true,
            // People get a profile once they sign up, not per visit.
            person_profiles: 'identified_only',
            session_recording: { maskAllInputs: true },
        });
        posthog.register({ app: source });
        client = posthog;
        for (const [event, properties] of queued.splice(0)) {
            posthog.capture(event, properties);
        }
        return posthog;
    });

    return loading;
}

function stop(): void {
    if (!client) return;
    client.opt_out_capturing();
    client.reset();
}

/** Which app sent an event: the marketing site, or the panel (sign-up). */
let source: 'site' | 'panel' = 'site';

/** Starts it if they've said yes, and follows their choice when it changes. */
export function initAnalytics(app: 'site' | 'panel'): void {
    source = app;
    if (!KEY || typeof window === 'undefined') return;
    if (hasConsent('analytics')) void start();
    window.addEventListener(CONSENT_EVENT, (event) => {
        if ((event as CustomEvent<Consent>).detail.analytics) void start();
        else stop();
    });
}

/** A named event, when they've said yes (dropped otherwise). */
export function track(
    event: string,
    properties: Record<string, unknown> = {},
): void {
    if (!KEY || !hasConsent('analytics')) return;
    if (client) client.capture(event, properties);
    else {
        queued.push([event, properties]);
        void start();
    }
}

/** Ties what they did before signing up to their account, by id only. */
export function identify(userId: number | string): void {
    if (!KEY || !hasConsent('analytics')) return;
    void start().then((posthog) => posthog?.identify(String(userId)));
}
