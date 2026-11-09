<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, X } from '@lucide/vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import {
    dismissSubscriptionNotice,
    useSubscriptionNotice,
} from '@/composables/useSubscriptionNotice';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace, WorkspaceAccess } from '@/types';

const page = usePage<{
    workspace: Workspace;
    workspaceAccess: WorkspaceAccess | null;
}>();

const message = useSubscriptionNotice();
const upgrade = useUpgradeLink();

const copied = ref(false);

async function copyCode(code: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(code);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {
        // Clipboard blocked; the code is on screen to type.
    }
}

/**
 * The upgrade link (serveros.com: Billing), with a plan's checkout opened
 * when the notice says which. Null when there's nowhere to go.
 */
const billingHref = computed(() => {
    if (message.value?.href) return message.value.href;
    if (!upgrade.value) return null;
    const url = upgrade.value.href;

    return message.value?.choose
        ? `${url}?choose=${message.value.choose}`
        : url;
});

const canManageBilling = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-billing') ??
        false,
);
</script>

<template>
    <div
        v-if="message"
        class="flex min-h-11 shrink-0 flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-1.5 font-ui text-[13px]/5"
        :class="
            message.tone === 'urgent'
                ? 'bg-(--ds-amber-bg) text-(--ds-amber-strong)'
                : 'bg-(--ds-panel) text-(--ds-text-secondary)'
        "
        role="status"
    >
        <span>{{ message.text }}</span>
        <span
            v-if="message.promo && canManageBilling"
            class="inline-flex flex-wrap items-center gap-1.5"
        >
            {{ ' ' + $t('Use') }}
            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1 rounded-[5px] border border-(--ds-border) bg-(--ds-panel) px-1.5 py-px font-ui-mono text-[12px]/4.5 font-medium text-(--ds-text) transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                :aria-label="
                    copied
                        ? $t('Code copied')
                        : $t('Copy the code :code', {
                              code: String(message.promo.code),
                          })
                "
                @click="copyCode(message.promo.code)"
            >
                {{ message.promo.code }}
                <Check v-if="copied" class="size-3" :stroke-width="2.4" />
                <Copy v-else class="size-3 opacity-60" :stroke-width="2" />
            </button>
            {{ $t('for :offer.', { offer: message.promo.offer }) + ' ' }}
        </span>
        <Link
            v-if="billingHref && (canManageBilling || message.href)"
            :href="billingHref"
            :class="
                appButtonClasses(
                    message.tone === 'urgent' ? 'primary' : 'secondary',
                    'sm',
                )
            "
        >
            {{ message.action }}
        </Link>
        <span v-else-if="!canManageBilling" class="text-(--ds-text-muted)">
            {{ ' ' + $t('Ask your workspace owner to choose a plan.') + ' ' }}
        </span>
        <button
            v-if="message.dismissible"
            type="button"
            class="flex size-6 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
            :aria-label="
                message.dismiss ? $t('Hide') : $t('Hide until tomorrow')
            "
            :title="message.dismiss ? $t('Hide') : $t('Hide until tomorrow')"
            @click="
                message.dismiss
                    ? message.dismiss()
                    : dismissSubscriptionNotice(page.props.workspace.id)
            "
        >
            <X class="size-3.5" :stroke-width="2" />
        </button>
    </div>
</template>
