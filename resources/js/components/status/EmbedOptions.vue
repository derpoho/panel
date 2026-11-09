<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';

/**
 * Ways to put a public status page on another site: a widget in an iframe
 * (card or one line, light, dark or following the visitor), a badge for a
 * README, and the JSON. Previews the widget as it's set up.
 */
const props = defineProps<{
    /** The page's address, e.g. https://serveros.com/status/lumi. */
    url: string;
    title: string;
}>();

const view = ref<'card' | 'bar'>('card');
const theme = ref<'light' | 'dark' | 'auto'>('light');

const origin = computed(() => new URL(props.url).origin);

const embedUrl = computed(() => {
    const params = new URLSearchParams();
    if (view.value === 'bar') params.set('view', 'bar');
    if (theme.value !== 'light') params.set('theme', theme.value);
    const query = params.toString();

    return `${props.url}/embed${query ? `?${query}` : ''}`;
});

const startHeight = computed(() => (view.value === 'bar' ? 42 : 320));

/*
 * The widget reports its height; this sizes the iframe to fit. The closing
 * tag is split so it can't end this component's own <script> block.
 */
const SCRIPT_END = '</' + 'script>';
const resizer = computed(
    () =>
        `<script>addEventListener("message",function(e){if(e.origin!=="${origin.value}"||!e.data||e.data.type!=="serveros:status-height")return;document.querySelectorAll('iframe[src^="${origin.value}/status/"]').forEach(function(f){if(f.contentWindow===e.source)f.style.height=e.data.height+"px"})});${SCRIPT_END}`,
);

const snippet = computed(
    () =>
        `<iframe src="${embedUrl.value}" title="${props.title.replace(/"/g, '&quot;')} status" width="100%" height="${startHeight.value}" style="border:0;border-radius:${view.value === 'bar' ? '8px' : '10px'};max-width:${view.value === 'bar' ? '360px' : '480px'};" loading="lazy"></iframe>\n${resizer.value}`,
);

const badgeUrl = computed(() => `${props.url}/badge.svg`);
const badgeMarkdown = computed(
    () => `[![Status](${badgeUrl.value})](${props.url})`,
);
const jsonUrl = computed(() => `${props.url}/status.json`);

const previewHeight = ref(startHeight.value);
const preview = ref<HTMLIFrameElement | null>(null);

function onMessage(event: MessageEvent): void {
    if (
        event.origin !== origin.value ||
        event.source !== preview.value?.contentWindow ||
        event.data?.type !== 'serveros:status-height'
    )
        return;
    previewHeight.value = Number(event.data.height) || startHeight.value;
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));

const copied = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) copied.value = null;
        }, 1600);
    } catch {
        // Clipboard blocked; the text is still selectable.
    }
}

/*
 * What sits behind the preview: a page in the widget's colours. "Match
 * visitor" follows this computer's setting, as the widget itself does.
 */
const backdrop = {
    light: 'bg-[#f6f7f9]',
    dark: 'bg-[#0f1013]',
    auto: 'bg-[#f6f7f9] [@media(prefers-color-scheme:dark)]:bg-[#0f1013]',
} as const;

const views = [
    { value: 'card', label: trans('Card') },
    { value: 'bar', label: trans('One line') },
] as const;
const themes = [
    { value: 'light', label: trans('Light') },
    { value: 'dark', label: trans('Dark') },
    { value: 'auto', label: trans('Match visitor') },
] as const;
</script>

<template>
    <div class="flex max-w-xl flex-col gap-3 pt-1.5">
        <div class="flex flex-wrap items-center gap-2">
            <div
                class="ds-segments"
                role="group"
                :aria-label="$t('Widget style')"
            >
                <button
                    v-for="option in views"
                    :key="option.value"
                    type="button"
                    class="cursor-pointer"
                    :aria-pressed="view === option.value"
                    @click="view = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
            <div
                class="ds-segments"
                role="group"
                :aria-label="$t('Widget theme')"
            >
                <button
                    v-for="option in themes"
                    :key="option.value"
                    type="button"
                    class="cursor-pointer"
                    :aria-pressed="theme === option.value"
                    @click="theme = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <div
            class="rounded-lg border border-(--ds-border) p-4"
            :class="backdrop[theme]"
        >
            <iframe
                ref="preview"
                :key="embedUrl"
                :src="embedUrl"
                :title="
                    $t(':title status widget preview', { title: String(title) })
                "
                class="block w-full border-0"
                :style="{
                    height: `${previewHeight}px`,
                    borderRadius: view === 'bar' ? '8px' : '10px',
                    maxWidth: view === 'bar' ? '360px' : '480px',
                }"
            />
        </div>

        <div class="flex items-start gap-2">
            <code
                class="min-w-0 grow rounded-md bg-(--ds-subtle) px-3 py-2 font-ui-mono text-xs/4.5 break-all whitespace-pre-wrap text-(--ds-text-secondary) select-all"
                >{{ snippet }}</code
            >
            <AppButton
                size="sm"
                variant="quiet"
                :aria-label="$t('Copy the widget\'s HTML')"
                @click="copy('widget', snippet)"
            >
                <Check
                    v-if="copied === 'widget'"
                    class="mi-pop size-3.5 text-(--ds-green)"
                    :stroke-width="2.25"
                />
                <Copy v-else class="mi-copy size-3.5" :stroke-width="1.75" />
            </AppButton>
        </div>
        <p class="text-xs/4.5 text-(--ds-text-muted)">
            {{
                ' ' +
                $t(
                    'Paste it where the widget should go. The script line sizes the frame to fit; leave it out and set the height yourself.',
                ) +
                ' '
            }}
        </p>

        <div class="flex items-center gap-3 pt-2">
            <img :src="badgeUrl" :alt="$t('Status badge')" class="h-5" />
        </div>
        <div
            v-for="row in [
                { key: 'badge', value: badgeMarkdown, name: 'badge Markdown' },
                { key: 'json', value: jsonUrl, name: 'JSON address' },
            ]"
            :key="row.key"
            class="flex items-center gap-2"
        >
            <code
                class="min-w-0 grow truncate rounded-md bg-(--ds-subtle) px-3 py-2 font-ui-mono text-xs/4 text-(--ds-text-secondary) select-all"
                >{{ row.value }}</code
            >
            <AppButton
                size="sm"
                variant="quiet"
                :aria-label="$t('Copy the :name', { name: String(row.name) })"
                @click="copy(row.key, row.value)"
            >
                <Check
                    v-if="copied === row.key"
                    class="mi-pop size-3.5 text-(--ds-green)"
                    :stroke-width="2.25"
                />
                <Copy v-else class="mi-copy size-3.5" :stroke-width="1.75" />
            </AppButton>
        </div>
    </div>
</template>
