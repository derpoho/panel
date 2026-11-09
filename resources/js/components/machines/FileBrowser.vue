<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle } from '@lucide/vue';
import {
    SolarDownload as Download,
    SolarFolder as Folder,
    SolarLock as Lock,
    SolarShieldCheck as ShieldCheck,
    SolarFile as FileIcon,
    SolarFileSymlink as FileSymlink,
    SolarUpload as Upload,
    SolarRefreshCw as RefreshCw,
    SolarTrash2 as Trash2,
} from '@/lib/solarIcons';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { toast } from '@/lib/toast';
import InputError from '@/components/InputError.vue';
import { bytes } from '@/components/machines/serviceFormat';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import { edit as securityEdit } from '@/routes/security';

export type FilePlace = { label: string; path: string };

type Entry = {
    name: string;
    kind: 'file' | 'directory' | 'symlink' | 'other';
    size: number;
    mode: number;
    modified_ts: number;
    symlink_target?: string;
};

type OpenFile = {
    path: string;
    bytes: Uint8Array;
    /** The text as it was read, or null when it isn't text. */
    original: string | null;
};

const props = defineProps<{
    machineId: number;
    places: FilePlace[];
    maxWrite: number;
    maxRead: number;
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    /** Online, with commands turned on, and allowed to manage machines. */
    available: boolean;
    /** What to say when there's nowhere to start. */
    emptyHint: string;
}>();

/* Talking to the panel: each operation is a daemon job the page waits on */

class FileJobError extends Error {
    constructor(
        message: string,
        public code?: string,
    ) {
        super(message);
    }
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function call(
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
): Promise<{ ok: boolean; status: number; body: Record<string, unknown> }> {
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        ...(method === 'POST' ? { body: JSON.stringify(body ?? {}) } : {}),
    });

    return {
        ok: response.ok,
        status: response.status,
        body: await response.json().catch(() => ({})),
    };
}

const FINISHED = ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];

async function job(
    op: 'list' | 'read' | 'write' | 'delete',
    path: string,
    content?: string,
): Promise<Record<string, unknown>> {
    const base = `/machines/${props.machineId}/files`;
    const sent = await call('POST', base, { op, path, content });

    if (!sent.ok) {
        const errors = sent.body.errors as Record<string, string[]> | undefined;

        throw new FileJobError(
            (errors && Object.values(errors)[0]?.[0]) ??
                (sent.body.error as string | undefined) ??
                trans('That didn’t work.'),
            sent.body.code as string | undefined,
        );
    }

    const id = sent.body.jobId as string;
    const started = Date.now();

    // Most answers take about a second, so check often at first; a
    // status check is cheap. Slow down for the long ones.
    while (Date.now() - started < 75_000) {
        const waited = Date.now() - started;
        await new Promise((resolve) =>
            setTimeout(
                resolve,
                waited < 4000 ? 150 : waited < 15_000 ? 500 : 1000,
            ),
        );
        const status = await call('GET', `${base}/${id}`);
        const state = status.body.state as string | undefined;

        if (!status.ok || !state) {
            throw new FileJobError(trans('Couldn’t check on it. Try again.'));
        }
        if (state === 'succeeded') {
            return (status.body.result as Record<string, unknown>) ?? {};
        }
        if (FINISHED.includes(state)) {
            throw new FileJobError(
                (status.body.error as { message?: string } | null)?.message ??
                    trans('The machine couldn’t do that.'),
            );
        }
    }

    throw new FileJobError(
        trans(
            'The machine didn’t answer in time. Check it’s online and try again.',
        ),
    );
}

/* base64 both ways, in chunks so big files don't blow the stack */

