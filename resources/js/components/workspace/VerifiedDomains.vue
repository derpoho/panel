<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { SolarCopy as Copy, SolarRotateCw as RotateCw } from '@/lib/solarIcons';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import { toast } from '@/lib/toast';

export type EmailDomain = {
    id: number;
    domain: string;
    verified: boolean;
    verifiedAt: string | null;
    checkedAt: string | null;
    record: { name: string; value: string };
};

export type EmailDomains = {
    /** On Pro and Business. */
    available: boolean;
    domains: EmailDomain[];
};

/**
 * Security › Verified domains: the company's mail domains, each proven
 * with a DNS TXT record. Company Google sign-in, single sign-on and SCIM
 * all go by them, so this shows whether or not Google sign-in is set up.
 */
const props = defineProps<{ emailDomains: EmailDomains }>();

const domainForm = useForm({ domain: '' });
function addDomain(): void {
    domainForm.post('/settings/email-domains', {
        preserveScroll: true,
        onSuccess: () => domainForm.reset(),
    });
}

const checking = ref<number | null>(null);
function checkDomain(domain: EmailDomain): void {
    checking.value = domain.id;
    router.post(
        `/settings/email-domains/${domain.id}/check`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (checking.value = null),
        },
    );
}

function removeDomain(domain: EmailDomain): void {
    if (
        !window.confirm(
            trans(
                'Remove :domain? Google sign-in, SSO and SCIM stop going by it.',
                { domain: domain.domain },
            ),
        )
    )
        return;
    router.delete(`/settings/email-domains/${domain.id}`, {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.domain) toast.error(errors.domain);
        },
    });
}

const copied = ref<string | null>(null);
async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = text;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}

const pill = {
    verified: 'bg-(--ds-green-bg) text-(--ds-green)',
    waiting: 'bg-(--ds-amber-bg) text-(--ds-amber)',
};
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <section
        id="verified-domains"
        class="flex scroll-mt-6 flex-col gap-3"
        aria-labelledby="verified-domains-heading"
    >
        <div class="flex flex-col gap-0.5">
            <h2
                id="verified-domains-heading"
                class="text-[15px]/6 font-semibold tracking-[-0.01em]"
            >
                {{ $t('Verified domains') }}
            </h2>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'Prove your company’s email domain is yours. Google sign-in, single sign-on and SCIM only work for emails on a verified domain.',
                    )
                }}
            </p>
        </div>

        <div class="ds-tile flex flex-col">
            <ul
                v-if="props.emailDomains.domains.length"
                class="ds-tile-rows flex flex-col"
            >
                <li
                    v-for="domain in props.emailDomains.domains"
                    :key="domain.id"
                    class="ds-tile-row flex flex-col gap-3 px-5 py-3"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span
                            class="font-ui-mono text-[13px]/5 text-(--ds-text)"
                            >{{ domain.domain }}</span
                        >
                        <span
                            class="rounded-full px-2 py-px text-[11.5px]/4 font-medium"
                            :class="
                                domain.verified ? pill.verified : pill.waiting
                            "
                        >
                            {{
                                domain.verified
                                    ? $t('Verified')
                                    : $t('Waiting for DNS')
                            }}
                        </span>
                        <div class="ml-auto flex gap-1.5">
                            <AppButton
                                v-if="!domain.verified"
                                size="xs"
                                :disabled="checking === domain.id"
                                @click="checkDomain(domain)"
                            >
                                <RotateCw
                                    class="size-3"
                                    :stroke-width="1.75"
                                    :class="{
                                        'animate-spin': checking === domain.id,
                                    }"
                                />
                                {{ $t('Check') }}
                            </AppButton>
                            <AppButton
                                size="xs"
                                variant="quiet"
                                @click="removeDomain(domain)"
                            >
                                {{ $t('Remove') }}
                            </AppButton>
                        </div>
                    </div>

                    <div
                        v-if="!domain.verified"
                        class="rounded-lg border border-(--ds-border) bg-(--ds-field)"
                    >
                        <p
                            class="px-3.5 pt-3 pb-2 text-[12.5px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Add this TXT record where the domain’s DNS is managed, then press Check. It can go on the domain itself too.',
                                )
                            }}
                        </p>
                        <table class="w-full text-[12px]/5">
                            <tbody>
                                <tr class="border-t border-(--ds-divider)">
                                    <td
                                        class="w-16 px-3.5 py-2 font-ui-mono text-(--ds-text-muted)"
                                    >
                                        TXT
                                    </td>
                                    <td
                                        v-for="part in [
                                            domain.record.name,
                                            domain.record.value,
                                        ]"
                                        :key="part"
                                        class="px-3.5 py-2"
                                    >
                                        <button
                                            type="button"
                                            class="flex cursor-pointer items-center gap-1.5 text-left font-ui-mono break-all hover:text-(--ds-text)"
                                            :class="focusRing"
                                            :title="$t('Copy')"
                                            @click="copy(part)"
                                        >
                                            {{ part }}
                                            <component
                                                :is="
                                                    copied === part
                                                        ? Check
                                                        : Copy
                                                "
                                                class="size-3 shrink-0 text-(--ds-text-faint)"
                                                :stroke-width="1.75"
                                            />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </li>
            </ul>

            <form
                class="flex flex-col gap-2 px-5 py-3.5"
                :class="{
                    'border-t border-(--ds-divider)':
                        props.emailDomains.domains.length,
                }"
                @submit.prevent="addDomain"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="domainForm.domain"
                        class="h-8 min-w-48 grow basis-56 text-[13px]"
                        :placeholder="$t('acme.com')"
                        :aria-label="$t('Your company’s email domain')"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <AppButton
                        size="sm"
                        type="submit"
                        :disabled="
                            domainForm.processing || !domainForm.domain.trim()
                        "
                    >
                        {{ $t('Add domain') }}
                    </AppButton>
                </div>
                <InputError :message="domainForm.errors.domain" />
            </form>
        </div>
    </section>
</template>
