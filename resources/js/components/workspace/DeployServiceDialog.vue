<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ArrowLeft, Check, LoaderCircle, Search } from '@lucide/vue';
import {
    SolarLockKeyhole as LockKeyhole,
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
    SolarCopy as Copy,
} from '@/lib/solarIcons';
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TemplateIcon from '@/components/workspace/TemplateIcon.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { useStepFrame } from '@/composables/useStepFrame';
import { useZipUpload } from '@/composables/useZipUpload';

export type TemplateField = {
    key: string;
    label: string;
    hint: string | null;
    secret: boolean;
    required: boolean;
    accept: boolean;
    generated: boolean;
    default: string;
};

export type ServiceTemplate = {
    slug: string;
    name: string;
    category: string;
    description: string;
    icon: string;
    kind: 'compose' | 'app';
    port: number;
    web: boolean;
    portEditable: boolean;
    health: string | null;
    notes: string | null;
    env: TemplateField[];
};

type DeployResult = {
    jobId: string;
    template: string;
    generated: { key: string; label: string; value: string }[];
    /** Generated settings kept from the last deploy of this service, so its data keeps working. */
    kept?: string[];
    url: string | null;
    /** Where it listens on the machine, when there's no domain. */
    localUrl: string | null;
    /** An SSH tunnel command to open it from your own computer. */
    tunnel: string | null;
    port: number;
    notes: string | null;
};

/**
 * "Deploy a service": search the template library, fill in the template's
 * settings, deploy. Styled after the search palette; the three steps hand
 * over with the same blur-slide as the MCP dialog.
 */
const props = defineProps<{
    templates: ServiceTemplate[];
    machineId: number;
}>();

const emit = defineEmits<{ deployed: [result: DeployResult] }>();

/** Whether private GitHub repositories can be connected here. */
const page = usePage<{ githubApp: boolean }>();

const open = defineModel<boolean>('open', { default: false });

const step = ref<'pick' | 'configure' | 'done'>('pick');
const { height, freeze, unfreeze } = useStepFrame('stepInner');

const query = ref('');
const category = ref('All');
const activeIndex = ref(0);
const searchInput = ref<HTMLInputElement | null>(null);

const categories = computed(() => [
    'All',
    ...new Set(props.templates.map((template) => template.category)),
    'Custom',
]);

const counts = computed(() => {
    const totals: Record<string, number> = { All: props.templates.length };
    for (const template of props.templates) {
        totals[template.category] = (totals[template.category] ?? 0) + 1;
    }

    return totals;
});

/* Custom: paste a GitHub link, detect the stack, pick the matching template */

type Detection = {
    detected: boolean;
    template: string | null;
    framework: string | null;
    type: string | null;
    repo: string;
    branch: string;
    commit: string;
    reasons: string[];
    /** Set when the code came from an uploaded .zip instead of a link. */
    upload?: { filename: string; files: number; expiresAt: string };
};

const isCustom = computed(() => category.value === 'Custom');
const detecting = ref(false);
const detection = ref<Detection | null>(null);
const detectError = ref<string | null>(null);
/** The template to use: the detected one, or one picked instead. */
const customSlug = ref('');

const appTemplates = computed(() =>
    props.templates.filter((template) => template.kind === 'app'),
);

const customTemplate = computed(
    () =>
        props.templates.find(
            (template) => template.slug === customSlug.value,
        ) ?? null,
);

async function detect(): Promise<void> {
    const repository = query.value.trim();

    if (!repository || detecting.value) {
        return;
    }

    detecting.value = true;
    detectError.value = null;
    detection.value = null;

    try {
        const response = await fetch('/services/detect', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({ repository }),
        });
        const body = await response.json();

        if (!response.ok) {
            detectError.value =
                body.errors?.repository?.[0] ??
                body.message ??
                trans('Couldn’t check that repository.');

            return;
        }

        detection.value = body as Detection;
        customSlug.value = body.template ?? '';
    } catch {
        detectError.value = trans('Couldn’t reach the panel. Try again.');
    } finally {
        detecting.value = false;
    }
}

/* Or upload the project as a .zip: sent in pieces, then unpacked and
   detected on the panel, ending with the same detection as a link */
const zip = useZipUpload();
const uploading = zip.uploading;
const uploadProgress = zip.progress;
const dragging = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

async function uploadZip(file: File | undefined): Promise<void> {
    detectError.value = null;
    detection.value = null;
    const found = await zip.upload(props.machineId, file);
    if (fileInput.value) fileInput.value.value = '';
    if (!found) {
        detectError.value = zip.error.value;
        return;
    }
    detection.value = found as Detection;
    customSlug.value = found.template ?? '';
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    void uploadZip(event.dataTransfer?.files?.[0]);
}

function onPick(event: Event): void {
    void uploadZip((event.target as HTMLInputElement).files?.[0]);
}

/** Where the code came from, in one line for the detection card. */
function sourceLabel(found: Detection): string {
    if (found.upload) {
        return `${found.upload.filename} · ${found.upload.files} files`;
    }
    return `${found.repo
        .replace('https://github.com/', '')
        .replace(
            /\.git$/,
            '',
        )} · ${found.branch} @ ${found.commit.slice(0, 7)}`;
}

function continueCustom(): void {
    if (!detection.value || !customTemplate.value) {
        return;
    }

    choose(customTemplate.value, {
        repo: detection.value.repo,
        commit: detection.value.commit,
    });

    // An upload's address is a random token: name the service after the zip.
    const upload = detection.value.upload;
    if (upload) {
        const name = repoServiceName(upload.filename.replace(/\.zip$/i, ''));
        if (name) form.value.service = name;
    }
}

