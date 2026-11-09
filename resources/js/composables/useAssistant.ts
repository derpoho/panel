import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Ask AI: one conversation shared by the top bar button and the chat
 * column, kept while you move between pages (and for the browser tab's
 * session). The server works through a message as an agent and streams
 * what it does as lines of JSON (AssistantAgent): its words, each tool it
 * runs as a step, and changes that wait for your Approve. While it works
 * the panel follows it to the page it's on.
 */
export type StepState =
    | 'running'
    | 'waiting'
    | 'done'
    | 'failed'
    | 'declined'
    | 'skipped';

export type AssistantStep = {
    kind: 'step';
    id: string;
    tool: string;
    title: string;
    detail: string;
    state: StepState;
    summary?: string;
    url?: string | null;
    /** Set for a change that asked for approval. */
    approval?: {
        fields: { label: string; value: string }[];
        destructive: boolean;
        alwaysAsk: boolean;
    };
};

export type AssistantItem =
    | { kind: 'user'; text: string }
    | { kind: 'text'; text: string }
    | { kind: 'error'; text: string }
    | AssistantStep;

/** The models one of the workspace's own AI keys can use (AiProviders::modelOptions). */
export type ModelOption = {
    provider: string;
    label: string;
    default: string;
    models: { id: string; label: string }[];
};

export type AssistantAllowance = {
    used: number;
    limit: number;
    ownKey: boolean;
    resetsIn: number | null;
};

type AgentEvent =
    | { type: 'chat'; id: string; model?: string | null }
    | { type: 'text'; text: string }
    | {
          type: 'step';
          id: string;
          tool: string;
          title: string;
          detail: string;
      }
    | { type: 'running'; id: string }
    | {
          type: 'result';
          id: string;
          ok: boolean;
          state: StepState;
          summary: string;
          url?: string | null;
      }
    | {
          type: 'approval';
          id: string;
          tool: string;
          title: string;
          detail: string;
          fields: { label: string; value: string }[];
          destructive: boolean;
          alwaysAsk?: boolean;
          url: string | null;
      }
    | { type: 'navigate'; url: string; target: string }
    | { type: 'error'; message: string }
    | { type: 'ping' }
    | { type: 'done'; allowance: AssistantAllowance };

const OPEN_KEY = 'assistant-open';
const EXPANDED_KEY = 'assistant-expanded';
const FOLLOW_KEY = 'assistant-follow';
const CHAT_KEY = 'assistant-agent-chat';
const MODEL_KEY = 'assistant-model';

function read<T>(storage: () => Storage, key: string, fallback: T): T {
    try {
        const value = storage().getItem(key);
        return value === null ? fallback : (JSON.parse(value) as T);
    } catch {
        return fallback;
    }
}

function write(storage: () => Storage, key: string, value: unknown): void {
    try {
        storage().setItem(key, JSON.stringify(value));
    } catch {
        // Storage may be unavailable (private mode, blocked site data).
    }
}

const saved = read<{ id: string | null; items: AssistantItem[] }>(
    () => sessionStorage,
    CHAT_KEY,
    { id: null, items: [] },
);

const open = ref<boolean>(read(() => localStorage, OPEN_KEY, false));
/** Wide: the column takes half the page instead of 400px. */
const expanded = ref<boolean>(read(() => localStorage, EXPANDED_KEY, false));
/** Follow along: the panel goes to the page the agent is working on. */
const follow = ref<boolean>(read(() => localStorage, FOLLOW_KEY, true));
const chatId = ref<string | null>(saved.id);
// A step that was running when the page went away won't hear how it ended.
for (const item of saved.items) {
    if (item.kind === 'step' && item.state === 'running') {
        item.state = 'skipped';
        item.summary =
            'Interrupted: the page reloaded. Its page shows how it ended.';
    }
}
const items = ref<AssistantItem[]>(saved.items);
const streaming = ref(false);
const allowance = ref<AssistantAllowance | null>(null);
/** Models to pick from: null until asked, [] when it runs on ServerOS's AI. */
const modelOptions = ref<ModelOption[] | null>(null);
/** The pick, "provider:model", kept in the browser; null for the key's own default. */
const model = ref<string | null>(read(() => localStorage, MODEL_KEY, null));
/** What answered the last message, as the server said. */
const activeModel = ref<string | null>(null);
let controller: AbortController | null = null;
/** They pressed Stop: the browser stopped listening, the server may not have stopped. */
let stoppedByUser = false;