function toBase64(data: Uint8Array): string {
    let binary = '';
    for (let i = 0; i < data.length; i += 0x8000) {
        binary += String.fromCharCode(...data.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

function fromBase64(text: string): Uint8Array {
    const binary = atob(text);
    const out = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        out[i] = binary.charCodeAt(i);
    }

    return out;
}

/** The file as text, or null for anything that isn't UTF-8 text. */
function asText(data: Uint8Array): string | null {
    if (data.subarray(0, 8000).includes(0)) {
        return null;
    }
    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(data);
    } catch {
        return null;
    }
}

function join(dir: string, name: string): string {
    return dir === '/' ? `/${name}` : `${dir}/${name}`;
}

function parent(path: string): string {
    const cut = path.replace(/\/+$/, '').lastIndexOf('/');

    return cut <= 0 ? '/' : path.slice(0, cut);
}

function baseName(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

/* Unlock: opening files and changing them needs two-factor, like the console */

const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(clock));

const unlockedMs = computed(() =>
    props.consoleLock.unlockedUntil
        ? Date.parse(props.consoleLock.unlockedUntil) - now.value
        : 0,
);
const unlocked = computed(
    () => props.consoleLock.twoFactorEnabled && unlockedMs.value > 0,
);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(unlockedMs.value / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

/** What to do once unlocked: the action that asked for it. */
const pending = ref<(() => void) | null>(null);
const unlockCode = ref('');
const unlockError = ref<string | null>(null);
const unlocking = ref(false);

function needsUnlock(then: () => void): boolean {
    if (unlocked.value) {
        return false;
    }
    pending.value = then;
    unlockCode.value = '';
    unlockError.value = null;

    return true;
}

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) {
        return;
    }
    unlocking.value = true;
    unlockError.value = null;

    try {
        const response = await call('POST', '/console/unlock', {
            code: unlockCode.value,
        });
        if (!response.ok) {
            const errors = response.body.errors as
                | Record<string, string[]>
                | undefined;
            unlockError.value =
                errors?.code?.[0] ??
                (response.body.error as string | undefined) ??
                trans('Couldn’t unlock.');
            unlockCode.value = '';

            return;
        }
        router.reload({
            only: ['consoleLock'],
            onFinish: () => {
                const then = pending.value;
                pending.value = null;
                then?.();
            },
        });
    } finally {
        unlocking.value = false;
    }
}

async function lockNow(): Promise<void> {
    await call('POST', '/console/lock');
    router.reload({ only: ['consoleLock'] });
}

/** A job refused as locked: the unlock window ran out under us. */
function handle(error: unknown, retry: () => void): string | null {
    if (error instanceof FileJobError && error.code === 'console_locked') {
        router.reload({ only: ['consoleLock'] });
        pending.value = retry;

        return null;
    }

    return error instanceof Error ? error.message : trans('That didn’t work.');
}

/** The folder on screen. It changes once the next one has arrived. */
const dir = ref<string | null>(props.places[0]?.path ?? null);
const entries = ref<Entry[]>([]);
const listError = ref<string | null>(null);
const goTo = ref(dir.value ?? '');
/** A folder on its way that hasn't been seen before. */
const opening = ref<string | null>(null);
/** The folder on screen, being checked again. */
const refreshing = ref(false);
/** Folders seen this visit, shown at once when gone back to. */
const seen = new Map<string, Entry[]>();
/** The latest folder asked for; older answers are ignored. */
let wanted: string | null = null;

function showFolder(
    path: string,
    found: Entry[],
    error: string | null = null,
): void {
    dir.value = path;
    goTo.value = path;
    entries.value = found;
    listError.value = error;
}

async function list(path: string): Promise<void> {
    wanted = path;
    const cached = seen.get(path);

    if (cached) {
        showFolder(path, cached);
        opening.value = null;
        refreshing.value = true;
    } else {
        opening.value = path;
    }

    try {
        const result = await job('list', path);
        const found = (result.entries as Entry[]) ?? [];
        seen.set(path, found);
        if (wanted === path) {
            showFolder(path, found);
        }
    } catch (error) {
        seen.delete(path);
        if (wanted === path) {
            const message = handle(error, () => list(path));
            if (message) {
                showFolder(path, [], message);
            }
        }
    } finally {
        if (wanted === path) {
            opening.value = null;
            refreshing.value = false;
        }
    }
}

