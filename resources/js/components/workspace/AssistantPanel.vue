<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    ArrowUp,
    ArrowUpRight,
    Check,
    Maximize2,
    Minimize2,
    Eye,
    EyeOff,
    Pause,
    X,
} from '@lucide/vue';
import {
    SolarCopy as Copy,
    SolarSquare as Square,
    SolarSquarePen as SquarePen,
} from '@/lib/solarIcons';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import type { AssistantStep } from '@/composables/useAssistant';
import { useAssistant } from '@/composables/useAssistant';
import AssistantModelPicker from '@/components/workspace/AssistantModelPicker.vue';
import { renderMarkdown } from '@/lib/markdown';

/**
 * Ask AI's chat: answers questions and does things in the workspace. Each
 * tool the agent runs is a step here; a change waits on a card with
 * Approve. Lives in a column beside the page on desktop (pushing it over)
 * and in a sheet on phones (WorkspaceLayout).
 */
const emit = defineEmits<{ close: [] }>();

const {
    items,
    expanded,
    follow,
    streaming,
    activity,
    allowance,
    send,
    decide,
    stop,
    clear,
} = useAssistant();

const draft = ref('');
const input = useTemplateRef<HTMLTextAreaElement>('input');
const scroller = useTemplateRef<HTMLElement>('scroller');
const copied = ref<number | null>(null);

const suggestions = [
    trans('Deploy a web app with Postgres and Redis'),
    trans('Set up deploys on every push to GitHub'),
    trans('Something on my server looks broken, can you look?'),
    trans('How do I point my domain at an app?'),
];

const empty = computed(() => items.value.length === 0);

/** Waiting on the model: nothing is running and nobody is being asked. */
const thinking = computed(() => {
    if (!streaming.value) return false;
    return !items.value.some(
        (item) =>
            item.kind === 'step' &&
            (item.state === 'running' || item.state === 'waiting'),
    );
});

const waitingOnYou = computed(() =>
    items.value.some(
        (item) => item.kind === 'step' && item.state === 'waiting',
    ),
);

/**
 * The panel's light, from the bottom, as on Fixes: cyan while it works,
 * amber while a change waits for you, red when the last thing failed.
 */
const glows = {
    working: 'rgb(0 229 214 / 0.22)',
    waiting: 'rgb(245 158 11 / 0.2)',
    failed: 'rgb(239 68 68 / 0.16)',
} as const;
const glow = computed(() =>
    activity.value ? glows[activity.value] : 'transparent',
);

const allowanceLine = computed(() => {
    const a = allowance.value;
    if (!a) return null;
    const left = Math.max(0, a.limit - a.used);
    return trans(':left of :limit messages left this hour', {
        left: String(left),
        limit: String(a.limit),
    });
});

function ask(text = draft.value) {
    if (!text.trim() || streaming.value) return;
    void send(text);
    draft.value = '';
    void nextTick(resize);
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        ask();
    }
}

