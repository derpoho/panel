/**
 * A page of the docs, from where they are (the shared docsUrl prop: the
 * docs site that comes with serveros.com's package, or serveros.com's docs
 * on an install without one).
 */
export function docsUrl(base: string, path = ''): string {
    return `${base.replace(/\/$/, '')}${path}`;
}