function go(): void {
    const path = goTo.value.trim();
    if (path.startsWith('/')) {
        closeFile();
        void list(path.length > 1 ? path.replace(/\/+$/, '') : path);
    }
}

const crumbs = computed(() => {
    if (!dir.value) {
        return [];
    }
    const parts = dir.value.split('/').filter(Boolean);

    return parts.map((name, i) => ({
        name,
        path: `/${parts.slice(0, i + 1).join('/')}`,
    }));
});

const place = computed(() =>
    props.places.find(
        (p) => dir.value === p.path || dir.value?.startsWith(`${p.path}/`),
    ),
);

function choose(entry: Entry): void {
    if (!dir.value) {
        return;
    }
    const path = join(dir.value, entry.name);
    if (entry.kind === 'directory') {
        void list(path);
    } else if (entry.kind === 'symlink') {
        // Could be a folder or a file; try it as a folder first.
        void openLink(path, entry);
    } else if (entry.kind === 'file') {
        void openFile(path, entry.size);
    }
}

async function openLink(path: string, entry: Entry): Promise<void> {
    try {
        await job('list', path);
        void list(path);
    } catch {
        void openFile(path, entry.size);
    }
}

onMounted(() => {
    if (props.available && dir.value) {
        void list(dir.value);
    }
});

/* One file open: view it, edit it as text, save, download */

const file = ref<OpenFile | null>(null);
const text = ref('');
const reading = ref<string | null>(null);
const fileError = ref<string | null>(null);
const saving = ref(false);
const editor = useTemplateRef<HTMLTextAreaElement>('editor');

const changed = computed(
    () => file.value?.original !== null && text.value !== file.value?.original,
);
const savedSize = computed(() => new TextEncoder().encode(text.value).length);

async function openFile(path: string, size: number): Promise<void> {
    if (size > props.maxRead) {
        toast.error(
            trans(
                ':baseName is :bytesValue. Files over :bytes2 can’t be opened from the panel yet.',
                {
                    baseName: String(baseName(path)),
                    bytesValue: String(bytes(size)),
                    bytes2: String(bytes(props.maxRead)),
                },
            ),
        );

        return;
    }
    if (needsUnlock(() => openFile(path, size))) {
        return;
    }

    reading.value = path;
    fileError.value = null;

    try {
        const result = await job('read', path);
        const data = fromBase64((result.content_b64 as string) ?? '');
        const original = asText(data);
        file.value = { path, bytes: data, original };
        text.value = original ?? '';
    } catch (error) {
        const message = handle(error, () => openFile(path, size));
        if (message) {
            toast.error(message);
        }
    } finally {
        reading.value = null;
    }
}

function closeFile(): void {
    file.value = null;
    text.value = '';
    fileError.value = null;
}

async function save(): Promise<void> {
    const open = file.value;
    if (!open || !changed.value || saving.value) {
        return;
    }
    if (savedSize.value > props.maxWrite) {
        fileError.value = trans(
            'Files over :bytes can’t be saved from the panel yet.',
            { bytes: String(bytes(props.maxWrite)) },
        );

        return;
    }
    if (needsUnlock(() => save())) {
        return;
    }

    saving.value = true;
    fileError.value = null;
    const data = new TextEncoder().encode(text.value);

    try {
        const result = await job('write', open.path, toBase64(data));
        file.value = { path: open.path, bytes: data, original: text.value };
        toast.success(
            result.backup
                ? trans(
                      'Saved. The old version is kept beside it as :baseName.',
                      { baseName: String(baseName(result.backup as string)) },
                  )
                : trans('Saved.'),
        );
        if (dir.value) {
            void list(dir.value);
        }
    } catch (error) {
        fileError.value = handle(error, () => save());
    } finally {
        saving.value = false;
    }
}

function saveKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key === 's') {
        event.preventDefault();
        void save();
    }
}