watch(open, (value) => write(() => localStorage, OPEN_KEY, value));
watch(expanded, (value) => write(() => localStorage, EXPANDED_KEY, value));
watch(model, (value) => write(() => localStorage, MODEL_KEY, value));
watch(follow, (value) => write(() => localStorage, FOLLOW_KEY, value));
watch(
    [chatId, items],
    () =>
        write(() => sessionStorage, CHAT_KEY, {
            id: chatId.value,
            items: items.value,
        }),
    { deep: true },
);

/**
 * How it's going, for the light in the chat and on the page: working on a
 * message, waiting for an Approve, or the last thing failed.
 */
const activity = computed<'working' | 'waiting' | 'failed' | null>(() => {
    if (
        items.value.some(
            (item) => item.kind === 'step' && item.state === 'waiting',
        )
    )
        return 'waiting';
    if (streaming.value) return 'working';
    return items.value[items.value.length - 1]?.kind === 'error'
        ? 'failed'
        : null;
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function step(id: string): AssistantStep | undefined {
    return items.value.find(
        (item): item is AssistantStep => item.kind === 'step' && item.id === id,
    );
}

/**
 * Someone's in the middle of something on the page: typing in a field, or a
 * dialog open. Following the agent to another page then would lose it.
 */
function busyOnPage(): boolean {
    const active = document.activeElement;
    const inChat = (el: Element | null) =>
        !!el?.closest('#assistant-panel, [data-assistant-sheet]');
    if (
        active instanceof HTMLElement &&
        !inChat(active) &&
        (active.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName))
    ) {
        return true;
    }

    return Array.from(
        document.querySelectorAll('[role="dialog"][data-state="open"]'),
    ).some(
        (dialog) =>
            !inChat(dialog) &&
            !dialog.querySelector('#assistant-panel, [data-assistant-sheet]'),
    );
}

/** A page in this panel, as a path Inertia can visit; null for elsewhere. */
function samePage(url: string): string | null {
    try {
        const target = new URL(url, window.location.href);
        if (target.origin !== window.location.origin) return null;
        return target.pathname + target.search;
    } catch {
        return null;
    }
}

/** Go to the page the agent is working on. */
function go(url: string): void {
    if (!follow.value) return;
    const path = samePage(url);
    if (!path) return;
    // Don't pull them off a half-filled form; the step's link goes there.
    if (busyOnPage()) return;
    const here = window.location.pathname + window.location.search;
    if (path === here) return;
    router.visit(path, { preserveScroll: false });
}

function handle(event: AgentEvent): void {
    switch (event.type) {
        case 'chat':
            chatId.value = event.id;
            activeModel.value = event.model ?? null;
            break;
        case 'text':
            items.value.push({ kind: 'text', text: event.text });
            break;
        case 'step':
            items.value.push({
                kind: 'step',
                id: event.id,
                tool: event.tool,
                title: event.title,
                detail: event.detail,
                state: 'running',
            });
            break;
        case 'running': {
            const s = step(event.id);
            if (s) s.state = 'running';
            break;
        }
        case 'result': {
            const s = step(event.id);
            if (s) {
                s.state = event.state;
                s.summary = event.summary;
                s.url = event.url ?? null;
            }
            break;
        }
        case 'approval':
            items.value.push({
                kind: 'step',
                id: event.id,
                tool: event.tool,
                title: event.title,
                detail: event.detail,
                state: 'waiting',
                approval: {
                    fields: event.fields,
                    destructive: event.destructive,
                    alwaysAsk: event.alwaysAsk ?? event.destructive,
                },
            });
            if (event.url) go(event.url);
            break;
        case 'navigate':
            go(event.url);
            break;
        case 'error':
            items.value.push({ kind: 'error', text: event.message });
            break;
        case 'done':
            allowance.value = event.allowance;
            break;
    }
}

async function post(url: string, body: Record<string, unknown>): Promise<void> {
    streaming.value = true;
    controller = new AbortController();
    stoppedByUser = false;
    let finished = false;

    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                // JSON first, so a signed-out session gets a 401, not the login page.
                Accept: 'application/json, application/x-ndjson',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        });

        if (!response.ok || !response.body) {
            const json = await response.json().catch(() => ({}));
            if (json.allowance) allowance.value = json.allowance;
            if (response.status === 410) {
                chatId.value = null;
                for (const item of items.value) {
                    if (
                        item.kind === 'step' &&
                        (item.state === 'running' || item.state === 'waiting')
                    ) {
                        item.state = 'skipped';
                        item.summary = trans('This chat expired');
                    }
                }
            }
            items.value.push({
                kind: 'error',
                text:
                    json.error ??
                    (response.status === 429
                        ? trans('That’s a lot of messages. Give it a minute.')
                        : (json.message ??
                          trans('Couldn’t get an answer. Try again.'))),
            });
            return;
        }

        // Anything but the stream (a login page after a redirect, a proxy's
        // error page) is said plainly rather than read as nothing.
        if (!(response.headers.get('Content-Type') ?? '').includes('ndjson')) {
            items.value.push({
                kind: 'error',
                text: response.redirected
                    ? trans(
                          'You’ve been signed out. Sign in again and ask once more.',
                      )
                    : trans('Couldn’t get an answer. Try again.'),
            });
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            let end: number;
            while ((end = buffer.indexOf('\n')) !== -1) {
                const line = buffer.slice(0, end).trim();
                buffer = buffer.slice(end + 1);
                if (!line) continue;
                try {
                    const event = JSON.parse(line) as AgentEvent;
                    if (event.type === 'done') finished = true;
                    handle(event);
                } catch {
                    // A line we can't read; skip it.
                }
            }
        }
        if (!finished && !stoppedByUser) {
            items.value.push({
                kind: 'error',
                text: trans(
                    'The answer stopped partway. Anything already approved may still be running: its page shows how it went.',
                ),
            });
        }
    } catch (cause) {
        if ((cause as Error).name !== 'AbortError') {
            items.value.push({
                kind: 'error',
                text: trans('Couldn’t reach Ask AI. Try again.'),
            });
        }
    } finally {
        streaming.value = false;
        controller = null;
        // Anything still spinning: the browser stopped listening, but work
        // already started on a server carries on to the end.
        for (const item of items.value) {
            if (item.kind === 'step' && item.state === 'running') {
                item.state = 'skipped';
                item.summary = stoppedByUser
                    ? trans(
                          'Still finishing on the server; its page shows how it ends',
                      )
                    : trans('Stopped');
            }
        }
    }
}