/** The box grows with what's typed, up to about six lines. */
function resize() {
    const el = input.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 148)}px`;
}

async function copy(index: number, text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = index;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        // Clipboard can be blocked; nothing to do.
    }
}

/** A link to a page in the panel, as a path; null for anywhere else. */
function pagePath(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        const target = new URL(url, window.location.href);
        return target.origin === window.location.origin
            ? target.pathname + target.search
            : null;
    } catch {
        return null;
    }
}

function stateLabel(item: AssistantStep): string {
    switch (item.state) {
        case 'running':
            return trans('Working');
        case 'waiting':
            return trans('Needs your approval');
        case 'done':
            return trans('Done');
        case 'failed':
            return trans('Failed');
        case 'declined':
            return trans('Declined');
        default:
            return trans('Skipped');
    }
}

/* Follow the work as it comes in, unless they've scrolled up to read. */
watch(
    () =>
        items.value
            .map((item) =>
                item.kind === 'step'
                    ? `${item.state}${item.summary ?? ''}`
                    : item.text.length,
            )
            .join(),
    async () => {
        const el = scroller.value;
        if (!el) return;
        const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        await nextTick();
        if (atBottom || !streaming.value) {
            el.scrollTop = el.scrollHeight;
        }
    },
);

/**
 * A link to a page of this site inside an answer opens through Inertia: a
 * full page load would cut off an answer still coming in.
 */
function onProseClick(event: MouseEvent) {
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    )
        return;
    const link = (event.target as Element | null)?.closest('a[href]');
    const path = link ? pagePath(link.getAttribute('href')) : null;
    if (path && link?.getAttribute('target') !== '_blank') {
        event.preventDefault();
        router.visit(path);
    }
}

function focus() {
    input.value?.focus();
}

defineExpose({ focus });

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <section
        class="relative isolate flex h-full min-h-0 flex-col overflow-hidden font-ui text-(--ds-text)"
        :aria-label="$t('Ask AI')"
    >
        <div
            class="assistant-glow pointer-events-none absolute inset-0 -z-10"
            :style="{ '--assistant-glow': glow }"
            aria-hidden="true"
        />
        <header
            class="flex h-13 shrink-0 items-center gap-2.5 border-b border-(--ds-divider) pr-2 pl-4"
        >
            <img
                src="/images/landing/serveros-logo.svg"
                alt=""
                class="size-4 shrink-0 object-contain invert dark:invert-0"
            />
            <h2 class="grow text-[14px]/5 font-medium">{{ $t('Ask AI') }}</h2>
            <div class="assistant-input flex gap-0.5 rounded-[9px] p-px">
                <button
                    type="button"
                    class="assistant-key flex size-7 cursor-pointer items-center justify-center rounded-[7px]"
                    :class="[focusRing, follow ? 'text-(--ds-text)!' : '']"
                    :title="
                        follow
                            ? $t(
                                  'Following along: the page goes where Ask AI works',
                              )
                            : $t('Follow along')
                    "
                    :aria-label="$t('Follow along')"
                    :aria-pressed="follow"
                    @click="follow = !follow"
                >
                    <Eye v-if="follow" class="size-3.5" :stroke-width="1.8" />
                    <EyeOff v-else class="size-3.5" :stroke-width="1.8" />
                </button>
                <button
                    v-if="!empty"
                    type="button"
                    class="assistant-key flex size-7 cursor-pointer items-center justify-center rounded-[7px]"
                    :class="focusRing"
                    :title="$t('New chat')"
                    :aria-label="$t('New chat')"
                    @click="(clear(), focus())"
                >
                    <SquarePen class="size-3.5" :stroke-width="1.6" />
                </button>
                <button
                    type="button"
                    class="assistant-key hidden size-7 cursor-pointer items-center justify-center rounded-[7px] lg:flex"
                    :class="focusRing"
                    :title="expanded ? $t('Shrink') : $t('Expand')"
                    :aria-label="
                        expanded ? $t('Shrink panel') : $t('Expand panel')
                    "
                    :aria-pressed="expanded"
                    @click="expanded = !expanded"
                >
                    <Minimize2
                        v-if="expanded"
                        class="mi-shrink size-3.5"
                        :stroke-width="1.6"
                    />
                    <Maximize2
                        v-else
                        class="mi-grow size-3.5"
                        :stroke-width="1.6"
                    />
                </button>
                <button
                    type="button"
                    class="assistant-key flex size-7 cursor-pointer items-center justify-center rounded-[7px]"
                    :class="focusRing"
                    :title="$t('Close')"
                    :aria-label="$t('Close')"
                    @click="emit('close')"
                >
                    <X class="mi-close size-3.5" :stroke-width="1.6" />
                </button>
            </div>
        </header>

        <div
            ref="scroller"
            class="min-h-0 grow overflow-y-auto overscroll-contain px-4"
        >
            <!-- Nothing asked yet -->
            <div
                v-if="empty"
                class="mx-auto flex min-h-full w-full max-w-[720px] flex-col justify-end pb-4"
            >
                <p class="text-[20px]/7 font-medium tracking-[-0.01em]">
                    {{ ' ' + $t('What can I do for you?') + ' ' }}
                </p>
                <p class="mt-1 text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Ask a question, or tell me what to do: deploy an app, set up a workflow, look into a problem. You watch me work, and every change waits for your approval.',
                        ) +
                        ' '
                    }}
                </p>
                <ul class="mt-5 flex flex-col gap-2">
                    <li v-for="suggestion in suggestions" :key="suggestion">
                        <button
                            type="button"
                            class="assistant-key w-full cursor-pointer rounded-[8px] px-3 py-2 text-left text-[13px]/5"
                            :class="focusRing"
                            @click="ask(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </li>
                </ul>
            </div>

            <ol
                v-else
                class="mx-auto flex w-full max-w-[720px] flex-col gap-3 py-5"
                aria-live="polite"
            >
                <li
                    v-for="(item, index) in items"
                    :key="item.kind === 'step' ? item.id : `i${index}`"
                    class="flex flex-col"
                    :class="{
                        'items-end': item.kind === 'user',
                        'mt-3': item.kind === 'user' && index > 0,
                    }"
                >
                    <p
                        v-if="item.kind === 'user'"
                        class="assistant-bubble max-w-[85%] rounded-[8px] px-3.5 py-2 text-[13px]/5 whitespace-pre-wrap"
                    >
                        {{ item.text }}
                    </p>

                    <p
                        v-else-if="item.kind === 'error'"
                        class="text-[13px]/5 text-(--ds-red)"
                    >
                        {{ item.text }}
                    </p>

                    <!-- A change waiting on the person -->
                    <div
                        v-else-if="
                            item.kind === 'step' &&
                            item.approval &&
                            item.state === 'waiting'
                        "
                        class="agent-approval overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-frame)"
                    >
                        <div class="flex items-start gap-2.5 px-3.5 pt-3">
                            <span
                                class="mt-1 size-2 shrink-0 rounded-full"
                                :class="
                                    item.approval.destructive
                                        ? 'bg-(--ds-red)'
                                        : 'bg-(--ds-amber)'
                                "
                                aria-hidden="true"
                            />
                            <div class="min-w-0 grow">
                                <p class="text-[13px]/5 font-medium">
                                    {{ item.title }}
                                </p>
                                <p class="text-[12px]/4 text-(--ds-text-muted)">
                                    {{
                                        item.approval.destructive
                                            ? $t(
                                                  'This can’t be undone. Check it before you approve.',
                                              )
                                            : $t(
                                                  'Ask AI wants to make this change.',
                                              )
                                    }}
                                </p>
                            </div>
                        </div>
                        <dl
                            v-if="item.approval.fields.length"
                            class="mx-3.5 mt-2.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-[7px] bg-(--ds-panel) px-3 py-2 text-[12px]/4"
                        >
                            <template
                                v-for="field in item.approval.fields"
                                :key="field.label"
                            >
                                <dt class="text-(--ds-text-faint)">
                                    {{ field.label }}
                                </dt>
                                <dd
                                    class="min-w-0 font-mono break-all text-(--ds-text-secondary)"
                                >
                                    {{ field.value }}
                                </dd>
                            </template>
                        </dl>
                        <div
                            class="flex flex-wrap items-center gap-1.5 px-3.5 py-3"
                        >
                            <button
                                type="button"
                                :data-agent-approve="item.id"
                                class="flex h-7 cursor-pointer items-center gap-1.5 rounded-[7px] px-3 text-[12px]/4 font-medium text-white transition-[scale,filter] active:scale-[0.97] disabled:cursor-default disabled:opacity-50"
                                :class="[
                                    focusRing,
                                    item.approval.destructive
                                        ? 'bg-(--ds-red) hover:brightness-110'
                                        : 'bg-(--ds-blue) hover:brightness-110',
                                ]"
                                :disabled="streaming"
                                @click="decide(item, true)"
                            >
                                <Check class="size-3.5" :stroke-width="2.2" />
                                {{ $t('Approve') }}
                            </button>
                            <button
                                v-if="!item.approval.alwaysAsk"
                                type="button"
                                class="assistant-key h-7 cursor-pointer rounded-[7px] px-2.5 text-[12px]/4"
                                :class="focusRing"
                                :disabled="streaming"
                                :title="
                                    $t(
                                        'Approve this and any other change in this chat that can be undone',
                                    )
                                "
                                @click="decide(item, true, true)"
                            >
                                {{ $t('Approve all in this chat') }}
                            </button>
                            <button
                                type="button"
                                class="h-7 cursor-pointer rounded-[7px] px-2.5 text-[12px]/4 text-(--ds-text-muted) hover:text-(--ds-text) disabled:cursor-default disabled:opacity-50"
                                :class="focusRing"
                                :disabled="streaming"
                                @click="decide(item, false)"
                            >
                                {{ $t('Decline') }}
                            </button>
                        </div>
                    </div>

                    <!-- A tool the agent ran (or is running) -->
                    <div
                        v-else-if="item.kind === 'step'"
                        class="agent-step flex min-w-0 items-start gap-2 text-[12.5px]/5"
                    >
                        <span
                            class="mt-0.5 flex size-4 shrink-0 items-center justify-center"
                            :title="stateLabel(item)"
                        >
                            <span
                                v-if="item.state === 'running'"
                                class="assistant-pulse size-2 rounded-full"
                            />
                            <Check
                                v-else-if="item.state === 'done'"
                                class="size-3.5 text-(--ds-green)"
                                :stroke-width="2.2"
                            />
                            <X
                                v-else-if="item.state === 'failed'"
                                class="size-3.5 text-(--ds-red)"
                                :stroke-width="2.2"
                            />
                            <Pause
                                v-else
                                class="size-3 text-(--ds-text-faint)"
                                :stroke-width="2"
                            />
                            <span class="sr-only">{{ stateLabel(item) }}</span>
                        </span>
                        <div class="min-w-0 grow">
                            <p class="flex min-w-0 items-baseline gap-1.5">
                                <span
                                    class="shrink-0"
                                    :class="
                                        item.state === 'running'
                                            ? 'assistant-thinking'
                                            : 'text-(--ds-text-secondary)'
                                    "
                                    >{{ item.title }}</span
                                >
                                <span
                                    v-if="item.detail"
                                    class="truncate text-(--ds-text-faint)"
                                    >{{ item.detail }}</span
                                >
                            </p>
                            <p
                                v-if="item.summary && item.state !== 'done'"
                                class="text-[12px]/4"
                                :class="
                                    item.state === 'failed'
                                        ? 'text-(--ds-red)'
                                        : 'text-(--ds-text-faint)'
                                "
                            >
                                {{ item.summary }}
                            </p>
                        </div>
                        <span
                            v-if="
                                item.state === 'done' &&
                                item.summary &&
                                item.summary !== 'Done'
                            "
                            class="shrink-0 text-[12px]/5 text-(--ds-text-faint)"
                            >{{ item.summary }}</span
                        >
                        <Link
                            v-if="pagePath(item.url)"
                            :href="pagePath(item.url)!"
                            class="flex size-5 shrink-0 items-center justify-center rounded-[5px] text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                            :class="focusRing"
                            :title="$t('Open')"
                            :aria-label="$t('Open')"
                        >
                            <ArrowUpRight
                                class="size-3.5"
                                :stroke-width="1.8"
                            />
                        </Link>
                    </div>

                    <template v-else-if="item.kind === 'text'">
                        <!-- eslint-disable vue/no-v-html: sanitised by DOMPurify -->
                        <div
                            class="assistant-prose text-[13px]/[1.6] text-(--ds-text-secondary)"
                            @click="onProseClick"
                            v-html="renderMarkdown(item.text)"
                        />
                        <!-- eslint-enable vue/no-v-html -->
                        <div
                            v-if="!(streaming && index === items.length - 1)"
                            class="mt-1 -ml-1.5 flex items-center"
                        >
                            <button
                                type="button"
                                class="flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                                :class="focusRing"
                                :aria-label="
                                    copied === index
                                        ? $t('Copied')
                                        : $t('Copy answer')
                                "
                                :title="
                                    copied === index ? $t('Copied') : $t('Copy')
                                "
                                @click="copy(index, item.text)"
                            >
                                <Check
                                    v-if="copied === index"
                                    class="mi-pop size-3.5 text-(--ds-green)"
                                    :stroke-width="2"
                                />
                                <Copy
                                    v-else
                                    class="mi-copy size-3.5"
                                    :stroke-width="1.75"
                                />
                            </button>
                        </div>
                    </template>
                </li>

                <li v-if="thinking">
                    <p class="assistant-thinking text-[13px]/5">
                        {{ ' ' + $t('Thinking…') + ' ' }}
                    </p>
                </li>
            </ol>
        </div>

        <form
            class="mx-auto w-full max-w-[744px] shrink-0 px-3 pt-1 pb-3"
            @submit.prevent="ask()"
        >
            <div
                class="assistant-input flex items-end gap-2 rounded-[10px] py-1 pr-1 pl-3"
            >
                <label for="assistant-input" class="sr-only">{{
                    $t('Ask or tell Ask AI what to do')
                }}</label>
                <textarea
                    id="assistant-input"
                    ref="input"
                    v-model="draft"
                    rows="1"
                    :placeholder="
                        waitingOnYou
                            ? $t('Approve above, or say what to do instead…')
                            : $t('Ask, or tell me what to do…')
                    "
                    class="max-h-37 min-h-8 grow resize-none bg-transparent py-1.5 text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                    @input="resize"
                    @keydown="onKeydown"
                />
                <AssistantModelPicker mode="button" />
                <button
                    v-if="streaming"
                    type="button"
                    class="assistant-key flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[7px]"
                    :class="focusRing"
                    :aria-label="$t('Stop')"
                    :title="$t('Stop')"
                    @click="stop"
                >
                    <Square class="size-3" fill="currentColor" />
                </button>
                <button
                    v-else
                    type="submit"
                    class="assistant-key flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[7px] disabled:cursor-default"
                    :class="focusRing"
                    :disabled="!draft.trim()"
                    :aria-label="$t('Send')"
                    :title="$t('Send')"
                >
                    <ArrowUp class="size-4" :stroke-width="2.2" />
                </button>
            </div>
            <p class="mt-2 text-center text-[11px]/4 text-(--ds-text-faint)">
                {{
                    ' ' +
                    $t(
                        'Changes wait for your approval. Answers can be wrong.',
                    ) +
                    ' '
                }}
                <template v-if="allowanceLine">
                    {{ ' ' + allowanceLine + ' ' }}
                </template>
                <AssistantModelPicker mode="note" />
            </p>
        </form>
    </section>
</template>
