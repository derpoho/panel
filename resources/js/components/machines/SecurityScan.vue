<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronDown, LoaderCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A look over a server: open ports, waiting security updates, services and
 * the system. One row per check, in the dashboard's list style: a spinner
 * while it runs, then a status dot, the finding, and what's behind it.
 * Rows settle one after another, never faster than the data.
 */
const props = defineProps<{ machineId: number }>();

const emit = defineEmits<{ finished: [issues: number] }>();

type Item = { text: string; tone: 'neutral' | 'warn' | 'issue' };
type Check = {
    key: string;
    label: string;
    state: 'scanning' | 'ok' | 'warn' | 'issue' | 'unknown';
    summary: string;
    items: Item[];
};

const placeholders: Check[] = [
    {
        key: 'ports',
        label: trans('Open ports'),
        state: 'scanning',
        summary: '',
        items: [],
    },
    {
        key: 'updates',
        label: trans('Security updates'),
        state: 'scanning',
        summary: '',
        items: [],
    },
    {
        key: 'services',
        label: trans('Services'),
        state: 'scanning',
        summary: '',
        items: [],
    },
    {
        key: 'system',
        label: trans('System'),
        state: 'scanning',
        summary: '',
        items: [],
    },
];

const checks = ref<Check[]>(placeholders);
/** How many cards may show their result yet: one more every ~0.9s. */
const revealed = ref(0);
const finished = ref(false);
const startedAt = Date.now();
const seconds = ref(0);

let jobId: string | null = null;
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let revealTimer: ReturnType<typeof setInterval> | undefined;
let polling = true;

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function start(): Promise<void> {
    try {
        const response = await fetch(`/machines/${props.machineId}/scan`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        jobId = (await response.json()).jobId ?? null;
    } catch {
        jobId = null;
    }

    void poll();
}

async function poll(): Promise<void> {
    if (!polling) return;

    try {
        const query = jobId ? `?job=${jobId}` : '';
        const response = await fetch(
            `/machines/${props.machineId}/scan${query}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            },
        );
        const report = await response.json();

        checks.value = report.checks;

        if (report.finished) {
            polling = false;

            return;
        }
    } catch {
        // Keep trying; the next poll may land.
    }

    pollTimer = setTimeout(poll, 2000);
}

/** A card shows its result once it's both revealed and actually done. */
function settled(index: number): boolean {
    return index < revealed.value && checks.value[index]?.state !== 'scanning';
}

const allSettled = computed(() => checks.value.every((_, i) => settled(i)));

const issues = computed(
    () =>
        checks.value.filter((c) => c.state === 'issue' || c.state === 'warn')
            .length,
);

onMounted(() => {
    void start();

    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    revealTimer = setInterval(() => {
        revealed.value = reduced ? checks.value.length : revealed.value + 1;
        seconds.value = Math.round((Date.now() - startedAt) / 1000);

        if (allSettled.value && !finished.value) {
            finished.value = true;
            emit('finished', issues.value);
        }
        if (finished.value && revealTimer) {
            clearInterval(revealTimer);
        }
    }, 900);
});

onBeforeUnmount(() => {
    polling = false;
    clearTimeout(pollTimer);
    clearInterval(revealTimer);
});

const summaryTone: Record<Check['state'], string> = {
    scanning: '',
    ok: 'text-(--ds-text-secondary)',
    warn: 'text-(--ds-amber)',
    issue: 'text-(--ds-red)',
    unknown: 'text-(--ds-text-muted)',
};

const itemTone: Record<Item['tone'], string> = {
    neutral: 'text-(--ds-text-muted)',
    warn: 'text-(--ds-amber)',
    issue: 'text-(--ds-red)',
};

const dotTone: Record<Check['state'], string> = {
    scanning: '',
    ok: 'bg-(--ds-green)',
    warn: 'bg-(--ds-amber)',
    issue: 'bg-(--ds-red)',
    unknown: 'bg-(--ds-text-faint)',
};

/** Rows show their first few findings; the rest open on request. */
const SHOWN = 3;
const opened = ref<string[]>([]);

function toggle(key: string): void {
    opened.value = opened.value.includes(key)
        ? opened.value.filter((k) => k !== key)
        : [...opened.value, key];
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul
            class="divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border)"
            :aria-label="$t('Security scan')"
        >
            <li
                v-for="(check, index) in checks"
                :key="check.key"
                class="grid grid-cols-[14px_minmax(0,1fr)] gap-x-3 px-4 py-3 sm:grid-cols-[14px_9rem_minmax(0,1fr)]"
                :aria-busy="!settled(index)"
            >
                <span class="flex h-5 items-center justify-center">
                    <LoaderCircle
                        v-if="!settled(index)"
                        class="size-3.5 animate-spin text-(--ds-text-faint) motion-reduce:animate-none"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <span
                        v-else
                        class="size-2 rounded-full"
                        :class="dotTone[check.state]"
                        :aria-label="check.state"
                    />
                </span>
                <span class="text-[13px]/5 font-medium">{{ check.label }}</span>

                <div class="col-start-2 min-w-0 sm:col-start-3">
                    <span
                        v-if="!settled(index)"
                        class="text-[13px]/5 text-(--ds-text-faint)"
                        >{{ $t('Checking…') }}</span
                    >
                    <template v-else>
                        <p
                            class="text-[13px]/5"
                            :class="summaryTone[check.state]"
                        >
                            {{ check.summary }}
                        </p>
                        <ul
                            v-if="check.items.length"
                            class="mt-1 flex flex-col gap-0.5 font-ui-mono text-xs/4.5"
                        >
                            <li
                                v-for="item in opened.includes(check.key)
                                    ? check.items
                                    : check.items.slice(0, SHOWN)"
                                :key="item.text"
                                class="truncate"
                                :class="itemTone[item.tone]"
                                :title="item.text"
                            >
                                {{ item.text }}
                            </li>
                        </ul>
                        <button
                            v-if="check.items.length > SHOWN"
                            type="button"
                            class="mt-1 flex cursor-pointer items-center gap-1 rounded-sm text-xs/4.5 font-medium text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                            :aria-expanded="opened.includes(check.key)"
                            @click="toggle(check.key)"
                        >
                            {{
                                opened.includes(check.key)
                                    ? $t('Show fewer')
                                    : $t(':count more', {
                                          count: String(
                                              check.items.length - SHOWN,
                                          ),
                                      })
                            }}
                            <ChevronDown
                                class="size-3 transition-transform"
                                :class="{
                                    'rotate-180': opened.includes(check.key),
                                }"
                                :stroke-width="2"
                            />
                        </button>
                    </template>
                </div>
            </li>
        </ul>

        <p
            class="flex items-center gap-2 text-[13px]/5 text-(--ds-text-muted)"
            role="status"
        >
            <template v-if="!finished">
                <LoaderCircle
                    class="size-3.5 animate-spin motion-reduce:animate-none"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                {{ $t('Checking your machine…') + ' ' }}
            </template>
            <template v-else>
                <span
                    class="size-2 rounded-full"
                    :class="issues ? 'bg-(--ds-amber)' : 'bg-(--ds-green)'"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium text-(--ds-text)">{{
                        issues
                            ? $t(
                                  issues === 1
                                      ? ':count thing to look at'
                                      : ':count things to look at',
                                  { count: String(issues) },
                              )
                            : $t('All clear')
                    }}</span>
                    {{
                        $t('· checked in :seconds :value', {
                            seconds: String(seconds ?? ''),
                            value: seconds === 1 ? $t('second') : $t('seconds'),
                        })
                    }}
                </span>
            </template>
        </p>
    </div>
</template>