async function send(text: string): Promise<void> {
    const message = text.trim();
    if (!message || streaming.value) return;
    // Anything waiting is skipped by sending something new.
    for (const item of items.value) {
        if (item.kind === 'step' && item.state === 'waiting') {
            item.state = 'skipped';
            item.summary = trans('Skipped');
        }
    }
    items.value.push({ kind: 'user', text: message });
    await post('/assistant', {
        chat: chatId.value,
        message,
        path: window.location.pathname,
        model: chosenModel(),
    });
}

async function decide(
    item: AssistantStep,
    approve: boolean,
    always = false,
): Promise<void> {
    if (streaming.value || item.state !== 'waiting' || !chatId.value) return;
    item.state = approve ? 'running' : 'declined';
    if (!approve) item.summary = trans('Declined');
    await post('/assistant/decide', {
        chat: chatId.value,
        call: item.id,
        approve,
        always,
    });
}

/** The picked model, if it's still one the workspace's keys offer. */
function chosenModel(): string | null {
    const pick = model.value;
    if (!pick || !modelOptions.value) return null;
    const [provider, ...rest] = pick.split(':');
    const id = rest.join(':');
    const option = modelOptions.value.find((o) => o.provider === provider);

    return option?.models.some((m) => m.id === id) ? pick : null;
}

let modelsLoading: Promise<void> | null = null;

/** Ask once a page load which models can be picked. */
function loadModels(force = false): Promise<void> {
    if (modelsLoading && !force) return modelsLoading;
    modelsLoading = fetch('/assistant/models', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
        .then((r) => (r.ok ? r.json() : { options: [] }))
        .then((json: { options?: ModelOption[] }) => {
            modelOptions.value = json.options ?? [];
        })
        .catch(() => {
            modelOptions.value = [];
            modelsLoading = null;
        });

    return modelsLoading;
}

/** Open Ask AI and ask it about something (Fix with Ask AI). */
async function askAbout(text: string): Promise<void> {
    open.value = true;
    if (streaming.value) return;
    await nextTick();
    await send(text);
}

function stop(): void {
    stoppedByUser = true;
    controller?.abort();
}

function clear(): void {
    stop();
    chatId.value = null;
    items.value = [];
}

export function useAssistant() {
    return {
        open,
        expanded,
        follow,
        items,
        streaming,
        activity,
        allowance,
        send,
        askAbout,
        model,
        modelOptions,
        activeModel,
        loadModels,
        decide,
        stop,
        clear,
        toggle: () => (open.value = !open.value),
    };
}
