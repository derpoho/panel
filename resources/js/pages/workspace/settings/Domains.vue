<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ExternalLink } from '@lucide/vue';
import {
    SolarGlobe as Globe,
    SolarCopy as Copy,
    SolarRotateCw as RotateCw,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from '@/lib/toast';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import type { Workspace } from '@/types';

type Domain = {
    id: number;
    hostname: string | null;
    path: string | null;
    page: string | null;
    state: 'pending' | 'active' | 'failed';
    records: { type: string; name: string; value: string }[];
    error: string | null;
    checkedAt: string | null;
    url: string | null;
};

type Kind = 'dashboard' | 'status';

/**
 * Settings › Domains: the workspace's own domain for its dashboard and its
 * status page (Business). A row each; waiting on DNS, the records to add
 * sit under the row and the page checks again by itself. Once the
 * dashboard's domain is live, the page moves there.
 */
const props = defineProps<{
    workspace: Workspace;
    allowed: boolean;
    ready: boolean;
    canChange: boolean;
    cnameTarget: string;
    subdomain: string;
    dashboard: Domain | null;
    status: Domain | null;
    statusPages: {
        slug: string;
        name: string;
        live: boolean;
        fleet: boolean;
        public: boolean;
        settingsUrl: string;
    }[];
    goUrl: string;
    docsUrl: string;
    /** Where to get a plan with own domains; null when there's nowhere. */
    billingUrl: string | null;
}>();

const usable = computed(() => props.allowed && props.ready && props.canChange);

const rows = computed(() => [
    {
        kind: 'dashboard' as Kind,
        label: trans('Dashboard'),
        domain: props.dashboard,
        shown: (d: Domain) => d.hostname ?? '',
    },
    {
        kind: 'status' as Kind,
        label: trans('Status page'),
        domain: props.status,
        shown: (d: Domain) =>
            d.hostname ??
            `${props.dashboard?.hostname ?? props.subdomain}/${d.path}`,
    },
]);

/**
 * The status page the status domain shows, when only members can open it:
 * visitors would be sent to sign in on our domain instead.
 */
const privateStatusPage = computed(() => {
    const slug = props.status?.page;
    const page = props.statusPages.find((p) =>
        slug ? p.slug === slug : p.fleet,
    );

    return page && !page.public ? page : null;
});

/** The page picked in the connect dialog, when it's members only. */
const pickedPrivate = computed(
    () => props.statusPages.find((p) => p.slug === form.page)?.public === false,
);

const connecting = ref<Kind | null>(null);
const statusMode = ref<'host' | 'path'>('host');
const form = useForm({
    purpose: 'dashboard' as Kind,
    hostname: '',
    path: 'status',
    page: props.statusPages[0]?.slug ?? '',
});

function openConnect(kind: Kind): void {
    form.reset();
    form.clearErrors();
    form.purpose = kind;
    statusMode.value = 'host';
    connecting.value = kind;
}

function connect(): void {
    form.transform((data) =>
        data.purpose === 'status' && statusMode.value === 'path'
            ? { ...data, hostname: null }
            : { ...data, path: null },
    ).post('/settings/domains', {
        preserveScroll: true,
        onSuccess: () => (connecting.value = null),
    });
}

const checking = ref<number | null>(null);
function check(domain: Domain): void {
    router.post(
        `/settings/domains/${domain.id}/check`,
        {},
        {
            preserveScroll: true,
            onStart: () => (checking.value = domain.id),
            onFinish: () => (checking.value = null),
        },
    );
}

const removing = ref<Domain | null>(null);
const removeBusy = ref(false);
function disconnect(): void {
    if (!removing.value) return;
    router.delete(`/settings/domains/${removing.value.id}`, {
        preserveScroll: true,
        onStart: () => (removeBusy.value = true),
        onFinish: () => {
            removeBusy.value = false;
            removing.value = null;
        },
    });
}

/* Waiting on DNS: look again every 10 seconds. */
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        const waiting = [props.dashboard, props.status].some(
            (d) => d?.state === 'pending',
        );
        if (waiting && document.visibilityState === 'visible') {
            router.reload({ only: ['dashboard', 'status'] });
        }
    }, 10_000);
});
onBeforeUnmount(() => clearInterval(timer));

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
    pending: 'bg-(--ds-amber-bg) text-(--ds-amber)',
    active: 'bg-(--ds-green-bg) text-(--ds-green)',
    failed: 'bg-(--ds-red-bg) text-(--ds-red)',
};
const pillLabel = computed(() => ({
    pending: trans('Waiting for DNS'),
    active: trans('Live'),
    failed: trans('Failed'),
}));

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const th = 'px-3 py-2.5 text-left text-[12px]/4 font-medium';
</script>

