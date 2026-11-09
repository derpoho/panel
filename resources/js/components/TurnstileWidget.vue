<script setup lang="ts">
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

/**
 * Cloudflare Turnstile inside a form: proof a person filled it in. It
 * stays out of sight unless Cloudflare wants a click, and puts its
 * one-time token in a hidden `cf-turnstile-response` field the form sends.
 * Each token works once, so the page resets it after a rejected submit.
 */
const props = defineProps<{ siteKey: string }>();

type TurnstileApi = {
    render: (el: HTMLElement, options: Record<string, unknown>) => string;
    reset: (id?: string) => void;
    remove: (id: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
        onServerOSTurnstile?: () => void;
    }
}

const SCRIPT =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onServerOSTurnstile';

const box = useTemplateRef<HTMLDivElement>('box');
let widget: string | null = null;

function render(): void {
    if (!window.turnstile || !box.value || widget !== null) return;
    widget = window.turnstile.render(box.value, {
        sitekey: props.siteKey,
        theme: 'auto',
        size: 'flexible',
        appearance: 'interaction-only',
    });
}

onMounted(() => {
    if (window.turnstile) {
        render();
        return;
    }
    window.onServerOSTurnstile = render;
    if (!document.querySelector(`script[src="${SCRIPT}"]`)) {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }
});

onBeforeUnmount(() => {
    if (widget !== null) window.turnstile?.remove(widget);
});

defineExpose({
    reset: () => {
        if (widget !== null) window.turnstile?.reset(widget);
    },
});
</script>

<template>
    <div ref="box" />
</template>
