import type { RouteDefinition } from '@/wayfinder';

type Method = 'get' | 'post' | 'put' | 'delete' | 'patch' | 'head' | 'options';

/**
 * Wayfinder renders subdomain routes as protocol-relative URLs
 * (//acme.serveros.com/machines). During SSR Inertia resolves those against
 * http://localhost but against https:// in the browser, so links hydrate with
 * different hrefs. Workspace pages always run on their own subdomain, so
 * linking by path is equivalent and renders the same on both sides.
 */
export function onWorkspace<TMethod extends Method>(
    route: RouteDefinition<TMethod>,
): RouteDefinition<TMethod> {
    const url = new URL(route.url, 'http://localhost');

    return { ...route, url: `${url.pathname}${url.search}${url.hash}` };
}