<template>
    <Head :title="$t('Domains')" />

    <Tile
        :title="$t('Domains')"
        :description="
            $t(
                'Your own domain for the dashboard and the status page, instead of :subdomain. MCP and the API stay where they are.',
                { subdomain },
            )
        "
        :icon="Globe"
        :padded="false"
    >
        <p
            v-if="!allowed || !ready"
            class="border-b border-(--ds-divider) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            <template v-if="!allowed">
                {{ $t('Your own domains come with Business.') + ' ' }}
                <a
                    v-if="billingUrl"
                    :href="billingUrl"
                    class="text-(--ds-text) underline underline-offset-2"
                    >{{ $t('See plans') }}</a
                >
            </template>
            <template v-else>{{
                $t('Custom domains aren’t switched on for this panel yet.')
            }}</template>
        </p>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[620px] text-[13px]/5">
                <thead>
                    <tr class="text-(--ds-text-muted)">
                        <th :class="[th, 'w-40 pl-5']">{{ $t('For') }}</th>
                        <th :class="th">{{ $t('Domain') }}</th>
                        <th :class="th">{{ $t('Status') }}</th>
                        <th :class="[th, 'pr-5']" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.kind">
                        <tr class="border-t border-(--ds-divider)">
                            <td class="px-3 py-3 pl-5 font-medium">
                                {{ row.label }}
                            </td>
                            <td class="px-3 py-3">
                                <span
                                    v-if="row.domain"
                                    class="font-ui-mono text-[12.5px]"
                                    >{{ row.shown(row.domain) }}</span
                                >
                                <span v-else class="text-(--ds-text-faint)">{{
                                    $t('Not connected')
                                }}</span>
                            </td>
                            <td class="px-3 py-3">
                                <span
                                    v-if="row.domain"
                                    class="rounded-full px-2 py-px text-[11.5px]/4 font-medium"
                                    :class="pill[row.domain.state]"
                                    >{{ pillLabel[row.domain.state] }}</span
                                >
                            </td>
                            <td class="px-3 py-3 pr-5">
                                <div class="flex justify-end gap-1.5">
                                    <template v-if="row.domain">
                                        <a
                                            v-if="
                                                row.domain.state === 'active' &&
                                                row.domain.url
                                            "
                                            :href="
                                                row.kind === 'dashboard'
                                                    ? goUrl
                                                    : row.domain.url
                                            "
                                            class="inline-flex"
                                        >
                                            <AppButton size="xs">
                                                <ExternalLink class="size-3" />
                                                {{ $t('Open') }}
                                            </AppButton>
                                        </a>
                                        <AppButton
                                            v-if="
                                                row.domain.state !== 'active' &&
                                                row.domain.hostname
                                            "
                                            size="xs"
                                            :disabled="
                                                checking === row.domain.id ||
                                                !usable
                                            "
                                            @click="check(row.domain)"
                                        >
                                            <RotateCw
                                                class="size-3"
                                                :class="{
                                                    'animate-spin':
                                                        checking ===
                                                        row.domain.id,
                                                }"
                                            />
                                            {{ $t('Check now') }}
                                        </AppButton>
                                        <AppButton
                                            v-if="canChange"
                                            size="xs"
                                            variant="quiet"
                                            @click="removing = row.domain"
                                        >
                                            {{ $t('Disconnect') }}
                                        </AppButton>
                                    </template>
                                    <AppButton
                                        v-else
                                        size="xs"
                                        :disabled="
                                            !usable ||
                                            (row.kind === 'status' &&
                                                !statusPages.length)
                                        "
                                        @click="openConnect(row.kind)"
                                    >
                                        {{ $t('Connect') }}
                                    </AppButton>
                                </div>
                            </td>
                        </tr>

                        <!-- Waiting on DNS: what to add -->
                        <tr
                            v-if="
                                row.domain &&
                                row.domain.state !== 'active' &&
                                row.domain.records.length
                            "
                        >
                            <td colspan="4" class="px-5 pb-4">
                                <div
                                    class="rounded-lg border border-(--ds-border) bg-(--ds-field)"
                                >
                                    <p
                                        class="px-3.5 pt-3 pb-2 text-[12.5px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t(
                                                'Add these where the domain’s DNS is managed. This page checks again by itself and moves on once it’s live, usually within minutes.',
                                            ) + ' '
                                        }}
                                        <span class="text-(--ds-text)">{{
                                            $t(
                                                'On Cloudflare, the CNAME can be proxied (orange cloud) or DNS only: both work.',
                                            )
                                        }}</span>
                                    </p>
                                    <table class="w-full text-[12px]/5">
                                        <tbody>
                                            <tr
                                                v-for="record in row.domain
                                                    .records"
                                                :key="record.type + record.name"
                                                class="border-t border-(--ds-divider)"
                                            >
                                                <td
                                                    class="w-16 px-3.5 py-2 font-ui-mono text-(--ds-text-muted)"
                                                >
                                                    {{ record.type }}
                                                </td>
                                                <td
                                                    v-for="part in [
                                                        record.name,
                                                        record.value,
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
                                                        />
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p
                                        v-if="row.domain.error"
                                        class="border-t border-(--ds-divider) px-3.5 py-2 text-[12px]/5 text-(--ds-amber)"
                                    >
                                        {{
                                            $t('Cloudflare says: :error', {
                                                error: row.domain.error,
                                            })
                                        }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <!-- A domain for a page only members can open -->
                        <tr
                            v-if="
                                row.kind === 'status' &&
                                row.domain &&
                                privateStatusPage
                            "
                        >
                            <td colspan="4" class="px-5 pb-4">
                                <p
                                    class="rounded-lg border border-(--ds-amber-border) bg-(--ds-amber-bg) px-3.5 py-2.5 text-[12.5px]/5 text-(--ds-amber-strong)"
                                >
                                    {{
                                        $t(
                                            ':page is private, so only members can open it. Visitors to this domain see that it’s private, with a link to sign in.',
                                            { page: privateStatusPage.name },
                                        )
                                    }}
                                    <a
                                        :href="privateStatusPage.settingsUrl"
                                        class="font-medium underline underline-offset-2"
                                        :class="focusRing"
                                        >{{ $t('Make it public') }}</a
                                    >
                                </p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p
            class="border-t border-(--ds-divider) px-5 py-3 text-[12.5px]/5 text-(--ds-text-muted)"
        >
            {{
                $t(
                    'Use a subdomain you own, pointed at :target with a CNAME record. Root domains like example.com can’t be used.',
                    { target: cnameTarget },
                ) + ' '
            }}
            <a
                :href="docsUrl"
                class="text-(--ds-text) underline underline-offset-2"
                >{{ $t('How it works') }}</a
            >
        </p>
    </Tile>

    <Dialog
        :open="connecting !== null"
        @update:open="(open) => !open && (connecting = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex min-w-0 flex-col" @submit.prevent="connect">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{
                            connecting === 'status'
                                ? $t('Connect a domain for the status page')
                                : $t('Connect a domain for the dashboard')
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Next you’ll add a DNS record pointing it at :target.',
                                { target: cnameTarget },
                            )
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div
                        v-if="connecting === 'status'"
                        class="flex flex-col gap-1.5"
                    >
                        <span :class="labelClass">{{ $t('Where') }}</span>
                        <div class="grid grid-cols-2 gap-1.5" role="radiogroup">
                            <button
                                v-for="option in [
                                    {
                                        value: 'host',
                                        label: $t('Its own subdomain'),
                                        example: 'status.example.com',
                                        disabled: false,
                                    },
                                    {
                                        value: 'path',
                                        label: $t('A path'),
                                        example: `${dashboard?.hostname ?? 'ops.example.com'}/status`,
                                        disabled: !dashboard,
                                    },
                                ] as const"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="statusMode === option.value"
                                :disabled="option.disabled"
                                class="ds-choice flex cursor-pointer flex-col items-start gap-0.5 px-3 py-2.5 text-left disabled:cursor-not-allowed disabled:opacity-50"
                                :class="focusRing"
                                @click="statusMode = option.value"
                            >
                                <span class="text-[13px]/5 font-medium">{{
                                    option.label
                                }}</span>
                                <span
                                    class="truncate font-ui-mono text-[11.5px]/4 text-(--ds-text-muted)"
                                    >{{ option.example }}</span
                                >
                            </button>
                        </div>
                        <span
                            v-if="!dashboard"
                            class="text-[12px]/4 text-(--ds-text-faint)"
                            >{{
                                $t(
                                    'A path needs the dashboard’s domain connected first.',
                                )
                            }}</span
                        >
                    </div>

                    <div
                        v-if="connecting !== 'status' || statusMode === 'host'"
                        class="flex flex-col gap-1.5"
                    >
                        <label for="domain-hostname" :class="labelClass">{{
                            $t('Domain')
                        }}</label>
                        <Input
                            id="domain-hostname"
                            v-model="form.hostname"
                            class="font-ui-mono md:text-[13px]"
                            :placeholder="
                                connecting === 'status'
                                    ? 'status.example.com'
                                    : 'ops.example.com'
                            "
                            spellcheck="false"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.hostname" />
                    </div>
                    <div v-else class="flex flex-col gap-1.5">
                        <label for="domain-path" :class="labelClass">{{
                            $t('Path')
                        }}</label>
                        <div
                            class="flex h-9 items-center rounded-md border border-input bg-(--ds-field) pl-3 font-ui-mono text-[13px] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                        >
                            <span class="shrink-0 text-(--ds-text-muted)"
                                >{{ dashboard?.hostname }}/</span
                            >
                            <input
                                id="domain-path"
                                v-model="form.path"
                                class="h-full min-w-0 grow bg-transparent outline-none"
                                spellcheck="false"
                                autocomplete="off"
                                required
                            />
                        </div>
                        <InputError :message="form.errors.path" />
                    </div>

                    <div
                        v-if="connecting === 'status'"
                        class="flex flex-col gap-1.5"
                    >
                        <label for="domain-page" :class="labelClass">{{
                            $t('Which status page')
                        }}</label>
                        <select
                            id="domain-page"
                            v-model="form.page"
                            class="h-9 w-full rounded-md border border-input bg-(--ds-field) px-2.5 text-[13px] outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50"
                        >
                            <option
                                v-for="page in statusPages"
                                :key="page.slug"
                                :value="page.slug"
                            >
                                {{ page.name
                                }}{{ page.live ? '' : ' ' + $t('(off)') }}
                            </option>
                        </select>
                        <p
                            v-if="pickedPrivate"
                            class="text-xs/4 text-(--ds-amber)"
                        >
                            {{
                                $t(
                                    'This page is private: only members can open it. Make it public in its settings so visitors can see it.',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.page" />
                    </div>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="connecting = null"
                    >
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Connect')"
                        busy-label="Connecting…"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="removing !== null"
        @update:open="(open) => !open && (removing = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-base/6 font-semibold">
                    {{ $t('Disconnect') }}
                    {{ removing?.hostname ?? `/${removing?.path}` }}?
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'It stops working at once, and :subdomain is home again. You can connect it again later.',
                            { subdomain },
                        )
                    }}
                </DialogDescription>
            </div>
            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
            >
                <AppButton size="sm" variant="quiet" @click="removing = null">{{
                    $t('Cancel')
                }}</AppButton>
                <form @submit.prevent="disconnect">
                    <DangerButton
                        :label="$t('Disconnect')"
                        busy-label="Disconnecting…"
                        :processing="removeBusy"
                    />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