function downloadBytes(name: string, data: Uint8Array): void {
    const url = URL.createObjectURL(new Blob([data as BlobPart]));
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function downloadOpen(): void {
    if (file.value) {
        const data = changed.value
            ? new TextEncoder().encode(text.value)
            : file.value.bytes;
        downloadBytes(baseName(file.value.path), data);
    }
}

async function download(entry: Entry): Promise<void> {
    if (!dir.value) {
        return;
    }
    const path = join(dir.value, entry.name);
    if (entry.size > props.maxRead) {
        toast.error(
            trans(
                ':name is :bytesValue. Files over :bytes2 can’t be downloaded from the panel yet.',
                {
                    name: String(entry.name),
                    bytesValue: String(bytes(entry.size)),
                    bytes2: String(bytes(props.maxRead)),
                },
            ),
        );

        return;
    }
    if (needsUnlock(() => download(entry))) {
        return;
    }

    reading.value = path;
    try {
        const result = await job('read', path);
        downloadBytes(
            entry.name,
            fromBase64((result.content_b64 as string) ?? ''),
        );
    } catch (error) {
        const message = handle(error, () => download(entry));
        if (message) {
            toast.error(message);
        }
    } finally {
        reading.value = null;
    }
}

/* Upload into the open folder, asking before replacing a file */

const picker = useTemplateRef<HTMLInputElement>('picker');
const uploading = ref(false);

function pick(): void {
    if (!needsUnlock(() => pick())) {
        picker.value?.click();
    }
}

async function picked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];
    input.value = '';
    if (!chosen || !dir.value) {
        return;
    }
    if (chosen.size > props.maxWrite) {
        toast.error(
            trans(
                ':name is :bytesValue. Files over :bytes2 can’t be uploaded from the panel yet.',
                {
                    name: String(chosen.name),
                    bytesValue: String(bytes(chosen.size)),
                    bytes2: String(bytes(props.maxWrite)),
                },
            ),
        );

        return;
    }

    const target = join(dir.value, chosen.name);
    const data = new Uint8Array(await chosen.arrayBuffer());
    const send = () => upload(target, data);

    if (entries.value.some((e) => e.name === chosen.name)) {
        confirming.value = {
            title: trans('Replace :name?', { name: String(chosen.name) }),
            body: trans(
                'There’s already a :name here. The old one is kept beside it as a backup.',
                { name: String(chosen.name) },
            ),
            label: trans('Replace'),
            busyLabel: trans('Replacing…'),
            danger: false,
            run: send,
        };
    } else {
        await send();
    }
}

async function upload(path: string, data: Uint8Array): Promise<void> {
    uploading.value = true;
    try {
        await job('write', path, toBase64(data));
        toast.success(
            trans('Uploaded :baseName.', { baseName: String(baseName(path)) }),
        );
        if (dir.value) {
            void list(dir.value);
        }
    } catch (error) {
        const message = handle(error, () => upload(path, data));
        if (message) {
            toast.error(message);
        }
    } finally {
        uploading.value = false;
    }
}

type Confirm = {
    title: string;
    body: string;
    label: string;
    busyLabel: string;
    danger: boolean;
    run: () => Promise<void>;
};

const confirming = ref<Confirm | null>(null);
const confirmBusy = ref(false);
const confirmOpen = computed({
    get: () => confirming.value !== null,
    set: (open: boolean) => {
        if (!open && !confirmBusy.value) {
            confirming.value = null;
        }
    },
});

async function confirmRun(): Promise<void> {
    if (!confirming.value) {
        return;
    }
    confirmBusy.value = true;
    try {
        await confirming.value.run();
    } finally {
        confirmBusy.value = false;
        confirming.value = null;
    }
}