const results = computed(() => {
    const words = query.value.toLowerCase().split(/\s+/).filter(Boolean);

    return props.templates.filter((template) => {
        if (category.value !== 'All' && template.category !== category.value) {
            return false;
        }

        const haystack =
            `${template.name} ${template.slug} ${template.description} ${template.category}`.toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
});

/** Results grouped under category headings, keeping a flat index for keys. */
const grouped = computed(() => {
    const groups: {
        category: string;
        items: { template: ServiceTemplate; index: number }[];
    }[] = [];

    results.value.forEach((template, index) => {
        let group = groups.find((g) => g.category === template.category);

        if (!group) {
            group = { category: template.category, items: [] };
            groups.push(group);
        }

        group.items.push({ template, index });
    });

    return groups;
});

watch([query, category], () => (activeIndex.value = 0));

/* The box is a search on the library tabs and a link box on Custom. */
watch(category, (next, previous) => {
    if (next === 'Custom' || previous === 'Custom') {
        query.value = '';
        detection.value = null;
        detectError.value = null;
        nextTick(() => searchInput.value?.focus());
    }
});

function onSearchKeydown(event: KeyboardEvent): void {
    if (isCustom.value) {
        if (event.key === 'Enter') {
            event.preventDefault();
            detect();
        }

        return;
    }

    const count = results.value.length;
    // The cards sit in a grid: up and down move a row, left and right one.
    const step = window.matchMedia('(min-width: 1024px)').matches
        ? 3
        : window.matchMedia('(min-width: 640px)').matches
          ? 2
          : 1;
    const moves: Record<string, number> = {
        ArrowDown: step,
        ArrowUp: -step,
        ArrowRight: 1,
        ArrowLeft: -1,
    };
    const move = moves[event.key];
    const caretKey =
        (event.key === 'ArrowLeft' || event.key === 'ArrowRight') &&
        query.value !== '';

    if (move !== undefined && count && !caretKey) {
        event.preventDefault();
        activeIndex.value = Math.min(
            count - 1,
            Math.max(0, activeIndex.value + move),
        );
        scrollActiveIntoView();
    } else if (event.key === 'Enter' && count) {
        event.preventDefault();
        choose(results.value[activeIndex.value]);
    }
}

function scrollActiveIntoView(): void {
    nextTick(() =>
        document
            .getElementById(`template-${activeIndex.value}`)
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

const selected = ref<ServiceTemplate | null>(null);
const form = ref({
    service: '',
    port: '' as string | number,
    repo: '',
    commit: '',
    health_path: '',
    domain: '',
    wildcard: false,
    env: {} as Record<string, string | boolean>,
    extra_env: '',
});
const showExtraEnv = ref(false);
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

/**
 * The names and ports already in use on this machine. The dialog suggests
 * a name and port outside them, so deploying PostgreSQL twice gives two
 * databases instead of redeploying the first under the same name.
 */
const taken = ref<{ services: string[]; ports: number[] }>({
    services: [],
    ports: [],
});
/** What choose() filled in, to tell an untouched suggestion from an edit. */
const suggested = ref<{ service: string; port: string | number } | null>(null);

function freeName(base: string): string {
    if (!taken.value.services.includes(base)) {
        return base;
    }

    const stem = base.slice(0, 59);
    let n = 2;

    while (taken.value.services.includes(`${stem}-${n}`)) {
        n++;
    }

    return `${stem}-${n}`;
}

function freePort(port: number): number {
    let free = port;

    while (taken.value.ports.includes(free) && free < 65535) {
        free++;
    }

    return free;
}

async function loadTaken(): Promise<void> {
    const machineId = props.machineId;

    try {
        const response = await fetch(`/machines/${machineId}/deploy/taken`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok || machineId !== props.machineId) {
            return;
        }

        taken.value = await response.json();
    } catch {
        return;
    }

    // Picked a template before the answer came: move an untouched
    // suggestion off a name or port that turned out to be taken.
    const template = selected.value;

    if (
        step.value === 'configure' &&
        template &&
        suggested.value &&
        form.value.service === suggested.value.service &&
        form.value.port === suggested.value.port
    ) {
        form.value.service = freeName(suggested.value.service);

        if (template.portEditable && template.kind !== 'app') {
            form.value.port = freePort(template.port);
        }

        suggested.value = {
            service: form.value.service,
            port: form.value.port,
        };
    }
}

/** Deploying under this name redeploys what's already there. */
const replacesExisting = computed(
    () =>
        step.value === 'configure' &&
        taken.value.services.includes(form.value.service),
);

/** The port asked for is in use, by something this deploy isn't replacing. */
const portTaken = computed(
    () =>
        Boolean(selected.value?.portEditable) &&
        selected.value?.kind !== 'app' &&
        !replacesExisting.value &&
        taken.value.ports.includes(Number(form.value.port)),
);

/** "https://github.com/acme/My_App.git" → "my-app". */
function repoServiceName(repo: string): string {
    const name = repo
        .replace(/\.git$/, '')
        .split('/')
        .pop()
        ?.toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^[^a-z]+|-+$/g, '');

    return (name ?? '').slice(0, 63);
}

function serviceNameFor(template: ServiceTemplate): string {
    const name = template.slug === 'custom-dockerfile' ? 'app' : template.slug;

    return name.replace(/[^a-z0-9-]/g, '-').slice(0, 63);
}

function choose(
    template: ServiceTemplate | undefined,
    source: { repo: string; commit: string } | null = null,
): void {
    if (!template) {
        return;
    }

    selected.value = template;
    form.value = {
        service: freeName(
            source
                ? repoServiceName(source.repo) || serviceNameFor(template)
                : serviceNameFor(template),
        ),
        // An app's port is the one inside its container, not the machine's.
        port:
            template.portEditable && template.kind !== 'app'
                ? freePort(template.port)
                : template.port,
        repo: source?.repo ?? '',
        commit: source?.commit ?? '',
        health_path: template.health ?? '/',
        domain: '',
        wildcard: false,
        env: Object.fromEntries(
            template.env.map((field) => [
                field.key,
                field.accept ? false : field.default,
            ]),
        ),
        extra_env: '',
    };
    suggested.value = { service: form.value.service, port: form.value.port };
    showExtraEnv.value = false;
    errors.value = {};
    step.value = 'configure';
}

function back(): void {
    step.value = 'pick';
    nextTick(() => searchInput.value?.focus());
}

/** The first message for a field, from Laravel's validation errors. */
function errorFor(key: string): string | undefined {
    return errors.value[key];
}

const missingAcceptance = computed(
    () =>
        selected.value?.env.some(
            (field) => field.accept && form.value.env[field.key] !== true,
        ) ?? false,
);

/* The domain: does its DNS point here yet? Caddy on the machine gets the
 * certificate once it does, so this only guides; it never blocks a deploy. */

type DomainCheck = {
    domain: string;
    status: 'ready' | 'missing' | 'elsewhere' | 'proxied';
    expected: string | null;
    found: string[];
    record: { type: string; name: string; value: string } | null;
    /** Every subdomain, through a *.domain record, when wildcard is ticked. */
    wildcard?: Omit<DomainCheck, 'wildcard'>;
};

const DOMAIN_PATTERN =
    /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

const dns = ref<DomainCheck | null>(null);
const dnsChecking = ref(false);
let dnsTimer: ReturnType<typeof setTimeout> | undefined;
let dnsRequest = 0;

/** People paste URLs: keep only the host, so "https://x.com/" is "x.com". */
function bareDomain(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z]+:\/\//, '')
        .replace(/[/?#].*$/, '')
        .replace(/:\d+$/, '');
}

async function checkDomain(): Promise<void> {
    const domain = bareDomain(form.value.domain);
    const request = ++dnsRequest;

    if (!DOMAIN_PATTERN.test(domain)) {
        dns.value = null;
        dnsChecking.value = false;

        return;
    }

    dnsChecking.value = true;

    try {
        const response = await fetch(
            `/machines/${props.machineId}/domain-check`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    domain,
                    wildcard: form.value.wildcard,
                }),
            },
        );

        if (request === dnsRequest) {
            dns.value = response.ok
                ? ((await response.json()) as DomainCheck)
                : null;
        }
    } catch {
        if (request === dnsRequest) {
            dns.value = null;
        }
    } finally {
        if (request === dnsRequest) {
            dnsChecking.value = false;
        }
    }
}

watch(
    () => [form.value.domain, form.value.wildcard],
    () => {
        clearTimeout(dnsTimer);
        dns.value = null;
        dnsTimer = setTimeout(checkDomain, 500);
    },
);

async function deploy(): Promise<void> {
    const template = selected.value;

    if (!template || submitting.value) {
        return;
    }

    submitting.value = true;
    errors.value = {};

    const env = Object.fromEntries(
        Object.entries(form.value.env).map(([key, value]) => [
            key,
            typeof value === 'boolean' ? (value ? 'true' : '') : value,
        ]),
    );

    try {
        const response = await fetch(`/machines/${props.machineId}/deploy`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({
                template: template.slug,
                service: form.value.service,
                port: template.portEditable ? form.value.port : null,
                repo: template.kind === 'app' ? form.value.repo : null,
                commit: template.kind === 'app' ? form.value.commit : null,
                health_path:
                    template.kind === 'app' ? form.value.health_path : null,
                domain:
                    template.web && form.value.domain
                        ? bareDomain(form.value.domain)
                        : null,
                wildcard: Boolean(
                    template.web && form.value.domain && form.value.wildcard,
                ),
                env,
                extra_env: form.value.extra_env || null,
            }),
        });
        const body = await response.json();

        if (response.status === 422 && body.errors) {
            errors.value = Object.fromEntries(
                Object.entries(body.errors as Record<string, string[]>).map(
                    ([key, messages]) => [key, messages[0]],
                ),
            );

            return;
        }

        if (!response.ok) {
            errors.value = {
                form: body.error ?? trans('Couldn’t queue the deployment.'),
            };

            return;
        }

        result.value = body as DeployResult;
        step.value = 'done';
        emit('deployed', result.value);
    } catch {
        errors.value = { form: trans('Couldn’t reach the panel. Try again.') };
    } finally {
        submitting.value = false;
    }
}

