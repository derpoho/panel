<script setup lang="ts">
import { Check, LoaderCircle } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { availability } from '@/routes/workspaces';

type Availability = {
    slug: string;
    available: boolean;
    message: string | null;
    suggestions: string[];
};

const props = defineProps<{
    domain: string;
    error?: string;
    tabindex?: number;
    /** Shown under the field while it's empty or untouched. */
    hint?: string;
}>();

const slug = ref('');
const status = ref<'idle' | 'checking' | 'available' | 'unavailable'>('idle');
const message = ref<string | null>(null);
const suggestions = ref<string[]>([]);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let pendingRequest: AbortController | undefined;

/**
 * Keep the value in subdomain form while typing: lowercase letters, numbers
 * and single hyphens.
 */
function normalize(value: string): string {
    return value
        .toLowerCase()
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-{2,}/g, '-')
        .replace(/^-/, '')
        .slice(0, 32);
}

async function check(value: string): Promise<void> {
    pendingRequest?.abort();
    pendingRequest = new AbortController();

    try {
        const response = await fetch(
            availability.url({ query: { slug: value } }),
            {
                headers: { Accept: 'application/json' },
                signal: pendingRequest.signal,
            },
        );

        if (!response.ok) {
            status.value = 'idle';

            return;
        }

        const result = (await response.json()) as Availability;

        if (result.slug !== slug.value.replace(/-$/, '')) {
            return;
        }

        status.value = result.available ? 'available' : 'unavailable';
        message.value = result.message;
        suggestions.value = result.suggestions;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            status.value = 'idle';
        }
    }
}

watch(slug, (value, previous) => {
    const normalized = normalize(value);

    if (normalized !== value) {
        slug.value = normalized;

        return;
    }

    if (normalized === previous) {
        return;
    }

    clearTimeout(debounceTimer);
    pendingRequest?.abort();
    message.value = null;
    suggestions.value = [];

    const candidate = normalized.replace(/-$/, '');

    if (candidate === '') {
        status.value = 'idle';

        return;
    }

    status.value = 'checking';
    debounceTimer = setTimeout(() => check(candidate), 350);
});

function useSuggestion(suggestion: string): void {
    slug.value = suggestion;
}

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    pendingRequest?.abort();
});
</script>

<template>
    <div class="grid gap-2">
        <Label for="workspace">{{ $t('Workspace') }}</Label>
        <div
            data-slot="slug-input"
            class="flex h-8 w-full items-center rounded-md border border-(--ds-border-strong) bg-(--ds-field) text-[13px] transition-[color,box-shadow] focus-within:border-[#3B7BFF] focus-within:ring-[3px] focus-within:ring-[#3B7BFF]/20"
            :class="{
                'border-destructive': status === 'unavailable' || props.error,
            }"
        >
            <input
                id="workspace"
                v-model="slug"
                name="workspace"
                type="text"
                inputmode="url"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                maxlength="32"
                :placeholder="$t('kernel-panic')"
                :tabindex="tabindex"
                :aria-invalid="status === 'unavailable' || !!props.error"
                aria-describedby="workspace-status"
                class="h-full min-w-0 flex-1 bg-transparent pl-3 text-foreground outline-none placeholder:text-muted-foreground"
            />
            <span class="shrink-0 pr-2 text-muted-foreground select-none"
                >.{{ domain }}</span
            >
            <span class="flex w-6 shrink-0 justify-center pr-2">
                <LoaderCircle
                    v-if="status === 'checking'"
                    class="size-3.5 animate-spin text-muted-foreground"
                />
                <Check
                    v-else-if="status === 'available'"
                    class="size-3.5 text-emerald-400"
                />
            </span>
        </div>

        <div id="workspace-status" aria-live="polite" class="text-[13px]/4">
            <template v-if="status === 'unavailable'">
                <p class="text-red-500">{{ message }}</p>
                <div
                    v-if="suggestions.length"
                    class="mt-2 flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-muted-foreground">{{
                        $t('Available:')
                    }}</span>
                    <button
                        v-for="suggestion in suggestions"
                        :key="suggestion"
                        type="button"
                        class="rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-foreground transition-colors hover:bg-white/10 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        @click="useSuggestion(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </template>
            <p v-else-if="status === 'available'" class="text-muted-foreground">
                {{
                    $t(':value.:domain is available.', {
                        value: slug.replace(/-$/, ''),
                        domain,
                    }) + ' '
                }}
            </p>
            <InputError v-else-if="props.error" :message="props.error" />
            <p v-else class="text-muted-foreground">
                {{
                    hint ??
                    $t(
                        "Optional. We'll create one from your name if you skip it.",
                    )
                }}
            </p>
        </div>
    </div>
</template>
