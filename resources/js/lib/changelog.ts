/**
 * The changelog's categories, and the colour each one's pill gets, in the
 * panel, /admin and on the site.
 */
export type ChangelogCategory = 'feature' | 'improvement' | 'fix' | 'security';

export type ChangelogCard = {
    id: number;
    title: string;
    slug: string;
    category: ChangelogCategory;
    categoryLabel: string;
    summary: string;
    imageUrl: string | null;
    linkUrl: string | null;
    linkLabel: string | null;
    publishedAt: string | null;
    url: string;
};

export const changelogTone: Record<ChangelogCategory, string> = {
    feature: 'bg-(--ds-blue)/12 text-(--ds-blue)',
    improvement: 'bg-(--ds-green)/12 text-(--ds-green)',
    fix: 'bg-(--ds-amber)/14 text-(--ds-amber)',
    security: 'bg-(--ds-red)/12 text-(--ds-red)',
};

/** "6 October 2026". */
export function changelogDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
              // One zone for everyone, so the server's HTML and the browser agree.
              timeZone: 'Europe/London',
          })
        : '';
}