function askDelete(entry: Entry): void {
    if (!dir.value || needsUnlock(() => askDelete(entry))) {
        return;
    }
    const path = join(dir.value, entry.name);
    const folder = entry.kind === 'directory';

    confirming.value = {
        title: trans('Delete :name?', { name: String(entry.name) }),
        body: folder
            ? trans(
                  'Only an empty folder can be deleted. This can’t be undone.',
              )
            : trans(
                  'It’s deleted from the machine straight away, with no backup. This can’t be undone.',
              ),
        label: trans('Delete'),
        busyLabel: trans('Deleting…'),
        danger: true,
        run: async () => {
            try {
                await job('delete', path);
                toast.success(
                    trans('Deleted :name.', { name: String(entry.name) }),
                );
                if (file.value?.path === path) {
                    closeFile();
                }
                if (dir.value) {
                    void list(dir.value);
                }
            } catch (error) {
                const message = handle(error, () => askDelete(entry));
                if (message) {
                    toast.error(message);
                }
            }
        },
    };
}

watch(
    () => props.available,
    (now) => {
        if (now && dir.value && entries.value.length === 0) {
            void list(dir.value);
        }
    },
);

// Made once, not per file (toLocaleString() builds one every call).
const modifiedFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function modified(ts: number): string {
    return ts ? modifiedFormat.format(new Date(ts * 1000)) : '';
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Nowhere ServerOS may look -->
        <div
            v-if="places.length === 0 && !dir"
            class="ds-tile flex flex-col items-center gap-2 px-6 py-14 text-center"
        >
            <h2 class="text-base font-semibold">
                {{ $t('No files to show yet') }}
            </h2>
            <p class="max-w-md text-[13px]/5 text-(--ds-text-muted)">
                {{ emptyHint }}
            </p>
        </div>

        <template v-else>
            <!-- Unlock, when something needs it -->
            <div
                v-if="pending"
                class="ds-tile flex flex-col items-center gap-4 px-6 py-10 text-center"
            >
                <span
                    class="ds-icon-chip size-12! rounded-xl!"
                    aria-hidden="true"
                >
                    <ShieldCheck class="size-5" :stroke-width="2" />
                </span>
                <template v-if="!consoleLock.twoFactorEnabled">
                    <div class="flex max-w-md flex-col gap-1.5">
                        <h2 class="text-base font-semibold">
                            {{
                                ' ' +
                                $t('Files need two-factor authentication') +
                                ' '
                            }}
                        </h2>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'Files can hold passwords and keys, and changing them changes the machine, so a password alone isn’t enough. Turn on two-factor for your account, then come back.',
                                ) +
                                ' '
                            }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <AppButton size="sm" @click="pending = null">
                            {{ ' ' + $t('Back') + ' ' }}
                        </AppButton>
                        <AppButton
                            variant="primary"
                            size="sm"
                            :href="securityEdit()"
                        >
                            {{ ' ' + $t('Set up two-factor') + ' ' }}
                        </AppButton>
                    </div>
                </template>
                <form
                    v-else
                    class="flex flex-col items-center gap-4"
                    @submit.prevent="unlock"
                >
                    <div class="flex max-w-md flex-col gap-1.5">
                        <h2 class="text-base font-semibold">
                            {{
                                ' ' +
                                $t('Unlock to open and change files') +
                                ' '
                            }}
                        </h2>
                        <p class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                ' ' +
                                $t(
                                    'Enter the 6-digit code from your authenticator app. Files and the console stay unlocked for :minutes minutes on this device.',
                                    {
                                        minutes: String(
                                            consoleLock.minutes ?? '',
                                        ),
                                    },
                                ) +
                                ' '
                            }}
                        </p>
                    </div>
                    <InputOTP
                        v-model="unlockCode"
                        :maxlength="6"
                        :disabled="unlocking"
                        autofocus
                        @complete="unlock"
                    >
                        <InputOTPGroup>
                            <InputOTPSlot
                                v-for="index in 6"
                                :key="index"
                                :index="index - 1"
                            />
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError :message="unlockError ?? undefined" />
                    <div class="flex gap-2">
                        <AppButton @click="pending = null">{{
                            $t('Cancel')
                        }}</AppButton>
                        <SubmitButton
                            :label="$t('Unlock')"
                            busy-label="Checking…"
                            :processing="unlocking"
                            :disabled="unlockCode.length !== 6"
                        />
                    </div>
                </form>
            </div>

            <template v-else>
                <!-- Unlocked: how long for -->
                <div
                    v-if="unlocked"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 px-3.5 py-2"
                >
                    <ShieldCheck
                        class="size-4 shrink-0 text-(--ds-green)"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <p class="text-[13px]/5">
                        {{ ' ' + $t('Unlocked with two-factor') }}
                        <span class="text-(--ds-text-muted)"
                            >{{ $t('· locks in') }}
                            <span class="font-ui-mono tabular-nums">{{
                                countdown
                            }}</span></span
                        >
                    </p>
                    <AppButton
                        size="xs"
                        variant="quiet"
                        class="ml-auto"
                        @click="lockNow"
                    >
                        <Lock class="size-3.5" :stroke-width="1.75" />
                        {{ $t('Lock now') + ' ' }}
                    </AppButton>
                </div>

                <!-- Where to look -->
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-if="places.length > 1"
                        class="ds-segments max-w-full overflow-x-auto"
                        role="group"
                        :aria-label="$t('Folders')"
                    >
                        <button
                            v-for="option in places"
                            :key="option.path"
                            type="button"
                            class="cursor-pointer"
                            :aria-pressed="place?.path === option.path"
                            :title="option.path"
                            @click="
                                closeFile();
                                list(option.path);
                            "
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <form
                        class="flex min-w-60 grow items-center gap-2"
                        @submit.prevent="go"
                    >
                        <label class="sr-only" for="file-path">{{
                            $t('Path')
                        }}</label>
                        <input
                            id="file-path"
                            v-model="goTo"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('/path/to/folder')"
                            class="h-8 min-w-0 grow rounded-md border border-input bg-transparent px-2.5 font-ui-mono text-xs text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus:border-ring focus:ring-[3px] focus:ring-ring/50 dark:bg-input/30"
                        />
                        <AppButton
                            type="submit"
                            size="sm"
                            :disabled="!available"
                            >{{ $t('Go') }}</AppButton
                        >
                    </form>
                </div>

                <p
                    v-if="!available"
                    class="ds-tile p-5 text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'The machine needs to be online, and you need permission to manage machines, to browse its files.',
                        ) +
                        ' '
                    }}
                </p>

                <div v-else-if="file" class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton size="sm" variant="quiet" @click="closeFile">
                            <ArrowLeft class="size-3.5" :stroke-width="1.75" />
                            {{ $t('Back') + ' ' }}
                        </AppButton>
                        <span
                            class="min-w-0 grow truncate font-ui-mono text-xs text-(--ds-text-secondary)"
                            :title="file.path"
                            >{{ file.path }}</span
                        >
                        <span class="text-xs text-(--ds-text-muted)">{{
                            bytes(
                                file.original === null
                                    ? file.bytes.length
                                    : savedSize,
                            )
                        }}</span>
                        <AppButton size="sm" @click="downloadOpen">
                            <Download class="size-3.5" :stroke-width="1.75" />
                            {{ $t('Download') + ' ' }}
                        </AppButton>
                        <AppButton
                            v-if="file.original !== null"
                            variant="primary"
                            size="sm"
                            :disabled="!changed || saving"
                            @click="save"
                        >
                            <LoaderCircle
                                v-if="saving"
                                class="size-3.5 animate-spin"
                                :stroke-width="2"
                            />
                            {{ saving ? $t('Saving…') : $t('Save') }}
                        </AppButton>
                    </div>
                    <textarea
                        v-if="file.original !== null"
                        ref="editor"
                        v-model="text"
                        spellcheck="false"
                        autocomplete="off"
                        wrap="off"
                        aria-label="File contents"
                        class="ds-tile h-[60vh] w-full resize-y bg-(--ds-code)! px-4 py-3 font-ui-mono text-xs/5 text-(--ds-text) outline-none focus:border-ring"
                        @keydown="saveKey"
                    />
                    <p
                        v-else
                        class="ds-tile p-5 text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'This isn’t a text file, so it can’t be shown here. Download it to open it on your computer.',
                            ) +
                            ' '
                        }}
                    </p>
                    <InputError :message="fileError ?? undefined" />
                    <p
                        v-if="file.original !== null"
                        class="text-xs text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Saving replaces the file on the machine and keeps the old version beside it. Services read most settings when they start, so restart the service after changing them.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>

                <div v-else class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <nav
                            :aria-label="$t('Folder')"
                            class="flex min-w-0 grow flex-wrap items-center font-ui-mono text-xs"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded px-1 py-0.5 text-(--ds-text-muted) hover:text-(--ds-text)"
                                :class="focusRing"
                                @click="list('/')"
                            >
                                /
                            </button>
                            <template
                                v-for="(crumb, i) in crumbs"
                                :key="crumb.path"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-1 py-0.5 hover:text-(--ds-text)"
                                    :class="[
                                        focusRing,
                                        i === crumbs.length - 1
                                            ? 'text-(--ds-text)'
                                            : 'text-(--ds-text-muted)',
                                    ]"
                                    @click="list(crumb.path)"
                                >
                                    {{ crumb.name }}
                                </button>
                                <span
                                    v-if="i < crumbs.length - 1"
                                    class="text-(--ds-text-faint)"
                                    >/</span
                                >
                            </template>
                        </nav>
                        <AppButton
                            size="sm"
                            variant="quiet"
                            :disabled="!dir || refreshing || opening !== null"
                            :aria-label="$t('Refresh')"
                            :title="$t('Refresh')"
                            @click="dir && list(dir)"
                        >
                            <RefreshCw
                                class="size-3.5"
                                :class="
                                    (refreshing || opening) && 'animate-spin'
                                "
                                :stroke-width="1.75"
                            />
                        </AppButton>
                        <AppButton
                            size="sm"
                            :disabled="!dir || uploading"
                            @click="pick"
                        >
                            <LoaderCircle
                                v-if="uploading"
                                class="size-3.5 animate-spin"
                                :stroke-width="2"
                            />
                            <Upload
                                v-else
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{ $t('Upload') + ' ' }}
                        </AppButton>
                        <input
                            ref="picker"
                            type="file"
                            class="hidden"
                            @change="picked"
                        />
                    </div>

                    <div
                        class="ds-tile divide-y-2 divide-black/5 transition-opacity duration-150 dark:divide-black/30"
                        :class="opening && 'opacity-60'"
                        :aria-busy="opening !== null || refreshing || undefined"
                    >
                        <div
                            v-if="dir && dir !== '/'"
                            class="flex items-center px-4 py-2"
                        >
                            <button
                                type="button"
                                class="flex cursor-pointer items-center gap-2 rounded text-sm text-(--ds-text-muted) hover:text-(--ds-text)"
                                :class="focusRing"
                                @click="list(parent(dir))"
                            >
                                <LoaderCircle
                                    v-if="opening === parent(dir)"
                                    class="size-4 animate-spin"
                                    :stroke-width="2"
                                />
                                <Folder
                                    v-else
                                    class="size-4"
                                    :stroke-width="1.75"
                                />
                                ..
                            </button>
                        </div>
                        <p
                            v-if="listError"
                            class="px-4 py-5 text-[13px]/5 text-(--ds-red)"
                        >
                            {{ listError }}
                        </p>
                        <p
                            v-else-if="opening && entries.length === 0"
                            class="flex items-center gap-2 px-4 py-5 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            <LoaderCircle
                                class="size-4 animate-spin"
                                :stroke-width="2"
                            />
                            {{ $t('Asking the machine…') + ' ' }}
                        </p>
                        <p
                            v-else-if="entries.length === 0"
                            class="px-4 py-5 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ ' ' + $t('This folder is empty.') + ' ' }}
                        </p>
                        <template v-else>
                            <div
                                v-for="entry in entries"
                                :key="entry.name"
                                class="group flex items-center gap-3 px-4 py-2 text-sm"
                            >
                                <button
                                    type="button"
                                    class="flex min-w-0 grow cursor-pointer items-center gap-2 rounded text-left"
                                    :class="[
                                        focusRing,
                                        entry.kind === 'other' &&
                                            'cursor-default text-(--ds-text-muted)',
                                    ]"
                                    :disabled="entry.kind === 'other'"
                                    @click="choose(entry)"
                                >
                                    <LoaderCircle
                                        v-if="
                                            dir &&
                                            [reading, opening].includes(
                                                join(dir, entry.name),
                                            )
                                        "
                                        class="size-4 shrink-0 animate-spin text-(--ds-text-muted)"
                                        :stroke-width="2"
                                    />
                                    <Folder
                                        v-else-if="entry.kind === 'directory'"
                                        class="size-4 shrink-0 text-(--ds-blue)"
                                        :stroke-width="1.75"
                                    />
                                    <FileSymlink
                                        v-else-if="entry.kind === 'symlink'"
                                        class="size-4 shrink-0 text-(--ds-text-muted)"
                                        :stroke-width="1.75"
                                    />
                                    <FileIcon
                                        v-else
                                        class="size-4 shrink-0 text-(--ds-text-muted)"
                                        :stroke-width="1.75"
                                    />
                                    <span
                                        class="truncate font-ui-mono text-[13px]"
                                        >{{ entry.name }}</span
                                    >
                                    <span
                                        v-if="entry.symlink_target"
                                        class="truncate text-xs text-(--ds-text-faint)"
                                        >→ {{ entry.symlink_target }}</span
                                    >
                                </button>
                                <span
                                    class="hidden shrink-0 text-xs text-(--ds-text-muted) sm:inline"
                                    >{{ modified(entry.modified_ts) }}</span
                                >
                                <span
                                    class="w-16 shrink-0 text-right text-xs text-(--ds-text-muted) tabular-nums"
                                    >{{
                                        entry.kind === 'directory'
                                            ? ''
                                            : bytes(entry.size)
                                    }}</span
                                >
                                <div
                                    class="flex shrink-0 gap-0.5 sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
                                >
                                    <AppButton
                                        v-if="entry.kind === 'file'"
                                        size="xs"
                                        variant="quiet"
                                        class="px-1.5!"
                                        :aria-label="
                                            $t('Download :name', {
                                                name: String(entry.name),
                                            })
                                        "
                                        :title="$t('Download')"
                                        @click="download(entry)"
                                    >
                                        <Download
                                            class="size-3.5"
                                            :stroke-width="1.75"
                                        />
                                    </AppButton>
                                    <AppButton
                                        v-if="entry.kind !== 'other'"
                                        size="xs"
                                        variant="danger"
                                        class="px-1.5!"
                                        :aria-label="
                                            $t('Delete :name', {
                                                name: String(entry.name),
                                            })
                                        "
                                        :title="$t('Delete')"
                                        @click="askDelete(entry)"
                                    >
                                        <Trash2
                                            class="size-3.5"
                                            :stroke-width="1.75"
                                        />
                                    </AppButton>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p class="text-xs text-(--ds-text-muted)">
                        {{
                            ' ' +
                            $t(
                                'ServerOS can only reach the folders of services it runs or has adopted. Opening, saving and deleting files need two-factor; saves and deletes go in the activity log.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>
            </template>
        </template>

        <Dialog v-model:open="confirmOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{ confirming?.title }}</DialogTitle>
                    <DialogDescription>{{
                        confirming?.body
                    }}</DialogDescription>
                </DialogHeader>
                <form
                    class="flex justify-end gap-2"
                    @submit.prevent="confirmRun"
                >
                    <AppButton
                        :disabled="confirmBusy"
                        @click="confirming = null"
                        >{{ $t('Cancel') }}</AppButton
                    >
                    <DangerButton
                        v-if="confirming?.danger ?? true"
                        :label="confirming?.label ?? $t('Delete')"
                        :busy-label="confirming?.busyLabel"
                        :processing="confirmBusy"
                    />
                    <SubmitButton
                        v-else
                        :label="confirming?.label ?? ''"
                        :busy-label="confirming?.busyLabel"
                        :processing="confirmBusy"
                    />
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
