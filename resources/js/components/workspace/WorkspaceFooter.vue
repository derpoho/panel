<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { docsUrl } from '@/lib/docs';

/**
 * Under the page on every dashboard screen: who runs it (or, on an install
 * that names no company, the panel's code), then where to look when
 * something's off (status, docs, support) and the small print.
 */
const page = usePage<{
    extensions?: { nav: { key: string; label: string; path: string }[] } | null;
}>();

// Support, when a package adds it to the workspace menu (serveros.com's support desk).
const support = computed(
    () =>
        page.props.extensions?.nav.find((item) => item.key === 'support') ??
        null,
);

// The terms (Legal), on the main domain when they're a path there.
const terms = computed(() => {
    const terms = page.props.brand.terms;

    return terms === null || !terms.startsWith('/')
        ? terms
        : `${page.props.appUrl}${terms}`;
});

const links = computed(() =>
    [
        page.props.statusUrl
            ? { label: 'Status', href: page.props.statusUrl, external: true }
            : null,
        { label: 'Docs', href: docsUrl(page.props.docsUrl), external: true },
        support.value
            ? { label: 'Support', href: support.value.path, external: false }
            : null,
        terms.value
            ? { label: 'Legal', href: terms.value, external: true }
            : null,
    ].filter((link) => link !== null),
);
</script>

<template>
    <footer
        class="flex h-10 shrink-0 items-center justify-between gap-4 px-5 font-ui text-[13px]/5 text-(--ds-text-muted)"
    >
        <p class="flex min-w-0 items-center gap-2">
            <img
                src="/images/landing/serveros-logo.svg"
                alt=""
                class="size-3.5 shrink-0 object-contain opacity-60 invert dark:invert-0"
            />
            <span v-if="page.props.brand.company" class="truncate max-sm:hidden"
                >© {{ new Date().getFullYear() }}
                {{ page.props.brand.company }}</span
            >
            <!-- No company: the panel's own code, which it's built from. -->
            <a
                v-else-if="page.props.brand.source"
                :href="page.props.brand.source"
                target="_blank"
                rel="noopener"
                class="truncate rounded-sm transition-colors hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring) max-sm:hidden"
                >ServerOS</a
            >
        </p>
        <nav :aria-label="$t('Footer')">
            <ul class="flex items-center gap-5">
                <li v-for="link in links" :key="link.label">
                    <a
                        :href="link.href"
                        :target="link.external ? '_blank' : undefined"
                        :rel="link.external ? 'noopener' : undefined"
                        class="rounded-sm transition-colors hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        >{{ $t(link.label) }}</a
                    >
                </li>
            </ul>
        </nav>
    </footer>
</template>
