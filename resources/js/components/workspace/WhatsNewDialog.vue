<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useWhatsNew } from '@/composables/useWhatsNew';
import { changelogDate, changelogTone } from '@/lib/changelog';

/**
 * The latest changelog entries, from the sidebar's What's new: what each
 * is, a link to try it in this workspace, and its page on the site.
 */
const page = usePage<{ appUrl: string }>();
const { open, fresh, latest } = useWhatsNew();

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-lg"
        >
            <div
                class="flex shrink-0 items-start justify-between gap-4 border-b border-(--ds-border) px-5 pt-5 pb-4"
            >
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ ' ' + $t('What’s new') + ' ' }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t('The latest from the ServerOS changelog.') +
                            ' '
                        }}
                    </DialogDescription>
                </div>
            </div>

            <ol v-if="latest.length" class="min-h-0 overflow-y-auto">
                <li
                    v-for="(entry, index) in latest"
                    :key="entry.id"
                    class="flex flex-col gap-2 px-5 py-4"
                    :class="{ 'border-t border-(--ds-divider)': index > 0 }"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full px-2 py-px text-[11px]/4 font-semibold"
                            :class="changelogTone[entry.category]"
                            >{{ entry.categoryLabel }}</span
                        >
                        <span class="text-[12px]/4 text-(--ds-text-muted)">{{
                            changelogDate(entry.publishedAt)
                        }}</span>
                        <span
                            v-if="fresh.has(entry.id)"
                            class="ml-auto flex items-center gap-1.5 text-[11px]/4 font-medium text-(--ds-blue)"
                        >
                            <span
                                class="size-1.5 rounded-full bg-(--ds-blue)"
                            />
                            {{ $t('New') + ' ' }}
                        </span>
                    </div>
                    <a
                        :href="entry.url"
                        target="_blank"
                        rel="noopener"
                        class="w-fit rounded text-[15px]/6 font-medium hover:underline hover:underline-offset-4"
                        :class="focusRing"
                        >{{ entry.title }}</a
                    >
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{ entry.summary }}
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <template v-if="entry.linkUrl">
                            <Link
                                v-if="entry.linkUrl.startsWith('/')"
                                :href="entry.linkUrl"
                                class="ds-raised-button cursor-pointer text-[12.5px]!"
                                :class="focusRing"
                                @click="open = false"
                                >{{ entry.linkLabel ?? $t('Try it') }}</Link
                            >
                            <a
                                v-else
                                :href="entry.linkUrl"
                                target="_blank"
                                rel="noopener"
                                class="ds-raised-button cursor-pointer text-[12.5px]!"
                                :class="focusRing"
                                >{{ entry.linkLabel ?? $t('Try it') }}</a
                            >
                        </template>
                        <a
                            :href="entry.url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1 rounded text-[12.5px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
                            :class="focusRing"
                        >
                            {{ ' ' + $t('Read more') }}
                            <ArrowUpRight
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                        </a>
                    </div>
                </li>
            </ol>
            <p
                v-else
                class="px-5 py-10 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t('Nothing yet. Updates show up here as they ship.') +
                    ' '
                }}
            </p>

            <div
                class="flex shrink-0 items-center justify-between gap-2 border-t border-(--ds-border) px-5 py-3"
            >
                <a
                    :href="`${page.props.appUrl}/changelog/rss`"
                    target="_blank"
                    rel="noopener"
                    class="rounded text-[12.5px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
                    :class="focusRing"
                    >{{ $t('RSS feed') }}</a
                >
                <a
                    :href="`${page.props.appUrl}/changelog`"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-1 rounded text-[12.5px]/5 font-medium text-(--ds-text) hover:underline hover:underline-offset-4"
                    :class="focusRing"
                >
                    {{ ' ' + $t('Full changelog') }}
                    <ArrowUpRight class="size-3.5" :stroke-width="1.75" />
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
