<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { bloom, grain } from '@/lib/brand';
import { home } from '@/routes';

/**
 * Sign in, sign up and the other account screens: the landing hero's
 * cobalt bloom fading into the page, and one plain card with our mark and
 * a centred title at the top, the form, and a grey strip along the bottom
 * for the way to the other screen (the pages give that line the auth-foot
 * class). The mark links back to the site. Light or dark, like the site.
 */
defineProps<{
    title?: string;
    description?: string;
}>();

// Who runs this install and where its terms are, if it says (App\Support\Brand).
const brand = usePage().props.brand;
</script>

<template>
    <div
        class="auth-page marketing-page ds-root relative isolate flex min-h-svh flex-col overflow-hidden bg-(--ds-frame) font-ui text-(--ds-text) antialiased"
    >
        <!-- The landing hero's cobalt bloom, fading into the page -->
        <div
            aria-hidden="true"
            class="auth-bloom pointer-events-none absolute inset-x-0 top-0 -z-10 h-[78svh] min-h-[560px] mask-[linear-gradient(black_0%,black_55%,rgb(0_0_0/0.55)_78%,transparent)]"
            :style="{ '--bloom': bloom }"
        >
            <div
                class="absolute inset-0 opacity-[0.05]"
                :style="{ backgroundImage: grain }"
            />
        </div>

        <main class="flex grow items-center justify-center px-4 py-12">
            <div class="cs-rise w-full max-w-[400px]">
                <!-- Our mark and name above the card, white on the blue -->
                <Link
                    :href="home()"
                    :aria-label="$t('Back to ServerOS')"
                    class="group mx-auto mb-7 flex w-fit items-center gap-2.5 rounded-md text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
                >
                    <img
                        src="/images/landing/serveros-logo.svg"
                        alt=""
                        class="size-[22px] object-contain transition-transform duration-500 group-hover:rotate-60"
                    />
                    <span
                        class="text-[19px]/6 font-semibold tracking-[-0.025em]"
                        >ServerOS</span
                    >
                </Link>
                <div
                    class="ds-modal workspace-panel auth-form auth-compact overflow-hidden rounded-[12px]! px-8 pt-8 pb-7 shadow-[0_0_0_1px_rgb(0_0_0/0.06),0_2px_4px_rgb(0_0_0/0.04),0_24px_48px_-16px_rgb(2_16_56/0.35)]! dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_0_0_1px_rgb(255_255_255/0.08),0_24px_64px_-12px_rgb(0_0_0/0.6)]!"
                >
                    <div
                        v-if="title || description"
                        class="mb-7 flex flex-col items-center text-center"
                    >
                        <h1
                            v-if="title"
                            class="text-[18px]/6 font-semibold tracking-[-0.01em]"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="mt-1.5 text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
                <!-- The install's terms and privacy policy, where it has them -->
                <p
                    v-if="brand.terms && brand.privacy"
                    class="mt-5 text-center text-[12.5px]/5 text-balance text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('By continuing you agree to our') }}
                    <a
                        :href="brand.terms"
                        class="text-(--ds-text-secondary) underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >{{ $t('Terms') }}</a
                    >
                    {{ $t('and') }}
                    <a
                        :href="brand.privacy"
                        class="text-(--ds-text-secondary) underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >{{ $t('Privacy policy') }}</a
                    >.
                </p>
                <p
                    v-else-if="brand.terms || brand.privacy"
                    class="mt-5 text-center text-[12.5px]/5 text-balance text-(--ds-text-muted)"
                >
                    {{ ' ' + $t('By continuing you agree to our') }}
                    <a
                        :href="brand.terms ?? brand.privacy ?? undefined"
                        class="text-(--ds-text-secondary) underline decoration-(--ds-border-hover) underline-offset-2 hover:text-(--ds-text)"
                        >{{
                            brand.terms ? $t('Terms') : $t('Privacy policy')
                        }}</a
                    >.
                </p>
            </div>
        </main>

        <footer
            v-if="brand.company"
            class="py-6 text-center text-[12px]/4 text-(--ds-text-faint)"
        >
            {{ `© ${new Date().getFullYear()} ${brand.company}.` }}
        </footer>
    </div>
</template>
