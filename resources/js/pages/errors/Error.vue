<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, RotateCw } from '@lucide/vue';
import { computed } from 'vue';
import { bloom, grain } from '@/lib/brand';
import { appButtonClasses } from '@/lib/appButton';

/**
 * Not found, not allowed, expired, broken or down: one page for all of
 * them, for workspace addresses and installs without a website (on
 * serveros.com the site, docs and feedback use its errors/Error.tsx), in
 * the same look: the landing's cobalt gradient and grain, a pill, a big
 * line and the way back. The way back goes wherever makes sense for the
 * address (see bootstrap/app.php).
 */
const props = defineProps<{
    status: number;
    home: { href: string; label: string };
    site: string;
    /** Where to write when something broke (config mail.support.address). */
    support: string;
}>();

const copy: Record<number, { title: string; body: string }> = {
    403: {
        title: trans('You can’t open this'),
        body: trans(
            'Your role doesn’t include this page. Ask an owner or admin of the workspace if you need it.',
        ),
    },
    404: {
        title: trans('This page doesn’t exist'),
        body: trans(
            'It may have moved, or the link has a typo. Check the address, or head back and find it from there.',
        ),
    },
    419: {
        title: trans('This page expired'),
        body: trans(
            'It was open for a while, so it needs a refresh before you carry on.',
        ),
    },
    429: {
        title: trans('Slow down a little'),
        body: trans(
            'That was a lot of requests in a short time. Wait a minute, then try again.',
        ),
    },
    500: {
        title: trans('Something broke on our side'),
        body: trans(
            'It’s not you. We’ve been told about it. Try again in a minute, and if it keeps happening, email :address.',
            { address: props.support },
        ),
    },
    503: {
        title: trans('Back in a moment'),
        body: trans(
            'We’re updating ServerOS right now. Your machines and apps keep running. Try again in a minute.',
        ),
    },
};

const message = computed(() => copy[props.status] ?? copy[500]);
const retry = computed(() => [419, 429, 500, 503].includes(props.status));

/** The main way out, white on the gradient so it reads at any height. */
const onBloom =
    'h-9 border-white bg-white px-4 text-[14px]/5 text-[#16161a] hover:border-[#f1f0ff] hover:bg-[#f1f0ff] focus-visible:outline-white';

function reload(): void {
    window.location.reload();
}

function back(): void {
    if (window.history.length > 1) window.history.back();
    else window.location.href = props.home.href;
}
</script>

<template>
    <Head :title="message.title" />

    <div
        class="ds-root relative isolate flex min-h-svh flex-col overflow-hidden bg-[#f7f7f8] font-ui text-white antialiased [color-scheme:light]"
    >
        <!-- The landing page's gradient and grain -->
        <div
            aria-hidden="true"
            class="absolute inset-0 -z-10"
            :style="{ backgroundImage: bloom }"
        >
            <div
                class="absolute inset-0 opacity-[0.05]"
                :style="{ backgroundImage: grain }"
            />
        </div>

        <header
            class="mx-auto flex h-16 w-full max-w-[1300px] items-center px-5 sm:px-8"
        >
            <a
                :href="site"
                class="flex items-center gap-2.5 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-[22px] object-contain"
                />
                <span class="text-[17px] font-semibold tracking-[-0.025em]">
                    ServerOS
                </span>
            </a>
        </header>

        <main
            class="mx-auto flex w-full max-w-[1300px] grow flex-col items-center px-5 pt-[14vh] pb-40 text-center sm:px-8 sm:pt-[18vh]"
        >
            <p
                class="rounded-full bg-[linear-gradient(180deg,rgb(255_255_255/0.2),rgb(255_255_255/0.08))] px-3.5 py-1.5 font-ui-mono text-[12px]/[18px] font-medium text-white/85 tabular-nums shadow-[inset_0_0_4px_rgb(255_255_255/0.2),inset_0_1px_1px_rgb(255_255_255/0.1)]"
            >
                {{ $t('Error :status', { status: String(status) }) }}
            </p>
            <h1
                class="mt-8 max-w-[640px] text-[38px]/[44px] font-medium tracking-[-0.035em] text-balance text-white sm:text-[52px]/[56px]"
            >
                {{ message.title }}
            </h1>
            <p
                class="mt-4 max-w-[480px] text-[17px]/[26px] tracking-[-0.01em] text-pretty text-[#bcd0fb]"
            >
                {{ message.body }}
            </p>
            <div
                class="mt-9 flex flex-wrap items-center justify-center gap-2.5"
            >
                <button
                    v-if="retry"
                    type="button"
                    :class="appButtonClasses('primary', 'md', onBloom)"
                    @click="reload"
                >
                    <RotateCw class="size-3.5" :stroke-width="2" />
                    {{ status === 419 ? $t('Refresh') : $t('Try again') }}
                </button>
                <a
                    v-else
                    :href="home.href"
                    :class="appButtonClasses('primary', 'md', onBloom)"
                >
                    {{ $t(home.label) }}
                </a>
                <button
                    type="button"
                    :class="
                        appButtonClasses(
                            'secondary',
                            'md',
                            'group h-9 border-white/15 bg-white/[0.06] px-4 text-[14px]/5 text-white hover:border-white/25 hover:bg-white/[0.12] focus-visible:outline-white',
                        )
                    "
                    @click="back"
                >
                    <ArrowLeft
                        class="size-3.5 opacity-70 transition-transform group-hover:-translate-x-0.5"
                        :stroke-width="2"
                    />
                    {{ $t('Go back') }}
                </button>
            </div>
        </main>

        <footer
            v-if="$page.props.brand?.company"
            class="py-6 text-center text-[12px]/4 text-[#777880]"
        >
            {{ `© ${new Date().getFullYear()} ${$page.props.brand?.company}.` }}
        </footer>
    </div>
</template>
