import DOMPurify from 'dompurify';
import { marked } from 'marked';

/**
 * Only links to our own site (the site itself, docs. and status.) and
 * email addresses stay clickable. Anything else an answer links to, it
 * could have made up, so it's shown as plain text instead.
 */
function allowed(href: string): boolean {
    try {
        const url = new URL(href, window.location.href);
        if (url.protocol === 'mailto:') return true;

        const site = window.location.hostname.split('.').slice(-2).join('.');
        return (
            url.protocol === 'https:' &&
            [site, `docs.${site}`, `status.${site}`].includes(url.hostname)
        );
    } catch {
        return false;
    }
}

// DOMPurify needs a DOM: in the marketing site's server renderer (no window)
// it can't sanitise, so the hook isn't added and renderMarkdown gives ''.
// Answers are only ever drawn in the browser.
if (DOMPurify.isSupported)
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName !== 'A') return;

        if (!allowed(node.getAttribute('href') ?? '')) {
            // Without an href it's just text (styled as such in .assistant-prose).
            node.removeAttribute('href');
            return;
        }
        const href = node.getAttribute('href') ?? '';
        const url = new URL(href, window.location.href);
        // A bare URL reads as its address, without https:// or a query.
        if (node.textContent === href && url.protocol === 'https:') {
            node.textContent = (url.host + url.pathname).replace(/\/$/, '');
        }
        // Trial links from Ask ServerOS (the site itself, not the docs) say
        // where the signup came from, without the answer showing it.
        if (
            url.host === window.location.host &&
            url.pathname === '/register' &&
            !url.searchParams.has('utm_source') &&
            !window.location.hostname.startsWith('docs.')
        ) {
            url.searchParams.set('utm_source', 'ask-serveros');
            url.searchParams.set('utm_medium', 'chat');
            node.setAttribute('href', url.toString());
        }
        // Links elsewhere open in a new tab, so whatever they were reading
        // stays put; a page of the site they're on opens in place.
        if (url.host !== window.location.host) {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener');
        }
    });

/** Markdown (GitHub style) to HTML that's safe to put on the page. */
export function renderMarkdown(markdown: string): string {
    if (!DOMPurify.isSupported) return '';

    // Models write dashes whatever they're told: a spaced one becomes a
    // comma, one between words or numbers a hyphen.
    const plain = markdown
        .replace(/ *[\u2013\u2014] +/g, ', ')
        .replace(/[\u2013\u2014]/g, '-');

    return DOMPurify.sanitize(
        marked.parse(plain, { gfm: true, breaks: true, async: false }),
        {
            // Answers can repeat what the model read (logs, a repository):
            // an image or media tag there would be fetched as soon as it's
            // drawn, sending whatever its address holds to anyone. Answers
            // are words and links only.
            FORBID_TAGS: [
                'img',
                'picture',
                'source',
                'video',
                'audio',
                'track',
                'svg',
                'math',
                'iframe',
                'object',
                'embed',
                'form',
                'input',
                'button',
                'style',
            ],
            FORBID_ATTR: ['style', 'srcset', 'background', 'poster'],
        },
    );
}