const result = ref<DeployResult | null>(null);
const copied = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 1600);
    } catch {
        // Clipboard may be blocked; the value is still on screen.
    }
}

/* Each time it opens (or mounts open), start fresh on the search. */
watch(
    open,
    (isOpen) => {
        if (isOpen) {
            step.value = 'pick';
            query.value = '';
            category.value = 'All';
            selected.value = null;
            detection.value = null;
            detectError.value = null;
            result.value = null;
            errors.value = {};
            dns.value = null;
            void loadTaken();
        }
    },
    { immediate: true },
);

const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-form ds-modal top-[8%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text)"
            :class="step === 'pick' ? 'sm:max-w-5xl' : 'sm:max-w-xl'"
            :show-close-button="false"
            @open-auto-focus.prevent="searchInput?.focus()"
        >
            <DialogTitle class="sr-only">{{
                $t('Deploy a service')
            }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{
                    ' ' +
                    $t(
                        'Search the service library, then configure and deploy one to this machine.',
                    ) +
                    ' '
                }}
            </DialogDescription>

            <div class="ds-step-frame" :style="{ height }">
                <div ref="stepInner">
                    <Transition
                        name="ds-step"
                        mode="out-in"
                        @before-leave="freeze"
                        @enter="unfreeze"
                    >
                        <!-- Step 1: search the library -->
                        <div v-if="step === 'pick'" key="pick">
                            <label
                                class="flex h-12 items-center gap-2.5 border-b border-(--ds-border) px-4"
                            >
                                <Search
                                    class="size-4 shrink-0 text-(--ds-text-muted)"
                                    :stroke-width="1.5"
                                />
                                <span class="sr-only">{{
                                    $t('Search services')
                                }}</span>
                                <input
                                    ref="searchInput"
                                    v-model="query"
                                    type="text"
                                    role="combobox"
                                    aria-expanded="true"
                                    aria-controls="template-results"
                                    :aria-activedescendant="
                                        results.length
                                            ? `template-${activeIndex}`
                                            : undefined
                                    "
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="
                                        isCustom
                                            ? $t(
                                                  'Paste a GitHub link, like https://github.com/you/app',
                                              )
                                            : $t(
                                                  'Search :templatesCount services…',
                                                  {
                                                      templatesCount: String(
                                                          templates.length,
                                                      ),
                                                  },
                                              )
                                    "
                                    class="h-full min-w-0 grow bg-transparent text-sm text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                                    @keydown="onSearchKeydown"
                                />
                                <kbd
                                    class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px text-[11px]/3.5 text-(--ds-text-muted)"
                                    aria-hidden="true"
                                >
                                    Esc
                                </kbd>
                            </label>

                            <div
                                class="overflow-x-auto border-b border-(--ds-border) px-3 py-2 sm:hidden"
                            >
                                <div
                                    class="ds-segments ds-segments--sm"
                                    role="group"
                                    :aria-label="$t('Category')"
                                >
                                    <button
                                        v-for="option in categories"
                                        :key="option"
                                        type="button"
                                        class="cursor-pointer"
                                        :aria-pressed="category === option"
                                        @click="category = option"
                                    >
                                        {{ option }}
                                    </button>
                                </div>
                            </div>

                            <!-- Like /apps: categories down the side, the
                                 library as cards beside them -->
                            <div class="flex h-[min(36rem,68vh)]">
                                <nav
                                    class="hidden w-52 shrink-0 overflow-y-auto border-r border-(--ds-border) p-2 sm:block"
                                    :aria-label="$t('Category')"
                                >
                                    <button
                                        v-for="option in categories"
                                        :key="option"
                                        type="button"
                                        class="flex h-8 w-full cursor-pointer items-center justify-between gap-3 rounded-[7px] px-2.5 text-left text-[13px] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                                        :class="[
                                            category === option
                                                ? 'bg-(--ds-hover) font-medium text-(--ds-text)'
                                                : 'text-(--ds-text-secondary) hover:bg-(--ds-hover)/60 hover:text-(--ds-text)',
                                            option === 'Custom' &&
                                                'mt-2 border-t border-(--ds-border) pt-px',
                                        ]"
                                        :aria-pressed="category === option"
                                        @click="category = option"
                                    >
                                        <span class="truncate">{{
                                            option === 'All'
                                                ? $t('All services')
                                                : option === 'Custom'
                                                  ? $t('Your own code')
                                                  : option
                                        }}</span>
                                        <span
                                            v-if="option !== 'Custom'"
                                            class="text-xs text-(--ds-text-faint) tabular-nums"
                                            >{{ counts[option] ?? 0 }}</span
                                        >
                                    </button>
                                </nav>
                                <div class="min-w-0 grow overflow-y-auto">
                                    <div
                                        v-if="isCustom"
                                        class="flex min-h-56 flex-col gap-4 p-4"
                                    >
                                        <template v-if="detection">
                                            <div
                                                v-if="
                                                    detection.detected &&
                                                    customTemplate
                                                "
                                                class="flex items-start gap-3 rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 p-3.5"
                                            >
                                                <span
                                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text-secondary)"
                                                >
                                                    <TemplateIcon
                                                        colored
                                                        :icon="
                                                            customTemplate.icon
                                                        "
                                                    />
                                                </span>
                                                <div
                                                    class="flex min-w-0 grow flex-col gap-1"
                                                >
                                                    <p
                                                        class="flex flex-wrap items-center gap-2 text-sm font-medium"
                                                    >
                                                        {{
                                                            detection.framework
                                                        }}
                                                        <span
                                                            class="rounded-full border border-(--ds-border-strong) px-2 py-px text-[11px]/4 font-medium text-(--ds-text-secondary)"
                                                        >
                                                            {{ detection.type }}
                                                        </span>
                                                    </p>
                                                    <p
                                                        class="truncate font-ui-mono text-xs/4 text-(--ds-text-muted)"
                                                    >
                                                        {{
                                                            sourceLabel(
                                                                detection,
                                                            )
                                                        }}
                                                    </p>
                                                    <p
                                                        v-for="reason in detection.reasons"
                                                        :key="reason"
                                                        class="text-xs/4 text-(--ds-text-faint)"
                                                    >
                                                        {{ reason }}
                                                    </p>
                                                </div>
                                            </div>
                                            <p
                                                v-else
                                                class="rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 p-3.5 text-[13px]/5 text-(--ds-text-secondary)"
                                            >
                                                {{
                                                    ' ' +
                                                    $t(
                                                        'Couldn’t tell what :value is. Pick the setup that fits below, or add a Dockerfile to the project.',
                                                        {
                                                            value:
                                                                detection.upload
                                                                    ?.filename ??
                                                                sourceLabel(
                                                                    detection,
                                                                ),
                                                        },
                                                    ) +
                                                    ' '
                                                }}
                                            </p>

                                            <div class="flex flex-col gap-1.5">
                                                <label
                                                    for="custom-setup"
                                                    :class="labelClass"
                                                >
                                                    {{
                                                        detection.detected
                                                            ? $t(
                                                                  'Not right? Use a different setup',
                                                              )
                                                            : $t('Setup')
                                                    }}
                                                </label>
                                                <select
                                                    id="custom-setup"
                                                    v-model="customSlug"
                                                    class="ds-select h-8 cursor-pointer rounded-md border border-input bg-transparent pl-3 text-[13px]/4 text-(--ds-text) shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                                >
                                                    <option value="" disabled>
                                                        {{
                                                            ' ' +
                                                            $t(
                                                                'Choose a setup',
                                                            ) +
                                                            ' '
                                                        }}
                                                    </option>
                                                    <option
                                                        v-for="template in appTemplates"
                                                        :key="template.slug"
                                                        :value="template.slug"
                                                    >
                                                        {{ template.name }}
                                                    </option>
                                                </select>
                                            </div>

                                            <div
                                                class="mt-auto flex justify-end gap-2"
                                            >
                                                <AppButton
                                                    size="sm"
                                                    variant="quiet"
                                                    @click="
                                                        detection = null;
                                                        query = '';
                                                        searchInput?.focus();
                                                    "
                                                >
                                                    {{
                                                        ' ' +
                                                        $t('Try another') +
                                                        ' '
                                                    }}
                                                </AppButton>
                                                <AppButton
                                                    size="sm"
                                                    variant="primary"
                                                    :disabled="!customTemplate"
                                                    @click="continueCustom"
                                                >
                                                    {{
                                                        ' ' +
                                                        $t('Continue') +
                                                        ' '
                                                    }}
                                                </AppButton>
                                            </div>
                                        </template>

                                        <template v-else>
                                            <p
                                                class="text-[13px]/5 text-(--ds-text-muted)"
                                            >
                                                {{
                                                    ' ' +
                                                    $t(
                                                        'Paste a GitHub repository above',
                                                    )
                                                }}<template
                                                    v-if="page.props.githubApp"
                                                >
                                                    {{
                                                        ' ' +
                                                        $t(
                                                            '(for a private one, connect GitHub in Settings → General first)',
                                                        )
                                                    }}</template
                                                >{{
                                                    $t(
                                                        '. ServerOS checks its latest commit, works out what it is (web app, API, static site…) and sets up Docker for it. If the repository has its own Dockerfile, that gets used instead.',
                                                    ) + ' '
                                                }}
                                            </p>
                                            <div
                                                v-if="detecting"
                                                class="shimmer h-16 rounded-[10px]"
                                                :aria-label="
                                                    $t(
                                                        'Checking the repository',
                                                    )
                                                "
                                            />

                                            <!-- Or the code as a .zip, for projects not on GitHub -->
                                            <label
                                                class="flex cursor-pointer flex-col items-center gap-1.5 rounded-[10px] border border-dashed px-4 py-5 text-center transition-colors"
                                                :class="
                                                    dragging
                                                        ? 'border-[#3B7BFF] bg-[#3B7BFF]/6'
                                                        : 'border-(--ds-border-hover) hover:bg-(--ds-hover)/50'
                                                "
                                                @dragover.prevent="
                                                    dragging = true
                                                "
                                                @dragleave.prevent="
                                                    dragging = false
                                                "
                                                @drop.prevent="onDrop"
                                            >
                                                <input
                                                    ref="fileInput"
                                                    type="file"
                                                    accept=".zip,application/zip"
                                                    class="sr-only"
                                                    :disabled="uploading"
                                                    @change="onPick"
                                                />
                                                <template v-if="uploading">
                                                    <span
                                                        class="text-[13px]/5 font-medium"
                                                        >{{
                                                            uploadProgress < 1
                                                                ? $t(
                                                                      'Uploading… :round%',
                                                                      {
                                                                          round: String(
                                                                              Math.round(
                                                                                  uploadProgress *
                                                                                      100,
                                                                              ),
                                                                          ),
                                                                      },
                                                                  )
                                                                : $t(
                                                                      'Unpacking and checking…',
                                                                  )
                                                        }}</span
                                                    >
                                                    <span
                                                        class="h-1 w-full max-w-64 overflow-hidden rounded-full bg-(--ds-border-strong)"
                                                    >
                                                        <span
                                                            class="block h-full origin-left rounded-full bg-[#3B7BFF] transition-transform duration-200"
                                                            :style="{
                                                                transform: `scaleX(${uploadProgress})`,
                                                            }"
                                                        />
                                                    </span>
                                                </template>
                                                <template v-else>
                                                    <span
                                                        class="text-[13px]/5 font-medium"
                                                        >{{
                                                            $t(
                                                                'Or upload your project as a .zip',
                                                            )
                                                        }}</span
                                                    >
                                                    <span
                                                        class="text-xs/4 text-(--ds-text-muted)"
                                                        >{{
                                                            $t(
                                                                'Drop it here or click to choose. Up to 1 GB; node_modules, build folders and .env files are left out.',
                                                            )
                                                        }}</span
                                                    >
                                                </template>
                                            </label>
                                            <InputError
                                                :message="
                                                    detectError ?? undefined
                                                "
                                            />
                                            <div
                                                class="mt-auto flex justify-end"
                                            >
                                                <AppButton
                                                    size="sm"
                                                    variant="primary"
                                                    :disabled="
                                                        !query.trim() ||
                                                        detecting
                                                    "
                                                    @click="detect"
                                                >
                                                    {{
                                                        detecting
                                                            ? $t('Checking…')
                                                            : $t('Detect')
                                                    }}
                                                </AppButton>
                                            </div>
                                        </template>
                                    </div>

                                    <div
                                        v-else
                                        id="template-results"
                                        role="listbox"
                                        :aria-label="$t('Services')"
                                        class="p-3"
                                    >
                                        <p
                                            v-if="results.length === 0"
                                            class="px-3 py-10 text-center text-[13px]/5 text-(--ds-text-muted)"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'Nothing matches “:query”.',
                                                    {
                                                        query,
                                                    },
                                                ) +
                                                ' '
                                            }}
                                        </p>

                                        <div
                                            v-for="group in grouped"
                                            :key="group.category"
                                            class="mb-3 last:mb-0"
                                        >
                                            <p
                                                class="px-1 pt-1 pb-2 text-xs/4 font-medium text-(--ds-text-muted)"
                                                aria-hidden="true"
                                            >
                                                {{ group.category }}
                                            </p>
                                            <div
                                                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                            >
                                                <div
                                                    v-for="{
                                                        template,
                                                        index,
                                                    } in group.items"
                                                    :id="`template-${index}`"
                                                    :key="template.slug"
                                                    role="option"
                                                    :aria-selected="
                                                        index === activeIndex
                                                    "
                                                    class="flex cursor-pointer flex-col gap-3 rounded-[10px] border p-3.5 transition-colors duration-100"
                                                    :class="
                                                        index === activeIndex
                                                            ? 'border-(--ds-border-hover) bg-(--ds-hover)'
                                                            : 'border-(--ds-border) bg-(--ds-field)/40'
                                                    "
                                                    @mousemove="
                                                        activeIndex = index
                                                    "
                                                    @click="choose(template)"
                                                >
                                                    <span
                                                        class="flex items-center gap-3"
                                                    >
                                                        <span
                                                            class="flex size-9 shrink-0 items-center justify-center rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text-secondary)"
                                                        >
                                                            <TemplateIcon
                                                                colored
                                                                :icon="
                                                                    template.icon
                                                                "
                                                                class="size-[18px]"
                                                            />
                                                        </span>
                                                        <span
                                                            class="flex min-w-0 grow flex-col"
                                                        >
                                                            <span
                                                                class="truncate text-[13px]/5 font-medium"
                                                            >
                                                                {{
                                                                    template.name
                                                                }}
                                                            </span>
                                                            <span
                                                                class="truncate text-xs/4 text-(--ds-text-faint)"
                                                            >
                                                                {{
                                                                    template.category
                                                                }}
                                                            </span>
                                                        </span>
                                                        <span
                                                            v-if="
                                                                template.kind ===
                                                                'app'
                                                            "
                                                            class="shrink-0 self-start rounded-[4px] bg-(--ds-hover) px-1.5 text-[10px]/4 font-medium text-(--ds-text-muted)"
                                                        >
                                                            {{
                                                                ' ' +
                                                                $t(
                                                                    'Your repo',
                                                                ) +
                                                                ' '
                                                            }}
                                                        </span>
                                                    </span>
                                                    <span
                                                        class="line-clamp-2 text-xs/[18px] text-(--ds-text-muted)"
                                                    >
                                                        {{
                                                            template.description
                                                        }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: configure the chosen template -->
                        <form
                            v-else-if="step === 'configure' && selected"
                            key="configure"
                            @submit.prevent="deploy"
                        >
                            <div
                                class="flex h-12 items-center gap-2.5 border-b border-(--ds-border) px-2.5"
                            >
                                <button
                                    type="button"
                                    class="flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                    :aria-label="$t('Back to the library')"
                                    @click="back"
                                >
                                    <ArrowLeft
                                        class="size-4"
                                        :stroke-width="1.75"
                                    />
                                </button>
                                <span
                                    class="flex size-7 items-center justify-center rounded-md border border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text-secondary)"
                                >
                                    <TemplateIcon
                                        :icon="selected.icon"
                                        colored
                                    />
                                </span>
                                <span class="text-sm font-medium">
                                    {{ selected.name }}
                                </span>
                                <span
                                    class="truncate text-xs text-(--ds-text-faint)"
                                >
                                    {{ selected.category }}
                                </span>
                            </div>

                            <div
                                class="flex max-h-[min(30rem,60vh)] flex-col gap-4 overflow-y-auto p-4"
                            >
                                <p class="text-[13px]/5 text-(--ds-text-muted)">
                                    {{ selected.description }}
                                </p>
                                <p
                                    v-if="selected.notes"
                                    class="-mt-2 text-xs/4 text-(--ds-text-faint)"
                                >
                                    {{ selected.notes }}
                                </p>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="deploy-service"
                                            :class="labelClass"
                                        >
                                            {{ ' ' + $t('Service name') + ' ' }}
                                        </label>
                                        <Input
                                            id="deploy-service"
                                            v-model.trim="form.service"
                                            required
                                            maxlength="63"
                                            pattern="[a-z][a-z0-9-]*"
                                            class="font-ui-mono"
                                        />
                                        <InputError
                                            :message="errorFor('service')"
                                        />
                                        <p
                                            v-if="
                                                replacesExisting &&
                                                !errorFor('service')
                                            "
                                            class="text-xs/4 text-(--ds-text-faint)"
                                        >
                                            {{
                                                $t(
                                                    ':service is already on this machine. Deploying with this name replaces it and keeps its data. Pick another name to add a second one.',
                                                    { service: form.service },
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div
                                        v-if="selected.portEditable"
                                        class="flex flex-col gap-1.5"
                                    >
                                        <label
                                            for="deploy-port"
                                            :class="labelClass"
                                        >
                                            {{
                                                selected.kind === 'app'
                                                    ? $t(
                                                          'Port your app listens on',
                                                      )
                                                    : $t('Port on this machine')
                                            }}
                                        </label>
                                        <Input
                                            id="deploy-port"
                                            v-model.number="form.port"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            class="tabular-nums"
                                        />
                                        <InputError
                                            :message="errorFor('port')"
                                        />
                                        <p
                                            v-if="
                                                portTaken && !errorFor('port')
                                            "
                                            class="text-xs/4 text-(--ds-text-faint)"
                                        >
                                            {{
                                                $t(
                                                    'Something on this machine already uses port :port.',
                                                    { port: String(form.port) },
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <template v-if="selected.kind === 'app'">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="deploy-repo"
                                            :class="labelClass"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'Git repository (public HTTPS)',
                                                ) +
                                                ' '
                                            }}
                                        </label>
                                        <Input
                                            id="deploy-repo"
                                            v-model.trim="form.repo"
                                            type="url"
                                            required
                                            :placeholder="
                                                $t(
                                                    'https://github.com/you/app.git',
                                                )
                                            "
                                        />
                                        <InputError
                                            :message="errorFor('repo')"
                                        />
                                    </div>
                                    <div
                                        class="grid gap-4"
                                        :class="
                                            selected.web &&
                                            'sm:grid-cols-[2fr_1fr]'
                                        "
                                    >
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                for="deploy-commit"
                                                :class="labelClass"
                                            >
                                                {{
                                                    ' ' + $t('Commit SHA') + ' '
                                                }}
                                            </label>
                                            <Input
                                                id="deploy-commit"
                                                v-model.trim="form.commit"
                                                required
                                                pattern="[a-fA-F0-9]{40}"
                                                maxlength="40"
                                                :placeholder="
                                                    $t('40-character commit')
                                                "
                                                class="font-ui-mono"
                                            />
                                            <InputError
                                                :message="errorFor('commit')"
                                            />
                                        </div>
                                        <div
                                            v-if="selected.web"
                                            class="flex flex-col gap-1.5"
                                        >
                                            <label
                                                for="deploy-health"
                                                :class="labelClass"
                                            >
                                                {{
                                                    ' ' +
                                                    $t('Health path') +
                                                    ' '
                                                }}
                                            </label>
                                            <Input
                                                id="deploy-health"
                                                v-model.trim="form.health_path"
                                                class="font-ui-mono"
                                            />
                                            <InputError
                                                :message="
                                                    errorFor('health_path')
                                                "
                                            />
                                        </div>
                                    </div>
                                </template>

                                <div
                                    v-if="selected.web"
                                    class="flex flex-col gap-1.5"
                                >
                                    <label
                                        for="deploy-domain"
                                        :class="labelClass"
                                    >
                                        {{ ' ' + $t('Domain') }}
                                        <span
                                            class="font-normal text-(--ds-text-faint)"
                                        >
                                            {{ ' ' + $t('(optional)') + ' ' }}
                                        </span>
                                    </label>
                                    <Input
                                        id="deploy-domain"
                                        v-model.trim="form.domain"
                                        :placeholder="$t('app.example.com')"
                                        autocomplete="off"
                                        spellcheck="false"
                                    />
                                    <InputError :message="errorFor('domain')" />
                                    <p
                                        v-if="!form.domain"
                                        class="flex items-start gap-1.5 text-xs/4 text-(--ds-text-faint)"
                                    >
                                        <LockKeyhole
                                            class="mt-px size-3.5 shrink-0"
                                            :stroke-width="1.75"
                                            aria-hidden="true"
                                        />
                                        {{
                                            $t(
                                                'Point a domain at this machine and it gets HTTPS automatically, with a certificate that renews itself.',
                                            ) + ' '
                                        }}
                                    </p>
                                    <p
                                        v-else-if="dnsChecking && !dns"
                                        class="flex items-center gap-1.5 text-xs/4 text-(--ds-text-faint)"
                                        aria-live="polite"
                                    >
                                        <LoaderCircle
                                            class="size-3.5 animate-spin"
                                            :stroke-width="1.75"
                                            aria-hidden="true"
                                        />
                                        {{ $t('Checking DNS…') + ' ' }}
                                    </p>
                                    <div
                                        v-else-if="dns"
                                        class="flex flex-col gap-2.5 rounded-[10px] border px-3.5 py-3 text-xs/4"
                                        :class="
                                            dns.status === 'ready'
                                                ? 'border-(--ds-green-border) bg-(--ds-green-bg)'
                                                : 'border-(--ds-amber-border) bg-(--ds-amber-bg)'
                                        "
                                        aria-live="polite"
                                    >
                                        <p
                                            class="flex items-start gap-1.5 text-(--ds-text-secondary)"
                                        >
                                            <CircleCheck
                                                v-if="dns.status === 'ready'"
                                                class="mt-px size-3.5 shrink-0 text-(--ds-green-strong)"
                                                :stroke-width="2"
                                                aria-hidden="true"
                                            />
                                            <CircleAlert
                                                v-else
                                                class="mt-px size-3.5 shrink-0 text-(--ds-amber-strong)"
                                                :stroke-width="2"
                                                aria-hidden="true"
                                            />
                                            <span v-if="dns.status === 'ready'">
                                                <span class="font-ui-mono">{{
                                                    dns.domain
                                                }}</span>
                                                {{
                                                    $t(
                                                        'points to this machine. HTTPS is set up as it deploys.',
                                                    ) + ' '
                                                }}
                                            </span>
                                            <span
                                                v-else-if="
                                                    dns.status === 'missing'
                                                "
                                            >
                                                {{
                                                    ' ' +
                                                    $t('No DNS record for')
                                                }}
                                                <span class="font-ui-mono">{{
                                                    dns.domain
                                                }}</span>
                                                {{
                                                    $t(
                                                        'yet. Add this one where you manage the domain:',
                                                    ) + ' '
                                                }}
                                            </span>
                                            <span
                                                v-else-if="
                                                    dns.status === 'proxied'
                                                "
                                            >
                                                {{
                                                    ' ' +
                                                    $t(
                                                        'It’s behind Cloudflare’s proxy, which blocks the certificate. Set the record to DNS only (grey cloud):',
                                                    ) +
                                                    ' '
                                                }}
                                            </span>
                                            <span v-else>
                                                {{
                                                    ' ' +
                                                    $t(
                                                        'It points to :value, not this machine. Change the record to:',
                                                        {
                                                            value: dns.found.join(
                                                                ', ',
                                                            ),
                                                        },
                                                    ) +
                                                    ' '
                                                }}
                                            </span>
                                        </p>
                                        <template
                                            v-if="
                                                dns.status !== 'ready' &&
                                                dns.record
                                            "
                                        >
                                            <dl
                                                class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 rounded-md border border-(--ds-border) bg-(--ds-card) px-3 py-2 font-ui-mono text-[12px]/5"
                                            >
                                                <dt
                                                    class="text-(--ds-text-faint)"
                                                >
                                                    {{ ' ' + $t('Type') + ' ' }}
                                                </dt>
                                                <dd class="col-span-2">
                                                    {{ dns.record.type }}
                                                </dd>
                                                <dt
                                                    class="text-(--ds-text-faint)"
                                                >
                                                    {{ ' ' + $t('Name') + ' ' }}
                                                </dt>
                                                <dd class="col-span-2 truncate">
                                                    {{ dns.record.name }}
                                                </dd>
                                                <dt
                                                    class="text-(--ds-text-faint)"
                                                >
                                                    {{
                                                        ' ' + $t('Value') + ' '
                                                    }}
                                                </dt>
                                                <dd class="truncate">
                                                    {{ dns.record.value }}
                                                </dd>
                                                <AppButton
                                                    size="xs"
                                                    variant="quiet"
                                                    :aria-label="
                                                        $t(
                                                            'Copy the IP address',
                                                        )
                                                    "
                                                    @click="
                                                        copy(
                                                            'dns',
                                                            dns.record.value,
                                                        )
                                                    "
                                                >
                                                    <Check
                                                        v-if="copied === 'dns'"
                                                        class="mi-pop size-3.5 text-(--ds-green)"
                                                        :stroke-width="2.25"
                                                    />
                                                    <Copy
                                                        v-else
                                                        class="mi-copy size-3.5"
                                                        :stroke-width="1.75"
                                                    />
                                                </AppButton>
                                            </dl>
                                            <div
                                                class="flex items-center justify-between gap-3"
                                            >
                                                <span
                                                    class="text-(--ds-text-muted)"
                                                >
                                                    {{
                                                        ' ' +
                                                        $t(
                                                            'You can deploy now. HTTPS starts once the record points here, which can take a few minutes.',
                                                        ) +
                                                        ' '
                                                    }}
                                                </span>
                                                <AppButton
                                                    size="xs"
                                                    variant="quiet"
                                                    :disabled="dnsChecking"
                                                    @click="checkDomain"
                                                >
                                                    {{
                                                        dnsChecking
                                                            ? $t('Checking…')
                                                            : $t('Check again')
                                                    }}
                                                </AppButton>
                                            </div>
                                        </template>
                                    </div>
                                    <label
                                        v-if="form.domain"
                                        class="mt-1 flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                                    >
                                        <TickBox
                                            v-model="form.wildcard"
                                            class="mt-0.5"
                                        />
                                        <span class="flex flex-col gap-0.5">
                                            <span class="text-(--ds-text)">{{
                                                $t('Allow wildcard subdomains')
                                            }}</span>
                                            <span
                                                class="text-xs/4 text-(--ds-text-faint)"
                                                >{{
                                                    $t(
                                                        'Every subdomain, like tenant.:domain, is served by this app and gets its own certificate the first time it’s visited. No DNS provider needed.',
                                                        {
                                                            domain:
                                                                bareDomain(
                                                                    form.domain,
                                                                ) ||
                                                                'example.com',
                                                        },
                                                    )
                                                }}</span
                                            >
                                        </span>
                                    </label>
                                    <InputError
                                        :message="errorFor('wildcard')"
                                    />
                                    <p
                                        v-if="
                                            form.wildcard &&
                                            dns?.wildcard &&
                                            dns.wildcard.status !== 'ready' &&
                                            dns.wildcard.record
                                        "
                                        class="text-xs/4 text-(--ds-text-muted)"
                                        aria-live="polite"
                                    >
                                        {{
                                            dns.wildcard.status === 'proxied'
                                                ? $t(
                                                      'Subdomains are behind Cloudflare’s proxy, which blocks their certificates. Set it to DNS only:',
                                                  )
                                                : $t(
                                                      'Subdomains need a record too:',
                                                  )
                                        }}
                                        <span
                                            class="font-ui-mono text-(--ds-text)"
                                            >{{ dns.wildcard.record.type }}
                                            {{ dns.wildcard.record.name }} →
                                            {{
                                                dns.wildcard.record.value
                                            }}</span
                                        >
                                    </p>
                                </div>

                                <section
                                    v-if="selected.env.length"
                                    class="flex flex-col gap-3"
                                    aria-labelledby="deploy-settings"
                                >
                                    <h3
                                        id="deploy-settings"
                                        class="text-xs/4 font-medium text-(--ds-text-faint)"
                                    >
                                        {{ ' ' + $t('Settings') + ' ' }}
                                    </h3>
                                    <template
                                        v-for="field in selected.env"
                                        :key="field.key"
                                    >
                                        <label
                                            v-if="field.accept"
                                            class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-(--ds-text-secondary) select-none"
                                        >
                                            <TickBox
                                                v-model="
                                                    form.env[
                                                        field.key
                                                    ] as boolean
                                                "
                                                class="mt-0.5"
                                            />
                                            <span>{{ field.label }}</span>
                                        </label>
                                        <div
                                            v-else
                                            class="flex flex-col gap-1.5"
                                        >
                                            <label
                                                :for="`deploy-env-${field.key}`"
                                                :class="labelClass"
                                            >
                                                {{ field.label }}
                                                <span
                                                    class="ml-1 font-ui-mono text-[11px] font-normal text-(--ds-text-faint)"
                                                >
                                                    {{ field.key }}
                                                </span>
                                            </label>
                                            <Input
                                                :id="`deploy-env-${field.key}`"
                                                v-model="
                                                    form.env[
                                                        field.key
                                                    ] as string
                                                "
                                                :placeholder="
                                                    field.generated
                                                        ? $t(
                                                              'Generated on deploy',
                                                          )
                                                        : undefined
                                                "
                                                autocomplete="off"
                                                spellcheck="false"
                                            />
                                            <p
                                                v-if="field.hint"
                                                class="text-xs/4 text-(--ds-text-faint)"
                                            >
                                                {{ field.hint }}
                                            </p>
                                        </div>
                                        <InputError
                                            :message="
                                                errorFor(`env.${field.key}`)
                                            "
                                        />
                                    </template>
                                </section>

                                <div
                                    v-if="selected.kind === 'app'"
                                    class="flex flex-col gap-1.5"
                                >
                                    <button
                                        v-if="!showExtraEnv"
                                        type="button"
                                        class="cursor-pointer self-start text-[13px]/5 font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                                        @click="showExtraEnv = true"
                                    >
                                        {{
                                            ' ' +
                                            $t('Add environment variables') +
                                            ' '
                                        }}
                                    </button>
                                    <template v-else>
                                        <label
                                            for="deploy-extra-env"
                                            :class="labelClass"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'More environment variables',
                                                ) +
                                                ' '
                                            }}
                                        </label>
                                        <textarea
                                            id="deploy-extra-env"
                                            v-model="form.extra_env"
                                            rows="4"
                                            spellcheck="false"
                                            placeholder="KEY=value, one per line"
                                            class="rounded-md border border-input bg-transparent px-3 py-2 font-ui-mono text-xs/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                        />
                                        <p
                                            v-if="selected?.kind === 'app'"
                                            class="text-xs/4 text-(--ds-text-faint)"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'Available while the app runs. Public ones (NEXT_PUBLIC_…, VITE_…) are also there while it builds.',
                                                ) +
                                                ' '
                                            }}
                                        </p>
                                        <InputError
                                            :message="errorFor('extra_env')"
                                        />
                                    </template>
                                </div>

                                <InputError :message="errorFor('form')" />
                            </div>

                            <div
                                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                            >
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    @click="open = false"
                                >
                                    {{ ' ' + $t('Cancel') + ' ' }}
                                </AppButton>
                                <SubmitButton
                                    :label="
                                        $t('Deploy :name', {
                                            name: String(selected.name),
                                        })
                                    "
                                    busy-label="Queuing…"
                                    :processing="submitting"
                                    :disabled="missingAcceptance"
                                />
                            </div>
                        </form>

                        <!-- Step 3: queued, with anything generated -->
                        <div v-else-if="step === 'done' && result" key="done">
                            <div class="flex flex-col gap-4 p-5">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-(--ds-green)/15 text-(--ds-green)"
                                        aria-hidden="true"
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            class="size-4.5"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.4"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        >
                                            <path
                                                class="mi-draw"
                                                pathLength="1"
                                                d="M5 12.5 10 17.5 19 7"
                                            />
                                        </svg>
                                    </span>
                                    <div class="flex flex-col">
                                        <p class="text-sm font-medium">
                                            {{
                                                ' ' +
                                                $t('Deploying :service', {
                                                    service: form.service,
                                                })
                                            }}
                                        </p>
                                        <p
                                            class="text-[13px]/5 text-(--ds-text-muted)"
                                        >
                                            {{
                                                ' ' +
                                                $t(
                                                    'Follow it in the Deployments tab. The first deploy pulls images, so give it a minute.',
                                                ) +
                                                ' '
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <p
                                    v-if="result.kept?.length"
                                    class="text-[13px]/5 text-(--ds-text-secondary)"
                                >
                                    {{
                                        ' ' +
                                        $t(
                                            'Kept :keys from the last deploy, so what’s already stored and connected keeps working.',
                                            { keys: result.kept.join(', ') },
                                        ) +
                                        ' '
                                    }}
                                </p>
                                <div
                                    v-if="result.generated.length"
                                    class="flex flex-col gap-2"
                                >
                                    <p
                                        class="text-[13px]/5 text-(--ds-text-secondary)"
                                    >
                                        {{
                                            ' ' +
                                            $t(
                                                'Save these now. ServerOS doesn’t keep them, so this is the only time you’ll see them.',
                                            ) +
                                            ' '
                                        }}
                                    </p>
                                    <ul
                                        class="overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50"
                                    >
                                        <li
                                            v-for="secret in result.generated"
                                            :key="secret.key"
                                            class="flex items-center gap-3 border-t border-(--ds-border) px-3.5 py-2.5 first:border-t-0"
                                        >
                                            <span
                                                class="flex min-w-0 grow flex-col"
                                            >
                                                <span
                                                    class="text-xs/4 text-(--ds-text-muted)"
                                                >
                                                    {{ secret.label }}
                                                </span>
                                                <span
                                                    class="truncate font-ui-mono text-[13px]/5 select-all"
                                                >
                                                    {{ secret.value }}
                                                </span>
                                            </span>
                                            <AppButton
                                                size="xs"
                                                variant="quiet"
                                                :aria-label="
                                                    $t('Copy :label', {
                                                        label: String(
                                                            secret.label,
                                                        ),
                                                    })
                                                "
                                                @click="
                                                    copy(
                                                        secret.key,
                                                        secret.value,
                                                    )
                                                "
                                            >
                                                <Check
                                                    v-if="copied === secret.key"
                                                    class="mi-pop size-3.5 text-(--ds-green)"
                                                    :stroke-width="2.25"
                                                />
                                                <Copy
                                                    v-else
                                                    class="mi-copy size-3.5"
                                                    :stroke-width="1.75"
                                                />
                                            </AppButton>
                                        </li>
                                    </ul>
                                </div>

                                <p
                                    v-if="result.url"
                                    class="text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{ ' ' + $t('It will answer at') }}
                                    <span class="font-ui-mono text-(--ds-text)">
                                        {{ result.url }}
                                    </span>
                                </p>
                                <p
                                    v-if="result.url?.startsWith('https://')"
                                    class="flex items-start gap-1.5 text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    <LockKeyhole
                                        class="mt-0.5 size-3.5 shrink-0"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t(
                                            'Caddy on the machine gets the certificate and redirects HTTP to HTTPS, as soon as the domain points here.',
                                        ) + ' '
                                    }}
                                </p>
                                <div
                                    v-if="!result.url && result.localUrl"
                                    class="flex flex-col gap-2 text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    <p>
                                        {{
                                            ' ' +
                                            $t(
                                                'With no domain it only listens on the machine itself, at',
                                            )
                                        }}
                                        <span
                                            class="font-ui-mono text-(--ds-text)"
                                            >{{ result.localUrl }}</span
                                        >{{
                                            $t(
                                                '. Add a domain to open it from anywhere, or reach it from your computer through an SSH tunnel, then open that address:',
                                            ) + ' '
                                        }}
                                    </p>
                                    <div
                                        v-if="result.tunnel"
                                        class="flex items-center gap-2 rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 py-1.5 pr-1.5 pl-3"
                                    >
                                        <code
                                            class="min-w-0 grow truncate font-ui-mono text-xs/5 text-(--ds-text) select-all"
                                            >{{ result.tunnel }}</code
                                        >
                                        <AppButton
                                            size="xs"
                                            variant="quiet"
                                            :aria-label="
                                                $t(
                                                    'Copy the SSH tunnel command',
                                                )
                                            "
                                            @click="
                                                copy('tunnel', result.tunnel)
                                            "
                                        >
                                            <Check
                                                v-if="copied === 'tunnel'"
                                                class="mi-pop size-3.5 text-(--ds-green)"
                                                :stroke-width="2.25"
                                            />
                                            <Copy
                                                v-else
                                                class="mi-copy size-3.5"
                                                :stroke-width="1.75"
                                            />
                                        </AppButton>
                                    </div>
                                </div>
                                <p
                                    v-if="result.notes"
                                    class="text-[13px]/5 text-(--ds-text-muted)"
                                >
                                    {{ result.notes }}
                                </p>
                            </div>

                            <div
                                class="flex justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                            >
                                <AppButton
                                    size="sm"
                                    variant="quiet"
                                    @click="back"
                                >
                                    {{ ' ' + $t('Deploy another') + ' ' }}
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="primary"
                                    @click="open = false"
                                >
                                    {{ ' ' + $t('Done') + ' ' }}
                                </AppButton>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
